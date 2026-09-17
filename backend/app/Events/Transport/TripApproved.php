<?php

namespace App\Events\Transport;

use App\Models\Transport\TransportTrip;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * EVT-004 TripApproved — STT-002's side effect, "Emit TripApproved".
 *
 * Registry row, verbatim:
 *   Producer         ApprovalService
 *   Payload Core     trip_id, approved_by
 *   Idempotency Key  trip_id+approval_id
 *   Consumers        TripEngine, Notifications
 *   Financial Impact Potential
 *   Audit            Yes
 *   Status           LOCKED
 *
 * ── A SEAM WITH NO LISTENERS, ON PURPOSE ──────────────────────────────────
 * Neither consumer subscribes yet. Same published-seam pattern as EVT-001 and
 * EVT-002: emit-only, no outbox, no new mechanism.
 *
 * ── THE IDEMPOTENCY KEY CANNOT BE HONOURED AS SPECIFIED — D-60 ───────────
 * The registry keys this on `trip_id+approval_id`. **There is no approvals
 * table in Step 11's DB_Registry and no field registry entry defines
 * `approval_id`.** The approval is recorded on the trip itself — `approved_by`
 * and `approved_at` — following `dispatched_by`/`dispatched_at`, which already
 * ship on that table.
 *
 * Nothing is substituted for the missing id. Not the audit-log row id, not a
 * uuid, not the trip id repeated. A fabricated approval_id would satisfy a
 * consumer's de-duplication while keying on something the registry never meant,
 * and would surface as a silently dropped event long after anyone remembers
 * this decision. `approved_at` stands in — it is the fact that actually exists,
 * and it changes when the approval changes.
 *
 * ── `approved_by` IS WHY THIS IS A DECISION AND NOT A CALCULATION ────────
 * A margin computation has no approver. The payload names one, PERM-003 denies
 * the Dispatcher, and the key names an approval record: three independent
 * signals that STT-002 is a human act. See D-58.
 *
 * ── WHAT THE APPROVAL DID NOT CHECK ──────────────────────────────────────
 * STT-002's LOCKED precondition is "Margin policy passed". It is NOT enforced.
 * A consumer must not read this event as evidence that a trip is commercially
 * viable — only that an authorised person approved it. See D-59.
 */
class TripApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public TransportTrip $trip,
    ) {
    }

    /** EVT-004's LOCKED Payload Core, and nothing beyond it. */
    public function payload(): array
    {
        return [
            'trip_id'     => (int) $this->trip->id,
            'approved_by' => $this->trip->approved_by === null
                ? null
                : (int) $this->trip->approved_by,
        ];
    }

    /**
     * EVT-004 Idempotency Key — specified as "trip_id+approval_id".
     *
     * `approval_id` has no table (D-60), so `approved_at` stands in. It is the
     * fact that exists and it moves when the approval does.
     */
    public function idempotencyKey(): string
    {
        return $this->trip->id.'+'.($this->trip->approved_at?->getTimestamp() ?? 0);
    }

    /** Tenancy travels with the event; a listener must not have to infer it. */
    public function tenantId(): int
    {
        return (int) $this->trip->tenant_id;
    }
}
