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
 * ── consignment_id IS AN APPROVED ADDITION TO A LOCKED PAYLOAD ────────────
 * EVT-002's Payload Core is LOCKED at "trip_id, order_id". consignment_id is a
 * THIRD field, added on explicit written approval of 2026-09-15 — the same route
 * `delivery_order` took into ENUM-006, and for the same reason: a LOCKED row is
 * not a developer's to widen. Grounds recorded beside D-49:
 *
 *   - STOS-TM-001 §11, an approved document, names consignment_id among what
 *     Person 2 must receive from Person 1.
 *   - Adding a field to an event payload is additive and backward compatible;
 *     no existing consumer breaks.
 *   - Without it, Person 2's only route to the value is querying
 *     transport_trips directly — D-46's residual exposure.
 *
 * It is nullable, and that is not a defect: a trip may legitimately carry no
 * consignment (trips shipped before consignments existed, and TM-001 §4 rule 3
 * makes the container a search anchor rather than a mandatory parent).
 *
 * ── WHAT IS STILL DELIBERATELY ABSENT ─────────────────────────────────────
 * container_id and route/geofence context are ALSO named in TM-001 §11 and were
 * explicitly NOT approved. There is no container table yet and no route context
 * to send, and a field carrying null forever is worse than an absent one — a
 * consumer will code against it. They are to be asked for again when the
 * container table exists. See D-49.
 */
class TripCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public TransportTrip $trip,
    ) {
    }

    /**
     * EVT-002's LOCKED Payload Core, plus the one approved addition.
     *
     * trip_id and order_id are the registry's. consignment_id is the approved
     * third field — see the class docblock and D-49.
     */
    public function payload(): array
    {
        return [
            'trip_id'        => (int) $this->trip->id,
            'order_id'       => (int) $this->trip->order_id,
            'consignment_id' => $this->trip->consignment_id === null
                ? null
                : (int) $this->trip->consignment_id,
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
