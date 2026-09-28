<?php

namespace App\Events\Transport;

use App\Models\Transport\TripCollection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * EVT-011 CollectionRecorded — a receipt has been recorded against a trip.
 *
 * Registry row, verbatim:
 *   Producer          Accounts/Collections
 *   Payload Core      receipt_id, invoice_id, amount
 *   Idempotency Key   receipt_id+posting_id
 *   Consumers         ControlRoom
 *   Financial Impact  Yes
 *   Audit             Yes
 *   Status            CONTROLLED
 *
 * ── TRANSPORT CANNOT FILL THIS PAYLOAD, AND THAT IS THE POINT ───────────
 * Three of the five fields are Accounts' vocabulary. A `receipt_id` and a
 * `posting_id` are accounting records that this module does not create and must
 * not invent — FORBID-002 and LOCK-004 keep Transport out of the books, so
 * there is nothing here to put in them.
 *
 * `invoice_id` we can supply, but only once Accounts has filled it in on the
 * linked `trip_bills` row (C-10). Before that it is genuinely null.
 *
 * So the payload below carries the registry's own field NAMES, populates the
 * two Transport legitimately knows, and leaves `receipt_id` null rather than
 * fabricating an identifier. It adds `collection_id` and `trip_id` alongside —
 * not instead — because a ControlRoom consumer reading a Transport-emitted
 * event needs something it can actually join on.
 *
 * ── SO WHO REALLY OWNS THIS EVENT? ──────────────────────────────────────
 * The registry contradicts itself and the contradiction is recorded as D-61.
 * API-011 is `/api/v1/transport/trips/{trip}/collection`, gated on
 * `transport.collection.record` — a Transport endpoint — and its Event Emitted
 * column names CollectionRecorded. But EVT-011's producer is
 * "Accounts/Collections" and its payload is pure accounting.
 *
 * Read together, the only coherent reading is that there are TWO acts: somebody
 * records against the receivable (Transport, tracking) and somebody posts the
 * receipt (Accounts, money). CTR-014's note "Posting event generated" says the
 * first triggers the second.
 *
 * This event is therefore emitted as the TRIGGER, with the fields Transport can
 * honestly supply. If Accounts would rather own the event outright and have
 * Transport emit nothing, that is a better answer and costs one deletion here —
 * it is the fourth question in D-61.
 *
 * ── A SEAM WITH NO LISTENERS, ON PURPOSE ────────────────────────────────
 * ControlRoom is SNG-TRN-019, Backlog. Same published-seam pattern as every
 * other Transport event: emitted from the day the state exists.
 */
class CollectionRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public TripCollection $collection,
        /** THIS receipt, not the running total — the registry's "amount". */
        public string $amount,
    ) {
    }

    /**
     * The registry's field names, honestly populated.
     *
     * `receipt_id` is null because Transport does not create receipts. A
     * consumer that needs one is waiting on Accounts, not on this module.
     */
    public function payload(): array
    {
        return [
            'receipt_id'    => null,
            'invoice_id'    => $this->collection->bill?->invoice_id,
            'amount'        => $this->amount,
            // Not in the registry row. Added because a consumer reading an
            // event whose first two fields may both be null needs something to
            // join on, and these are what Transport actually knows.
            'collection_id' => (int) $this->collection->id,
            'trip_id'       => (int) $this->collection->trip_id,
        ];
    }
}
