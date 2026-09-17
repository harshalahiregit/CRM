<?php

namespace App\Events\Transport;

use App\Models\Transport\TripDocument;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * EVT-009 PODReceived — proof of delivery has arrived against a trip.
 *
 * Registry row, verbatim:
 *   Producer          DocumentEngine
 *   Payload Core      trip_id, attachment_id
 *   Idempotency Key   attachment_id
 *   Consumers         BillingEngine, Notifications
 *   Financial Impact  No
 *   Audit             Yes
 *   Status            CONTROLLED
 *
 * ── `attachment_id` IS THIS ROW'S ID ─────────────────────────────────────
 * The registry names the payload field `attachment_id` and there is no
 * attachments table in the DB registry — DB-009 `trip_documents` is the
 * "LR/POD/EWB/attachments index", so the attachment IS the trip_documents row.
 * Emitting its id under the registry's field name rather than renaming the
 * field: a consumer written against Step 11 gets the key it was promised.
 *
 * ── EMITTED ON RECEIPT, NOT ON VERIFICATION ──────────────────────────────
 * The event is PODReceived and the registry means it. Delivery of the file is
 * the fact being announced; whether it is any good is STT-008's separate
 * decision, which moves the trip to pod_verified. Firing this on verification
 * instead would leave Notifications unable to tell anybody that a POD is
 * waiting to be checked — which is the one thing they need to know.
 *
 * ── A SEAM WITH NO LISTENERS, ON PURPOSE ─────────────────────────────────
 * Neither consumer subscribes yet. BillingEngine is SNG-TRN-015, which this
 * ticket unblocks rather than contains; Notifications is SNG-TRN-021, Backlog.
 * Same published-seam pattern as TripCreated, TripAssigned and AdvanceRequested:
 * emitted from the day the state exists, so a consumer added later needs no
 * change here.
 *
 * ── IDEMPOTENCY ──────────────────────────────────────────────────────────
 * The registry key is `attachment_id` alone, and unlike EVT-006 that key is
 * fully available: the row id identifies it with no version component missing.
 * A consumer can deduplicate exactly as Step 11 specifies.
 */
class PodReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(public TripDocument $document)
    {
    }

    /** The registry's two payload fields, and nothing beyond them. */
    public function payload(): array
    {
        return [
            'trip_id'       => (int) $this->document->trip_id,
            'attachment_id' => (int) $this->document->id,
        ];
    }
}
