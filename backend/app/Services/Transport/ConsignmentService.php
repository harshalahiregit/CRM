<?php

namespace App\Services\Transport;

use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Transport\TransportConsignment;
use App\Services\Transport\TripEventRecorder;
use App\Models\Transport\TransportOrder;
use App\Models\User;
use App\Repositories\Transport\TransportConsignmentRepository;
use App\Support\Transport\TransportDocumentNumber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The commercial shipment — STOS-CTD §8's consignment.
 *
 * Requirements: `STOS-REQ-ORD-004` (link container to order), `STOS-REQ-CTD-002`
 * (link container to customer), `STOS-REQ-CTD-003` (link container to order) —
 * all P0. The entity exists by explicit architecture approval, D-39.
 *
 * ── WHAT THIS SERVICE DELIBERATELY DOES NOT DO ───────────────────────────
 * It does not gate creation on the order's status. A consignment is the
 * commercial description of what is being moved, and nothing in the RTM, the
 * FRS, STOS-CTD or Step 9 says an order must reach any particular state before
 * it can be described. Inventing "the order must be approved first" would be a
 * business rule with no source — Hard Rule 1 — and it would be wrong in the
 * ordinary case, where the consignment is captured WITH the order at draft.
 *
 * It does not compute a status. See D-44: STOS-CTD §11 puts that in a lifecycle
 * engine that does not exist, and its values span five lifecycles across two
 * owners.
 *
 * It does not touch containers. Those are the next two steps of this block.
 *
 * ── TENANCY ──────────────────────────────────────────────────────────────
 * Every lookup is tenant-scoped AT the point of lookup, and a cross-tenant id
 * raises ResourceNotFoundException — 404, never 403. Telling the caller "not
 * yours" would confirm the row exists in somebody else's workspace.
 */
class ConsignmentService
{
    public function __construct(private TransportConsignmentRepository $consignments)
    {
    }

    /** Fields a caller may set. Everything else is derived or stamped. */
    private const WRITABLE = [
        'customer_reference', 'cargo_description', 'service_type',
        'special_handling', 'package_count', 'gross_weight_kg', 'volume_cbm',
    ];

    /* ── Reads ──────────────────────────────────────────────────────── */

    public function list(int $tenantId, array $filters = []): LengthAwarePaginator
    {
        return $this->consignments->filtered($tenantId, $filters);
    }

    public function find(int $id, int $tenantId): TransportConsignment
    {
        $consignment = $this->consignments->findForTenant($id, $tenantId);

        if (! $consignment) {
            // The same exception a genuinely missing row raises.
            throw new ResourceNotFoundException('Consignment');
        }

        return $consignment;
    }

    /** CTD-003 — every consignment on one order. */
    public function forOrder(int $orderId, int $tenantId)
    {
        // Resolve the order first so an unknown or cross-tenant id reads as
        // "no such order" rather than returning a confident empty list.
        $this->assertOrderUsable($orderId, $tenantId);

        return $this->consignments->forOrder($orderId, $tenantId);
    }

    /* ── Writes ─────────────────────────────────────────────────────── */

    /**
     * @param  array<string,mixed>  $data
     */
    public function create(array $data, int $tenantId, ?User $actor = null): TransportConsignment
    {
        $order = $this->assertOrderUsable((int) ($data['order_id'] ?? 0), $tenantId);

        $created = DB::transaction(function () use ($data, $order, $tenantId, $actor) {
            /** @var TransportConsignment $consignment */
            $consignment = TransportConsignment::create(array_merge(
                $this->writable($data),
                [
                    'tenant_id'          => $tenantId,
                    // The central engine allocates when the tenant has switched
                    // 'transport_consignment' on; otherwise the model's own
                    // allocator keeps the same shape. Opt-in per tenant, so this
                    // cannot assume it.
                    'consignment_number' => TransportDocumentNumber::allocate(
                        'transport_consignment',
                        $tenantId,
                        fn () => TransportConsignment::nextLocalNumber($tenantId),
                    ),
                    'order_id'    => $order->id,
                    // CTD-002. Denormalised FROM the order rather than accepted
                    // from the caller: two sources for one fact is two chances
                    // to disagree, and the order owns the customer.
                    'customer_id' => $order->customer_id,
                    'created_by'  => $actor?->id,
                    'updated_by'  => $actor?->id,
                ],
            ));

            $consignment->audit('transport.consignment.created', $actor, new: [
                'consignment_number' => $consignment->consignment_number,
                'order_id'           => $consignment->order_id,
                'order_number'       => $order->order_number,
                'customer_id'        => $consignment->customer_id,
                'customer_reference' => $consignment->customer_reference,
                'service_type'       => $consignment->service_type,
                'has_cargo_detail'   => $consignment->hasCargoDetail(),
            ]);

            Log::channel('transport')->info('Consignment created', [
                'consignment_id'     => $consignment->id,
                'consignment_number' => $consignment->consignment_number,
                'order_id'           => $order->id,
                'tenant_id'          => $tenantId,
                'user_id'            => $actor?->id,
            ]);

            return $consignment->fresh();
        });

        // CTD §31, after the commit. Recorded against the consignment and its
        // order — there is no trip yet, and may never be one. D-115.
        app(TripEventRecorder::class)->record(
            'consignment.created', tenantId: $tenantId, actor: $actor,
            consignmentId: $created->id, orderId: $created->order_id,
            detail: ['consignment_number' => $created->consignment_number],
        );

        return $created;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function update(TransportConsignment $consignment, array $data, int $tenantId, ?User $actor = null): TransportConsignment
    {
        $this->assertTenant($consignment, $tenantId);

        $payload = $this->writable($data);

        if ($payload === []) {
            throw new BusinessException('No consignment field was supplied to update.', 422);
        }

        return DB::transaction(function () use ($consignment, $payload, $tenantId, $actor) {
            $before = $consignment->only(array_keys($payload));

            $consignment->fill($payload);
            $consignment->updated_by = $actor?->id;

            // Nothing actually differs. Writing an audit row for a no-op would
            // make a real change harder to find in the trail.
            if (! $consignment->isDirty()) {
                return $consignment->fresh();
            }

            $changed = array_keys($consignment->getDirty());
            $consignment->save();

            $consignment->audit('transport.consignment.updated', $actor,
                old: array_intersect_key($before, array_flip($changed)),
                new: $consignment->only($changed),
                context: [
                    'consignment_number' => $consignment->consignment_number,
                    'fields'             => $changed,
                ],
            );

            Log::channel('transport')->info('Consignment updated', [
                'consignment_id' => $consignment->id, 'fields' => $changed,
                'tenant_id' => $tenantId, 'user_id' => $actor?->id,
            ]);

            return $consignment->fresh();
        });
    }

    public function delete(TransportConsignment $consignment, int $tenantId, ?User $actor = null): void
    {
        $this->assertTenant($consignment, $tenantId);

        // A consignment a trip is already carrying is not a draft somebody can
        // tidy away — the trip's history would then point at nothing, which is
        // the referential failure D-14 was raised for.
        $trips = $consignment->trips()->count();

        if ($trips > 0) {
            throw new BusinessException(
                'This consignment is already carried by '.$trips.' trip'.($trips === 1 ? '' : 's')
                .' and cannot be deleted. Detach it from the trip first.',
                422,
            );
        }

        DB::transaction(function () use ($consignment, $actor) {
            $consignment->audit('transport.consignment.deleted', $actor, old: [
                'consignment_number' => $consignment->consignment_number,
                'order_id'           => $consignment->order_id,
            ]);

            // Soft delete — the number stays out of circulation, and
            // nextLocalNumber reads withTrashed() so it is never reissued.
            $consignment->delete();
        });

        Log::channel('transport')->info('Consignment deleted', [
            'consignment_id' => $consignment->id, 'tenant_id' => $tenantId, 'user_id' => $actor?->id,
        ]);
    }

    /* ── internals ──────────────────────────────────────────────────── */

    /** @param array<string,mixed> $data */
    private function writable(array $data): array
    {
        return array_intersect_key($data, array_flip(self::WRITABLE));
    }

    /**
     * The order must exist in THIS workspace.
     *
     * Deliberately not a status gate — see the class docblock. This checks
     * existence and tenancy only, which are not business rules.
     */
    private function assertOrderUsable(int $orderId, int $tenantId): TransportOrder
    {
        $order = TransportOrder::forTenant($tenantId)->find($orderId);

        if (! $order) {
            // Tenant-scoped lookup: naming another workspace's order must read
            // as "no such order", never as "not yours".
            throw new BusinessException('That order does not exist in this workspace.', 422);
        }

        return $order;
    }

    private function assertTenant(TransportConsignment $consignment, int $tenantId): void
    {
        if ((int) $consignment->tenant_id !== $tenantId) {
            Log::channel('transport')->warning('Consignment tenant mismatch', [
                'consignment_id' => $consignment->id, 'tenant_id' => $tenantId,
            ]);

            throw new ResourceNotFoundException('Consignment');
        }
    }
}
