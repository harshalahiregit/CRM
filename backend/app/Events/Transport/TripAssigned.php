<?php

namespace App\Events\Transport;

use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripAssignment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * EVT-005 TripAssigned — fired when a trip becomes fully crewed.
 *
 * Registry row, verbatim:
 *   Producer         AssignmentService
 *   Payload Core     trip_id, vehicle_id, driver_id
 *   Idempotency Key  trip_id+assignment_id
 *   Consumers        Dispatch, Notifications
 *   Financial Impact No
 *   Audit            Yes
 *   Status           CONTROLLED
 *
 * ── A SEAM WITH NO LISTENERS, ON PURPOSE ──────────────────────────────────
 * Neither consumer exists: Dispatch is SNG-TRN-010 and Notifications is
 * SNG-TRN-021 (P1). This follows the pattern App\Events\Accounts\VoucherPosted
 * already sets in this codebase — "a designed-for seam, no Phase 1 listeners" —
 * so the contract is published now and a later ticket subscribes without
 * AllocationService changing.
 *
 * ── PAYLOAD IS EXACTLY THE THREE FIELDS ───────────────────────────────────
 * payload() returns trip_id, vehicle_id and driver_id and nothing else, because
 * that is what the registry's Payload Core column lists. The models are carried
 * on the event for a listener's convenience, but the wire payload is not widened
 * — an event contract that quietly grows is one consumers cannot rely on.
 *
 * assignment_id is exposed separately via idempotencyKey(), not as payload: the
 * registry uses it to build "trip_id+assignment_id", which is how a consumer
 * recognises a redelivery of the same allocation.
 */
class TripAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public TransportTrip $trip,
        public TripAssignment $assignment,
        /** The eligibility verdicts that permitted this allocation. Audit context, not payload. */
        public array $evidence = [],
    ) {
    }

    /** EVT-005 Payload Core, exactly. */
    public function payload(): array
    {
        return [
            'trip_id'    => (int) $this->trip->id,
            'vehicle_id' => $this->assignment->vehicle_id === null ? null : (int) $this->assignment->vehicle_id,
            'driver_id'  => $this->assignment->driver_id === null ? null : (int) $this->assignment->driver_id,
        ];
    }

    /** EVT-005 Idempotency Key — "trip_id+assignment_id". */
    public function idempotencyKey(): string
    {
        return $this->trip->id.'+'.$this->assignment->id;
    }

    /** Tenancy travels with the event; a listener must not have to infer it. */
    public function tenantId(): int
    {
        return (int) $this->trip->tenant_id;
    }
}
