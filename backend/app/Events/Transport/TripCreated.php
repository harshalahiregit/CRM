<?php

namespace App\Events\Transport;

use App\Models\Transport\TransportTrip;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * EVT-002 TripCreated — fired when an order becomes an executable trip.
 *
 * Registry row, verbatim:
 *   Producer         TripEngine
 *   Payload Core     trip_id, order_id
 *   Idempotency Key  trip_id+version
 *   Consumers        Viability, Notifications
 *   Financial Impact No
 *   Audit            Yes
 *   Status           LOCKED
 *
 * ── A SEAM WITH NO LISTENERS, ON PURPOSE ──────────────────────────────────
 * Neither consumer subscribes yet: Viability is SNG-TRN-008, which no ticket
 * has built, and Notifications is SNG-TRN-021 (P1). Same published-seam pattern
 * as TripAssigned.
 *
 * STOS-TM-001 §11 requires Person 2 to receive trip_id and transport_order_id
 * from Person 1. This event is the first mechanism by which they can, without
 * reading our tables — see D-46's residual exposure and D-48.
 *
 * ── WHAT IS DELIBERATELY NOT IN THE PAYLOAD ───────────────────────────────
 * consignment_id is NOT here, and that is a decision rather than an oversight.
 * TM-001 §11 names it among what Person 2 must receive, and transport_trips has
 * carried the column since 2026-09-15 — but EVT-002's Payload Core is LOCKED at
 * "trip_id, order_id". Adding a field to a LOCKED payload is the same class of
 * change as adding a value to ENUM-006, which required explicit written
 * approval. So it is logged, not added: see D-49.
 *
 * container_id, also named in TM-001 §11, does not exist yet in any case —
 * transport_containers is the next step of this block.
 */
class TripCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public TransportTrip $trip,
    ) {
    }

    /** EVT-002 Payload Core, exactly. */
    public function payload(): array
    {
        return [
            'trip_id'  => (int) $this->trip->id,
            'order_id' => (int) $this->trip->order_id,
        ];
    }

    /**
     * EVT-002 Idempotency Key — "trip_id+version".
     *
     * transport_trips has no version column; `updated_at` stands in, exactly as
     * it does for EVT-001. See OrderCreated::idempotencyKey().
     */
    public function idempotencyKey(): string
    {
        return $this->trip->id.'+'.($this->trip->updated_at?->getTimestamp() ?? 0);
    }

    /** Tenancy travels with the event; a listener must not have to infer it. */
    public function tenantId(): int
    {
        return (int) $this->trip->tenant_id;
    }
}
