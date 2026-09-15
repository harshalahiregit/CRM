<?php

namespace App\Services\Transport;

use App\Events\Transport\OrderCreated;
use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Customer\Client;
use App\Models\Transport\TransportOrder;
use App\Models\User;
use App\Repositories\Transport\TransportOrderRepository;
use App\Support\Transport\OrderPriority;
use App\Support\Transport\OrderSource;
use App\Support\Transport\TransportDocumentNumber;
use App\Support\Transport\OrderStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Transport Order business logic (SNG-TRN-006).
 *
 * Two things this class is careful about:
 *
 *  VALIDATION IS A GATE, NOT A FORM CHECK. STOS-OPS §7 lists what must hold
 *  "before an order becomes operationally actionable": customer exists, customer
 *  active, service available, pickup defined, delivery defined, required
 *  date/time defined, applicable commercial reference exists. Shape validation
 *  lives in the FormRequest; the gates that need the database live here, so they
 *  apply however the order arrives — API, import, or a later channel.
 *
 *  EVERY MATERIAL ACT IS AUDITED. Creation and each status change write to the
 *  SNG-TRN-027 trail through the model's audit() helper. This is the first real
 *  proof the foundation works outside its own tests.
 *
 * $tenantId is a parameter throughout rather than read from auth().
 */
class TransportOrderService
{
    public function __construct(private TransportOrderRepository $orders)
    {
    }

    /** @param array<string,mixed> $filters */
    public function list(int $tenantId, array $filters = []): LengthAwarePaginator
    {
        return $this->orders->filtered($tenantId, $filters);
    }

    public function statusCounts(int $tenantId): array
    {
        return $this->orders->statusCounts($tenantId);
    }

    public function find(int $id, int $tenantId): TransportOrder
    {
        $order = $this->orders->findForTenant($id, $tenantId);

        if (! $order) {
            // The same exception a genuinely missing row raises. Distinguishing
            // "not yours" from "not there" would confirm the record exists in
            // somebody else's tenant.
            throw new ResourceNotFoundException('Transport order');
        }

        return $order;
    }

    /**
     * Create an order in DRAFT.
     *
     * @param  array<string,mixed>  $data
     */
    public function create(array $data, int $tenantId, ?User $actor = null): TransportOrder
    {
        $this->assertCustomerUsable((int) $data['customer_id'], $tenantId);

        return DB::transaction(function () use ($data, $tenantId, $actor) {
            /** @var TransportOrder $order */
            $order = TransportOrder::create([
                'tenant_id'            => $tenantId,
                // STOS-DB §161 — unique per organization. The central engine
                // allocates when the tenant has switched 'transport_order' on;
                // otherwise the model's own allocator keeps the same shape. The
                // engine is opt-in per tenant, so this cannot assume it.
                'order_number'         => TransportDocumentNumber::allocate(
                    'transport_order',
                    $tenantId,
                    fn () => TransportOrder::nextLocalNumber($tenantId),
                ),
                'customer_id'          => $data['customer_id'],
                'customer_reference'   => $data['customer_reference'] ?? null,
                'pickup_location'      => $data['pickup_location'],
                'delivery_location'    => $data['delivery_location'],
                'required_at'          => $data['required_at'],
                'service_type'         => $data['service_type'],
                'order_status'         => OrderStatus::INITIAL,
                'priority'             => $data['priority'] ?? OrderPriority::DEFAULT,
                'source'               => $data['source'] ?? OrderSource::DEFAULT,
                'rate_reference'       => $data['rate_reference'] ?? null,
                'route'                => $data['route'] ?? null,
                'special_requirements' => $data['special_requirements'] ?? null,
                'billing_requirements' => $data['billing_requirements'] ?? null,
                'created_by'           => $actor?->id,
                'updated_by'           => $actor?->id,
            ]);

            $order->audit('transport.order.created', $actor, new: [
                'order_number' => $order->order_number,
                'customer_id'  => $order->customer_id,
                'service_type' => $order->service_type,
                'priority'     => $order->priority,
                'source'       => $order->source,
                'status'       => $order->order_status,
            ]);

            Log::channel('transport')->info('Transport order created', [
                'order_id' => $order->id, 'order_number' => $order->order_number,
                'tenant_id' => $tenantId, 'user_id' => $actor?->id,
            ]);

            // EVT-001 OrderCreated (LOCKED). Dispatched inside the transaction,
            // as EVT-005 is: Laravel holds queued listeners until commit, and a
            // synchronous listener that ran against a rolled-back order would be
            // acting on a row that never existed.
            //
            // No listener subscribes yet — TripEngine and Notifications are the
            // registry's consumers and neither does. That is the point: the seam
            // is published so Person 2 and Person 3 can subscribe without this
            // service changing, instead of reading transport_orders directly
            // (D-46's residual exposure, D-48).
            OrderCreated::dispatch($order->fresh());

            return $order;
        });
    }

    /**
     * Amend a draft order.
     *
     * Only DRAFT is editable. Once submitted, an order is under validation and a
     * silent edit would change what somebody is being asked to approve.
     * order_number is never editable — it is the commercial reference.
     *
     * @param  array<string,mixed>  $data
     */
    public function update(TransportOrder $order, array $data, int $tenantId, ?User $actor = null): TransportOrder
    {
        $this->assertTenant($order, $tenantId);

        if ($order->order_status !== OrderStatus::DRAFT) {
            throw new BusinessException(
                'Only a draft order can be edited. This order is '.$order->statusLabel().'.',
                422
            );
        }

        if (isset($data['customer_id']) && (int) $data['customer_id'] !== (int) $order->customer_id) {
            $this->assertCustomerUsable((int) $data['customer_id'], $tenantId);
        }

        $before = $order->only([
            'customer_id', 'customer_reference', 'pickup_location', 'delivery_location',
            'required_at', 'service_type', 'priority', 'rate_reference', 'route',
            'special_requirements', 'billing_requirements',
        ]);

        $order->fill(array_merge(
            array_intersect_key($data, array_flip([
                'customer_id', 'customer_reference', 'pickup_location', 'delivery_location',
                'required_at', 'service_type', 'priority', 'rate_reference', 'route',
                'special_requirements', 'billing_requirements',
            ])),
            ['updated_by' => $actor?->id],
        ))->save();

        $order->audit('transport.order.updated', $actor, old: $before, new: $order->only(array_keys($before)));

        return $order->fresh();
    }

    /**
     * Move an order through SM-ORD.
     *
     * One method for every transition so the guard cannot be bypassed by adding
     * another. OrderStatus::canTransition is the only authority on what is legal.
     */
    public function transition(TransportOrder $order, string $to, int $tenantId, ?User $actor = null, ?string $reason = null): TransportOrder
    {
        $this->assertTenant($order, $tenantId);

        $from = $order->order_status;

        if ($from === $to) {
            throw new BusinessException('This order is already '.OrderStatus::LABELS[$to].'.', 422);
        }

        if (! OrderStatus::canTransition($from, $to)) {
            throw new BusinessException(
                'An order cannot move from '.OrderStatus::LABELS[$from].' to '.OrderStatus::LABELS[$to].'.',
                422
            );
        }

        // OPS §7 — the operational-readiness gate. Checked on the way OUT of
        // draft rather than on create, because a draft is explicitly allowed to
        // be incomplete while it is being assembled.
        if ($to === OrderStatus::SUBMITTED) {
            $this->assertOperationallyActionable($order, $tenantId);
        }

        if ($to === OrderStatus::REJECTED && ! $reason) {
            // A rejection with no reason is not reviewable, and Step 9 P-008
            // asks for changes that are reviewable, not merely recorded.
            throw new BusinessException('Rejecting an order needs a reason.', 422);
        }

        $order->forceFill(['order_status' => $to, 'updated_by' => $actor?->id])->save();

        $order->auditTransition(
            'transport.order.status_changed',
            $from,
            $to,
            $actor,
            array_filter(['reason' => $reason]),
        );

        Log::channel('transport')->info('Transport order status changed', [
            'order_id' => $order->id, 'from' => $from, 'to' => $to,
            'tenant_id' => $tenantId, 'user_id' => $actor?->id,
        ]);

        return $order->fresh();
    }

    /* ── Gates ──────────────────────────────────────────────────────── */

    /**
     * STOS-OPS §7, the half that needs the database: "Customer exists; customer
     * active". The rest of §7 — pickup, delivery, required date, service — is
     * shape validation and is enforced by the FormRequest and by NOT NULL.
     */
    private function assertCustomerUsable(int $customerId, int $tenantId): void
    {
        $customer = Client::forTenant($tenantId)->find($customerId);

        if (! $customer) {
            // Tenant-scoped lookup: naming another tenant's customer must read
            // as "no such customer", never as "not yours".
            throw new BusinessException('That customer does not exist in this workspace.', 422);
        }

        if (property_exists($customer, 'active') || isset($customer->active)) {
            if ((int) $customer->active === 0) {
                throw new BusinessException('That customer is inactive and cannot be given new orders.', 422);
            }
        }
    }

    /** The §7 gate applied when an order leaves draft. */
    private function assertOperationallyActionable(TransportOrder $order, int $tenantId): void
    {
        $this->assertCustomerUsable((int) $order->customer_id, $tenantId);

        $missing = [];
        if (blank($order->pickup_location))   { $missing[] = 'pickup location'; }
        if (blank($order->delivery_location)) { $missing[] = 'delivery location'; }
        if (blank($order->required_at))       { $missing[] = 'required date and time'; }
        if (blank($order->service_type))      { $missing[] = 'service type'; }

        if ($missing) {
            throw new BusinessException(
                'This order cannot be submitted yet — it still needs: '.implode(', ', $missing).'.',
                422
            );
        }
    }

    private function assertTenant(TransportOrder $order, int $tenantId): void
    {
        if ((int) $order->tenant_id !== $tenantId) {
            Log::channel('transport')->warning('Transport order tenant mismatch', [
                'order_id' => $order->id, 'tenant_id' => $tenantId,
            ]);

            throw new ResourceNotFoundException('Transport order');
        }
    }
}
