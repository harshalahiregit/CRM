<?php

namespace App\Events\Transport;

use App\Models\Transport\TransportOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * EVT-001 OrderCreated — fired when a transport order is raised.
 *
 * Registry row, verbatim:
 *   Producer         OrderService
 *   Payload Core     order_id, customer_id
 *   Idempotency Key  order_id+version
 *   Consumers        TripEngine, Notifications
 *   Financial Impact No
 *   Audit            Yes
 *   Status           LOCKED
 *
 * ── A SEAM WITH NO LISTENERS, ON PURPOSE ──────────────────────────────────
 * Neither consumer subscribes yet: TripEngine creates trips from an order
 * through TransportTripService today, and Notifications is SNG-TRN-021 (P1).
 * This follows the pattern TripAssigned and App\Events\Accounts\VoucherPosted
 * already set — the contract is published now and a later ticket subscribes
 * without TransportOrderService changing.
 *
 * Publishing it matters beyond registry compliance. STOS-TM-001 §11 requires
 * Person 2 to receive transport_order_id from Person 1; with no event and no
 * read contract, their only route to it is querying our tables directly, which
 * is the residual exposure D-46 names as the thing this section cannot guard.
 * An unlistened event is a front door left open; a foreign query is the back
 * one forced.
 *
 * ── PAYLOAD IS THE TWO FIELDS THE REGISTRY LOCKS ──────────────────────────
 * order_id and customer_id, and nothing else. The order carries a reference, a
 * service type and a priority that a consumer might well want, and none of them
 * is in the Payload Core column. An event contract that quietly grows is one
 * consumers cannot rely on — and this row is LOCKED, so widening it needs the
 * same written approval ENUM-006 required, not a developer's judgement.
 *
 * See D-49 for what TM-001 §11 asks for that this payload does not carry.
 */
class OrderCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public TransportOrder $order,
    ) {
    }

    /** EVT-001 Payload Core, exactly. */
    public function payload(): array
    {
        return [
            'order_id'    => (int) $this->order->id,
            'customer_id' => $this->order->customer_id === null ? null : (int) $this->order->customer_id,
        ];
    }

    /**
     * EVT-001 Idempotency Key — "order_id+version".
     *
     * transport_orders has no version column, so `updated_at` stands in as the
     * version of the row a consumer is looking at. That is what a redelivery
     * check actually needs: two deliveries of the same order at the same
     * revision must produce the same key. Recorded rather than invented — if a
     * real version column is added later, this is the one line that changes.
     */
    public function idempotencyKey(): string
    {
        return $this->order->id.'+'.($this->order->updated_at?->getTimestamp() ?? 0);
    }

    /** Tenancy travels with the event; a listener must not have to infer it. */
    public function tenantId(): int
    {
        return (int) $this->order->tenant_id;
    }
}
