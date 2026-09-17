<?php

namespace App\Events\Transport;

use App\Models\Transport\TripBill;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * BillingPrepared — a trip has passed its billing preconditions.  SNG-TRN-015.
 *
 * ── THIS EVENT IS NAMED BY API-010 AND REGISTERED NOWHERE ────────────────
 * API-010's "Event Emitted" column reads `BillingPrepared` exactly:
 *
 *   API-010 | POST /api/v1/transport/trips/{trip}/bill | Prepare customer billing
 *           | transport.billing.prepare | emits BillingPrepared | CONTROLLED
 *
 * The Event_Registry has twelve rows, EVT-001..012, and **BillingPrepared is
 * not one of them**. So the API registry promises an event the event registry
 * never defines: no producer, no payload, no idempotency key, no consumer list.
 * Recorded as D-60.
 *
 * The payload below is therefore constructed, and deliberately mirrors the
 * shape of the events that ARE registered around it — an id, the trip, and the
 * one figure the consumer needs — rather than inventing a richer contract
 * nobody asked for. `bill_id` is the idempotency key by the same reasoning
 * EVT-009 uses for `attachment_id`.
 *
 * ── WHAT THIS EVENT IS AND IS NOT SAYING ─────────────────────────────────
 * It says: Transport has checked its preconditions and this trip may be
 * invoiced for this amount. It does NOT say an invoice exists — that is EVT-010
 * `InvoicePosted`, whose producer is **Accounts**, and it is emitted by them
 * after they raise it. Transport writes no ledger line, ever (FORBID-002,
 * LOCK-004).
 *
 * ── A SEAM WITH NO LISTENERS, ON PURPOSE ─────────────────────────────────
 * Nothing subscribes yet — the Accounts bridge is SNG-TRN-024, which is
 * Backlog. Same published-seam pattern as TripCreated, AdvanceRequested and
 * PodReceived: emitted from the day the state exists, so the consumer added
 * later needs no change here.
 */
class BillingPrepared
{
    use Dispatchable, SerializesModels;

    public function __construct(public TripBill $bill)
    {
    }

    /** Constructed — see the class docblock. Kept to what a biller needs. */
    public function payload(): array
    {
        return [
            'bill_id'  => (int) $this->bill->id,
            'trip_id'  => (int) $this->bill->trip_id,
            'amount'   => (string) $this->bill->billable_amount,
            'currency' => (string) $this->bill->currency,
        ];
    }
}
