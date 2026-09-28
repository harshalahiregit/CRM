<?php

namespace App\Services\Transport;

use App\Events\Transport\TripApproved;
use App\Services\Transport\AllocationService;
use App\Services\Transport\TripEventRecorder;
use App\Events\Transport\TripCreated;
use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Transport\TransportConsignment;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\User;
use App\Repositories\Transport\TransportTripRepository;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\TransitScope;
use App\Support\Transport\TransportDocumentNumber;
use App\Support\Transport\TripStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
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

        $created = DB::transaction(function () use ($order, $data, $tenantId, $actor) {
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
                // CTD-003 — which shipment this trip is moving. Optional: a trip
                // may be raised before the consignment is described.
                'consignment_id'   => $this->resolveConsignmentId($data, $order, $tenantId),
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
                'consignment_id'   => $trip->consignment_id,
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

            // EVT-002 TripCreated (LOCKED). Inside the transaction, as EVT-001
            // and EVT-005 are — a synchronous listener acting on a rolled-back
            // trip would be acting on a row that never existed.
            //
            // Viability (SNG-TRN-008) and Notifications (SNG-TRN-021) are the
            // registry's consumers and neither exists. Published anyway: TM-001
            // §11 requires Person 2 to receive trip_id and transport_order_id
            // from us, and until now there was no mechanism but our tables.
            TripCreated::dispatch($trip->fresh());

            return $trip;
        });

        // CTD §31's timeline. AFTER the commit, like every other recorder call:
        // a trip event describing a trip that was rolled back would be a line of
        // history for something that never happened.
        //
        // This was missing until 2026-09-19. The row existed on every trip only
        // because BackfillTripEvents reconstructed it from the audit log, so the
        // gap was invisible — the timelines looked complete. D-115.
        app(TripEventRecorder::class)->record('trip.created', trip: $created, actor: $actor);

        return $created;
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

        $trip->forceFill([
            'status'     => $to,
            'updated_by' => $actor?->id,
            // STT-003's reason is the OUTSTANDING objection, not a permanent
            // mark. Resubmitting answers it, so it is cleared here — otherwise a
            // trip that went round the loop and was approved would still display
            // the objection it had already satisfied. The audit keeps the
            // history.
            'rejection_reason' => null,
        ])->save();

        $trip->auditTransition('transport.trip.status_changed', $from, $to, $actor);

        Log::channel('transport')->info('Trip submitted for viability', [
            'trip_id' => $trip->id, 'from' => $from, 'to' => $to,
            'tenant_id' => $tenantId, 'user_id' => $actor?->id,
        ]);

        $submitted = $trip->fresh();

        // CTD §31. Backfill supplied this row historically; nothing wrote it
        // live. D-115.
        app(TripEventRecorder::class)->record('trip.submitted', trip: $submitted, actor: $actor);

        return $submitted;
    }

    /**
     * STT-002 — `viability_pending → approved`, "Approve viable trip".
     *
     *   STT-002  | ApprovalService | precondition "Margin policy passed"
     *            | side effect "Emit TripApproved" | audited | LOCKED
     *
     * ── WHAT THIS CHECKS, AND WHAT IT DOES NOT ───────────────────────────
     * It checks the STATE and, at the route, the PERMISSION (PERM-003, which
     * denies the Dispatcher). **It checks NOTHING about the commercials.**
     *
     * STT-002's LOCKED precondition is "Margin policy passed" and it is NOT
     * enforced here. That is a ruling, not an oversight: the margin verdict
     * belongs to SNG-TRN-008 (Trip Viability), which is not built and is blocked
     * on SNG-TRN-005's rate card — a P0 ticket with NO ASSIGNED OWNER — and on
     * Person 3's unbuilt `trip_costs`. Without it the entire chain after trip
     * creation was unreachable by any real user: allocation, pre-trip and
     * dispatch were all built and all dead. See D-63 for that gap and D-64 for
     * this deferral.
     *
     * SO: A USER CAN APPROVE A TRIP THAT WOULD LOSE MONEY. The approval dialog
     * says so on screen, and TripApprovalTest pins the absence with a test
     * written to fail the day viability lands. When SNG-TRN-008 arrives, the
     * margin gate goes HERE, in front of the transition.
     *
     * ── WHY THE APPROVER IS RECORDED ON THE TRIP ─────────────────────────
     * EVT-004's payload needs `approved_by`, and its idempotency key names an
     * `approval_id` for which no table exists (D-65). Recorded on the trip, like
     * `dispatched_by` before it, rather than inventing an entity to satisfy a
     * key.
     */
    public function approve(TransportTrip $trip, int $tenantId, ?User $actor = null): TransportTrip
    {
        $this->assertTenant($trip, $tenantId);

        $from = $trip->status;
        $to   = TripStatus::APPROVED;

        if (! TripStatus::canTransition($from, $to)) {
            throw new BusinessException(
                'Only a trip awaiting viability can be approved. This trip is '.$trip->statusLabel().'.',
                422
            );
        }

        // Carried from STT-001: a trip with no agreed freight could not have
        // been assessed, so approving one would be approving nothing. This is
        // NOT the margin gate — it is the same field check that let the trip
        // into viability_pending in the first place.
        if ($trip->approved_freight === null) {
            throw new BusinessException(
                'This trip has no approved freight, so there is nothing to approve.',
                422
            );
        }

        $approved = DB::transaction(function () use ($trip, $from, $to, $actor) {
            $trip->forceFill([
                'status'      => $to,
                'approved_at' => now(),
                'approved_by' => $actor?->id,
                'updated_by'  => $actor?->id,
            ])->save();

            $trip->auditTransition('transport.trip.status_changed', $from, $to, $actor);

            return $trip->fresh();
        });

        // STT-002's side effect. Emitted AFTER the transaction commits, so no
        // listener can ever see an approval that was rolled back.
        TripApproved::dispatch($approved);

        // CTD §31's timeline. After the commit, for the same reason.
        app(TripEventRecorder::class)->record('trip.approved', trip: $approved, actor: $actor);

        Log::channel('transport')->info('Trip approved', [
            'trip_id' => $approved->id, 'from' => $from, 'to' => $to,
            'tenant_id' => $tenantId, 'user_id' => $actor?->id,
            // Recorded on every approval so the deferral is visible in the logs
            // as well as the code — see D-64.
            'margin_policy_checked' => false,
        ]);

        return $approved;
    }

    /**
     * STT-003 — `viability_pending → draft`, "Reject for correction".
     *
     *   STT-003  | actor Operations | precondition "Rejection reason"
     *            | side effect "Return to edit" | audited | LOCKED
     *
     * The other half of the review. A reviewer who can only say yes is not
     * reviewing, and before this a trip that should not be approved had nowhere
     * to go but forward.
     *
     * ── THE REASON IS THE PRECONDITION, SO IT IS MANDATORY ───────────────
     * STT-003 names "Rejection reason" as its precondition, so an empty one is
     * refused rather than defaulted. A trip that returns to draft with no
     * recorded objection is its own kind of trap door: the person who has to fix
     * it cannot know what to fix, and the next reviewer cannot see it was ever
     * questioned.
     *
     * ── THE LOOP MUST CLOSE ──────────────────────────────────────────────
     * "Return to edit" means edit and RESUBMIT. A rejected trip goes back to
     * draft, where update() accepts changes again, and submitForViability()
     * moves it forward once more — clearing this reason as it goes. The trip can
     * go round as many times as it takes.
     *
     * ── PERMISSION: TRIP_APPROVE, REUSED DELIBERATELY ────────────────────
     * Step 11 has NO permission row for rejecting a trip. Rather than invent a
     * second grant matrix, this reuses PERM-003's — approve and reject are the
     * two answers to one question, and sending a trip back is strictly less
     * powerful than approving it. Inventing a matrix nobody specified would be
     * the larger step. Recorded in D-12 with the other derived rows.
     */
    public function reject(TransportTrip $trip, ?string $reason, int $tenantId, ?User $actor = null): TransportTrip
    {
        $this->assertTenant($trip, $tenantId);

        $from = $trip->status;
        $to   = TripStatus::DRAFT;

        if (! TripStatus::canTransition($from, $to)) {
            throw new BusinessException(
                'Only a trip awaiting viability can be sent back. This trip is '.$trip->statusLabel().'.',
                422
            );
        }

        $reason = trim((string) $reason);

        if ($reason === '') {
            throw new BusinessException(
                'Give a reason for sending this trip back, so whoever corrects it knows what to change.',
                422
            );
        }

        $rejected = DB::transaction(function () use ($trip, $from, $to, $actor, $reason) {
            $trip->forceFill([
                'status'           => $to,
                'rejection_reason' => $reason,
                'updated_by'       => $actor?->id,
                // A rejected trip has never been approved. Clearing these keeps
                // the two records from contradicting each other if a trip is
                // approved, and it cannot be while it sits in draft.
                'approved_at'      => null,
                'approved_by'      => null,
            ])->save();

            // The reason travels in the audit row as well as the column: the
            // column holds the OUTSTANDING objection and is cleared on resubmit,
            // so without this the history of a trip rejected twice would show
            // that it happened but not what was said either time.
            $trip->audit('transport.trip.rejected', $actor, old: ['status' => $from], new: [
                'status' => $to,
                'reason' => $reason,
            ]);

            return $trip->fresh();
        });

        Log::channel('transport')->info('Trip sent back for correction', [
            'trip_id' => $rejected->id, 'from' => $from, 'to' => $to,
            'tenant_id' => $tenantId, 'user_id' => $actor?->id,
        ]);

        // CTD §31. The reason travels with it: a timeline that shows a trip went
        // back to draft without saying what was objected to sends the reader to
        // the audit log to find out, which is the thing the timeline exists to
        // save them. D-115.
        app(TripEventRecorder::class)->record(
            'trip.returned', trip: $rejected, actor: $actor,
            detail: ['reason' => $reason],
            summary: $reason ? 'Sent back: '.$reason : null,
        );

        return $rejected;
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

    /**
     * The consignment this trip is moving — STOS-CTD §8, CTD-003.
     *
     * Optional, because a trip can legitimately be raised from an approved
     * order before anyone has described what is being moved.
     *
     * TWO REFUSALS, AND THEY ARE DIFFERENT:
     *   - another tenant's consignment reads as "no such consignment" (404),
     *     never "not yours", which would confirm the row exists;
     *   - a consignment belonging to a DIFFERENT order is a 422, because it
     *     exists and is visible — the caller has simply picked the wrong one,
     *     and telling them so is the useful answer.
     *
     * Without this check a trip could carry a consignment from an unrelated
     * order, and every screen that reads order -> consignment -> trip would
     * show a chain that does not hold.
     *
     * @param  array<string,mixed>  $data
     */
    private function resolveConsignmentId(array $data, TransportOrder $order, int $tenantId): ?int
    {
        $consignmentId = $data['consignment_id'] ?? null;

        if ($consignmentId === null) {
            return null;
        }

        $consignment = TransportConsignment::forTenant($tenantId)->find((int) $consignmentId);

        if (! $consignment) {
            throw new ResourceNotFoundException('Consignment');
        }

        if ((int) $consignment->order_id !== (int) $order->id) {
            throw new BusinessException(
                'Consignment '.$consignment->consignment_number.' is on a different order '
                .'and cannot be carried by this trip.',
                422,
            );
        }

        return (int) $consignment->id;
    }

    /** The order this trip came from, tenant-checked. */
    public function orderFor(TransportTrip $trip, int $tenantId): TransportOrder
    {
        $this->assertTenant($trip, $tenantId);

        return $this->orders->find((int) $trip->order_id, $tenantId);
    }

    /**
     * STT-007 — `in_transit → delivered`. Record that the load arrived.
     *
     *   STT-007 | trigger "Delivery confirmation" | actor TripEngine
     *           | precondition "Destination event" | side effect "Request POD"
     *           | audited | LOCKED
     *   SM-TRP  | `delivered` · active · entry gate "Destination confirmed"
     *           | exit "POD received" · owner Operations
     *   RTM     | STOS-REQ-OPS-010 "Record delivery" · P0 · "Delivery confirmed"
     *
     * ── "DESTINATION EVENT" WITH NO SENSOR TO RAISE ONE ──────────────────
     * The precondition names an event that only telemetry could produce, and
     * there is none (SNG-TRN-020, P1). But SM-TRP's entry gate is "Destination
     * CONFIRMED", which a person can do, and FRS TRP-P0-011's own rule line
     * grants a "manual update fallback". So this is the specified path with its
     * automatic half missing, not a substitute for it. Same reasoning the owner
     * accepted for departure in Q3.
     *
     * ── NOT VIA `arrived` ────────────────────────────────────────────────
     * Step 9 puts ARRIVED on this edge. Nothing in any document gates it, and
     * no requirement records an arrival distinct from a delivery — the RTM runs
     * OPS-008 dispatch → OPS-009 track → OPS-010 delivery with nothing between.
     * Under the standing rule of 2026-09-17 it stays declared and unreachable.
     * D-36, closed.
     *
     * ── "REQUEST POD" IS A SENTENCE, NOT A RECORD ────────────────────────
     * Reaching `delivered` IS the request: TripDocumentService::verify()
     * already declines to advance a trip that is not standing here, and
     * billingReadiness() already computes what is outstanding. A `pod_requests`
     * table invented to satisfy two words would be a record no document
     * defines — nothing would say when it closes, who owns it, or what a second
     * one means. The screen says proof of delivery is now required; the state
     * does the rest. See TransitScope::POD_REQUEST_FORM.
     *
     * ── THE PERMISSION IS DELIBERATELY NOT THE DRIVER'S ──────────────────
     * FRS TRP-P0-013's actor is "Driver/Delivery", but that row is POD CAPTURE,
     * which is P3's and where PERM-010 already gives the Driver `own`. The
     * state change is not the proof. A driver submits what they have; an
     * operator confirms the trip is delivered, because that unlocks billing for
     * everyone downstream. TRIP_DELIVER mirrors PERM-004.
     *
     * @param  array{delivered_at?:string|null}  $fields
     */
    public function recordDelivery(TransportTrip $trip, array $fields, int $tenantId, ?User $actor = null): TransportTrip
    {
        $this->assertTenant($trip, $tenantId);

        $from = $trip->status;
        $to   = TripStatus::DELIVERED;

        if (! TripStatus::canTransition($from, $to)) {
            throw new BusinessException(
                $from === TripStatus::DISPATCHED
                    // The most likely mistake, and the one worth naming: the
                    // trip was released but nobody recorded it leaving.
                    ? 'This trip has been released but is not recorded as having left yet. Record the departure first.'
                    : ($from === TripStatus::DELIVERED
                        ? 'This trip is already recorded as delivered.'
                        : 'Only a trip in transit can be recorded as delivered. This trip is '.$trip->statusLabel().'.'),
                422
            );
        }

        $deliveredAt = $this->deliveryTime($trip, $fields['delivered_at'] ?? null);

        $delivered = DB::transaction(function () use ($trip, $from, $to, $deliveredAt, $actor) {
            $trip->forceFill([
                'status'       => $to,
                'delivered_at' => $deliveredAt,
                'delivered_by' => $actor?->id,
                'updated_by'   => $actor?->id,
            ])->save();

            $trip->auditTransition(
                'transport.trip.status_changed',
                $from,
                $to,
                $actor,
                [
                    'rule'          => TransitScope::OPS_010,
                    'transition'    => TransitScope::EDGE_DELIVERY,
                    'registry'      => TransitScope::STT_007,
                    'authorization' => TransitScope::AUTHORIZATION_DELIVERY,
                    'sources'       => 'RTM STOS-REQ-OPS-010; SM-TRP entry gate "Destination confirmed"; FRS TRP-P0-011 ("manual update fallback")',
                    'trip_number'   => $trip->trip_number,
                    'delivered_at'  => $deliveredAt,
                    'recorded'      => 'manual',
                    // STT-007's side effect, and the form it actually takes.
                    'pod_requested' => TransitScope::POD_REQUEST_FORM,
                    // Declared so the audit trail itself records that no arrival
                    // was skipped over — the state does not exist.
                    'via_arrived'   => false,
                ],
            );

            return $trip->fresh();
        });

        app(TripEventRecorder::class)->record(
            'trip.delivered', trip: $delivered, actor: $actor, occurredAt: $delivered->delivered_at,
        );

        // The cargo is off, so the vehicle and the driver come free — STOS-OPS
        // §83 separates operational closure from accounting closure, and our
        // `closed` is the accounting end. The reasoning is in
        // AllocationService::releaseOnDelivery(), which owns the resource
        // vocabulary; this line only says WHEN. D-119.
        //
        // After the commit, like every other side effect of this method: a trip
        // that rolled back must not have freed a truck.
        app(AllocationService::class)->releaseOnDelivery($delivered, $tenantId, $actor);

        Log::channel('transport')->info('Trip delivered', [
            'trip_id' => $delivered->id, 'tenant_id' => $tenantId,
            'user_id' => $actor?->id, 'delivered_at' => $deliveredAt,
            'registry' => TransitScope::STT_007, 'recorded' => 'manual',
        ]);

        return $delivered;
    }

    /**
     * When it arrived — TransitScope::TIME_ORDER.
     *
     * Backdating allowed, for the same reason as departure. Refused: a delivery
     * in the future, and a delivery before the trip left.
     */
    private function deliveryTime(TransportTrip $trip, ?string $given): string
    {
        if ($given === null || trim($given) === '') {
            return now()->format('Y-m-d H:i:s');
        }

        $at = Carbon::parse($given);

        if ($at->isFuture()) {
            throw new BusinessException(
                'A delivery cannot be recorded in the future. Leave the time blank to use now.',
                422
            );
        }

        if ($trip->departed_at && $at->lt($trip->departed_at)) {
            throw new BusinessException(
                'The trip cannot have arrived before it left. It departed on '
                .$trip->departed_at->format('j M Y, H:i').'.',
                422
            );
        }

        return $at->format('Y-m-d H:i:s');
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
