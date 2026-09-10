<?php

namespace App\Services\Transport;

use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\User;
use App\Repositories\Transport\TransportTripRepository;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\TransportDocumentNumber;
use App\Support\Transport\TripStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Order → Trip conversion (SNG-TRN-007).
 *
 * Ticket: "As dispatch, I can convert an approved order into a trip."
 * FRS TRP-P0-001: trigger "Confirmed customer order", output "Trip draft".
 *
 * ── WHY CREATION HAPPENS AT APPROVAL, NOT AT DISPATCH ─────────────────────
 * Two reference documents say otherwise — STOS-OPS §37 ("When dispatch is
 * confirmed, Sangoe creates/activates Trip") and STOS-LSM §6.9 ("The system
 * creates the Trip"). They are outranked: the authority register puts Steps 9-12
 * above the STOS-* suite, and all four higher documents place the Trip earlier —
 * Step 12 says "convert an approved order", Step 3 outputs a "Trip draft", and
 * Step 9's machine runs draft → viability_pending → approved → allocated →
 * pretrip_ok → DISPATCHED, which requires the trip to exist long before dispatch
 * in order to be viability-checked at all. Ruled and confirmed in the scope
 * agreement; recorded here because the contradiction is real and a future reader
 * will meet it.
 *
 * Rules enforced:
 *   BR-P0-001    trip number unique within tenant/YEAR, reject duplicate
 *   TRP-P0-001   no duplicate ACTIVE trip against the same shipment
 *   CTR-004      cannot create an orphan trip
 *   STT-001      draft → viability_pending, the only transition this ticket owns
 */
class TransportTripService
{
    public function __construct(
        private TransportTripRepository $trips,
        private TransportOrderService $orders,
    ) {
    }

    /** @param array<string,mixed> $filters */
    public function list(int $tenantId, array $filters = []): LengthAwarePaginator
    {
        return $this->trips->filtered($tenantId, $filters);
    }

    public function statusCounts(int $tenantId): array
    {
        return $this->trips->statusCounts($tenantId);
    }

    public function find(int $id, int $tenantId): TransportTrip
    {
        $trip = $this->trips->findForTenant($id, $tenantId);

        if (! $trip) {
            throw new ResourceNotFoundException('Trip');
        }

        return $trip;
    }

    /**
     * Create a Trip from an approved Order.
     *
     * @param  array{approved_freight?:numeric,currency?:string,route?:string}  $data
     */
    public function createFromOrder(int $orderId, array $data, int $tenantId, ?User $actor = null): TransportTrip
    {
        // Tenant-scoped lookup. A cross-tenant order id never resolves, so the
        // orphan/eligibility checks below can never run against someone else's
        // record — this is also what makes CTR-004's "belongs to tenant" real.
        $order = $this->orders->find($orderId, $tenantId);

        if (! OrderStatus::isTripEligible($order->order_status)) {
            throw new BusinessException(
                'Only an approved order can become a trip. This order is '.$order->statusLabel().'.',
                422
            );
        }

        // TRP-P0-001 — "no duplicate active trip against same shipment".
        if ($this->trips->hasOpenTripForOrder($tenantId, $order->id)) {
            throw new BusinessException(
                'This order already has an active trip. Close or cancel it before creating another.',
                422
            );
        }

        return DB::transaction(function () use ($order, $data, $tenantId, $actor) {
            /** @var TransportTrip $trip */
            $trip = TransportTrip::create([
                'tenant_id'        => $tenantId,
                'order_id'         => $order->id,
                // BR-P0-001 — unique within tenant/year. The engine's 'yearly'
                // reset rule delivers that when enabled; the local allocator
                // scopes its LIKE to this year's prefix for the same effect.
                // The unique index is what actually guarantees it either way.
                'trip_number'      => TransportDocumentNumber::allocate(
                    'transport_trip',
                    $tenantId,
                    fn () => TransportTrip::nextLocalNumber($tenantId),
                ),
                'status'           => TripStatus::INITIAL,
                'approved_freight' => $data['approved_freight'] ?? null,
                'currency'         => $data['currency'] ?? 'INR',
                // OPS §37 — a trip links its customer and route. Carried from the
                // order so a trip is readable on its own.
                'customer_id'      => $order->customer_id,
                'route'            => $data['route'] ?? $order->route,
                'created_by'       => $actor?->id,
                'updated_by'       => $actor?->id,
                // vehicle_id / driver_id are NOT set here. Allocation is
                // SNG-TRN-009 and has eligibility rules this ticket does not own.
            ]);

            $trip->audit('transport.trip.created', $actor, new: [
                'trip_number'      => $trip->trip_number,
                'order_id'         => $order->id,
                'order_number'     => $order->order_number,
                'customer_id'      => $trip->customer_id,
                'status'           => $trip->status,
                'approved_freight' => $trip->approved_freight,
                'currency'         => $trip->currency,
            ]);

            // The order is a party to this too — someone reading the ORDER's
            // history must see that it became a trip, without having to know
            // the trip exists in order to look for it.
            $order->audit('transport.order.trip_created', $actor, new: [
                'trip_id'     => $trip->id,
                'trip_number' => $trip->trip_number,
            ]);

            Log::channel('transport')->info('Trip created from order', [
                'trip_id' => $trip->id, 'trip_number' => $trip->trip_number,
                'order_id' => $order->id, 'tenant_id' => $tenantId, 'user_id' => $actor?->id,
            ]);

            return $trip;
        });
    }

    /**
     * STT-001 — draft → viability_pending, "Submit viability".
     *
     * The only transition this ticket implements. Its precondition in the
     * registry is "Required fields present"; its side effect is "create
     * viability snapshot", which belongs to SNG-TRN-008 — so this method moves
     * the state and audits it, and deliberately does NOT calculate anything.
     */
    public function submitForViability(TransportTrip $trip, int $tenantId, ?User $actor = null): TransportTrip
    {
        $this->assertTenant($trip, $tenantId);

        $from = $trip->status;
        $to   = TripStatus::VIABILITY_PENDING;

        if (! TripStatus::canTransition($from, $to)) {
            throw new BusinessException(
                'A trip can only be submitted for viability from Draft. This trip is '.$trip->statusLabel().'.',
                422
            );
        }

        // STT-001 precondition. A trip with no agreed freight cannot be assessed
        // for margin, which is the entire point of the state it is moving into.
        if ($trip->approved_freight === null) {
            throw new BusinessException(
                'Set the approved freight before submitting this trip for viability.',
                422
            );
        }

        $trip->forceFill(['status' => $to, 'updated_by' => $actor?->id])->save();

        $trip->auditTransition('transport.trip.status_changed', $from, $to, $actor);

        Log::channel('transport')->info('Trip submitted for viability', [
            'trip_id' => $trip->id, 'from' => $from, 'to' => $to,
            'tenant_id' => $tenantId, 'user_id' => $actor?->id,
        ]);

        return $trip->fresh();
    }

    /**
     * Amend a draft trip's commercial fields.
     *
     * Only DRAFT, and only the two fields this ticket owns. Status moves through
     * transition methods, never through a general update — otherwise the state
     * machine becomes advisory.
     *
     * @param  array{approved_freight?:numeric,currency?:string,route?:string}  $data
     */
    public function update(TransportTrip $trip, array $data, int $tenantId, ?User $actor = null): TransportTrip
    {
        $this->assertTenant($trip, $tenantId);

        if ($trip->status !== TripStatus::DRAFT) {
            throw new BusinessException(
                'Only a draft trip can be edited. This trip is '.$trip->statusLabel().'.',
                422
            );
        }

        $before = $trip->only(['approved_freight', 'currency', 'route']);

        $trip->fill(array_merge(
            array_intersect_key($data, array_flip(['approved_freight', 'currency', 'route'])),
            ['updated_by' => $actor?->id],
        ))->save();

        $trip->audit('transport.trip.updated', $actor, old: $before, new: $trip->only(array_keys($before)));

        return $trip->fresh();
    }

    /** The order this trip came from, tenant-checked. */
    public function orderFor(TransportTrip $trip, int $tenantId): TransportOrder
    {
        $this->assertTenant($trip, $tenantId);

        return $this->orders->find((int) $trip->order_id, $tenantId);
    }

    private function assertTenant(TransportTrip $trip, int $tenantId): void
    {
        if ((int) $trip->tenant_id !== $tenantId) {
            Log::channel('transport')->warning('Trip tenant mismatch', [
                'trip_id' => $trip->id, 'tenant_id' => $tenantId,
            ]);

            throw new ResourceNotFoundException('Trip');
        }
    }
}
