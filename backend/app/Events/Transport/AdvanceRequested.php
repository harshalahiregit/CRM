<?php

namespace App\Events\Transport;

use App\Models\Transport\TripAdvance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * EVT-006 AdvanceRequested — money has been asked for against a trip.
 *
 * Registry row, verbatim:
 *   Producer          AdvanceService
 *   Payload Core      advance_id, trip_id, amount
 *   Idempotency Key   advance_id+version
 *   Consumers         ApprovalService, Notifications
 *   Financial Impact  Potential
 *   Audit             Yes
 *   Status            CONTROLLED
 *
 * ── A SEAM WITH NO LISTENERS, ON PURPOSE ──────────────────────────────────
 * Neither consumer subscribes yet. ApprovalService is the shared Sangoe
 * approval engine that MAM §33 says Transport must reuse rather than
 * reimplement, and nothing has wired Transport to it; Notifications is
 * SNG-TRN-021, which is Backlog. Same published-seam pattern as TripCreated and
 * TripAssigned: the event is emitted from the day the state exists, so a
 * consumer added later needs no change here.
 *
 * ── "FINANCIAL IMPACT: POTENTIAL" IS NOT "FINANCIAL IMPACT" ──────────────
 * A request is not a posting. LOCK-004 and FORBID-002 forbid Transport writing
 * ledger lines, and this event does not ask anybody to — it says somebody wants
 * money, which is why the registry marks it Potential rather than Yes. The
 * accounting event belongs at payment, which is TRP-P0-008 and not this ticket.
 *
 * ── IDEMPOTENCY ──────────────────────────────────────────────────────────
 * The registry key is advance_id+version. `trip_advances` has no version column
 * — FLD-012 specifies only `status` — so the id alone identifies it today, and
 * a consumer that needs to deduplicate can use it. Recorded rather than solved:
 * adding a column the field registry does not list would be FORBID-005.
 */
class AdvanceRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(public TripAdvance $advance)
    {
    }

    /** The registry's three payload fields, and nothing beyond them. */
    public function payload(): array
    {
        return [
            'advance_id' => (int) $this->advance->id,
            'trip_id'    => (int) $this->advance->trip_id,
            'amount'     => (string) $this->advance->amount_requested,
        ];
    }
}
