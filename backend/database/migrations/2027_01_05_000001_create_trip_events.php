<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `trip_events` — STOS-DB §37, and the spine of the Digital Passport.
 *
 *   §37  "Do not overwrite every historical status. Maintain: trip_events."
 *   §38  "Current status = latest valid state. History = events."
 *
 * ── STEP 11 HAS NO ROW FOR THIS TABLE — D-113 ───────────────────────────
 * The canonical registry's DB_Registry runs DB-001…DB-020 and contains no
 * events table, no field rows, no API row and no permission row — while
 * STOS-DB names it outright and STOS-CTD builds the Passport on it across §§31,
 * 32, 33, 34, 35, 101 and 133.
 *
 * So every column below cites the line it comes from. That citation is what
 * stands in for the registry row that does not exist, and it is the only reason
 * building this is not inventing it.
 *
 * ── WHY THIS IS NOT transport_audit_logs ────────────────────────────────
 * Three reasons, and the third is the one that matters.
 *
 *   1. An audit row records a FIELD CHANGE — who changed what, from what, to
 *      what. "Genset ON", "Port Entry" and "Feedback received" are not field
 *      changes on a Transport row; they are things that happened.
 *   2. Audit rows hang off auditable_type + auditable_id, a Transport model. A
 *      GPS ping and an accounting posting have no Transport model to hang off.
 *   3. IT IS OURS, AND HALF THESE EVENTS ARE NOT. Of CTD §31's sixteen example
 *      entries, seven belong to P2 or P3. For them to contribute through the
 *      audit log they would have to write into the record of what WE did.
 *
 * A shared table with a documented contract is the only shape that lets three
 * people fill one timeline without any of them reaching into another's service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            /* ── The chain. All nullable, all indexed. ────────────────────
             *
             * `trip_id` is nullable and that is not laziness: CTD §31's own
             * example opens with "08:10 Order Approved", which happens BEFORE a
             * trip exists — a trip is created from an approved order.
             *
             * order/consignment/container are denormalised on write so the
             * Container Passport can ask for one container's events without
             * joining through three tables. CTD §133 composes the Passport from
             * the chain, and a timeline that needs a three-table join to render
             * is a timeline that will be rendered slowly or not at all.
             */
            $table->unsignedBigInteger('trip_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('consignment_id')->nullable();
            $table->unsignedBigInteger('container_id')->nullable();

            // STOS-DB §37's examples. An OPEN column with a declared registry —
            // see TripEventType for why a locked enum would make this table
            // useless to the two people who own half the events.
            $table->string('event_type', 60);

            // CTD §101's nine branches of the stream.
            $table->string('category', 20);

            // CTD §32: "each event should identify its source". CTD §33's ten.
            $table->string('source', 20);

            /* ── Two times, because they are two facts. STOS-DB §19. ──────
             *
             * `occurred_at` is when it HAPPENED. `recorded_at` is when we heard.
             * A telemetry batch buffered for an hour has both, and CTD §31 is a
             * chronology of the first — a timeline ordered by the second would
             * put an hour of driving after the delivery that followed it.
             */
            $table->dateTime('occurred_at');
            $table->dateTime('recorded_at');

            // The line the timeline shows.
            $table->string('summary', 190);

            // Whatever the producer wants to carry. Nobody else parses it —
            // that is the contract, and it is what keeps three sections from
            // needing to agree on a shape none of the documents defines.
            $table->json('detail')->nullable();

            // CTD §33's USER and DRIVER sources. The name is denormalised, as
            // the audit log already does, so a departed employee does not erase
            // who did what.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 190)->nullable();
            $table->string('actor_role', 60)->nullable();

            /* ── CTD §34 — EVENT IMMUTABILITY ────────────────────────────
             * "Historical events should not be silently edited. Corrections
             * should create a Correction Event with audit trail."
             *
             * So a correction is a NEW row pointing at the one it corrects, and
             * the model refuses update and delete outright.
             */
            $table->unsignedBigInteger('corrects_event_id')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            // NO updated_at and NO deleted_at, deliberately. An append-only
            // table with an updated_at invites somebody to use it.

            /* ── Indexes: one per question the Passport actually asks ──── */
            $table->index(['tenant_id', 'trip_id', 'occurred_at'], 'trip_events_trip_idx');
            $table->index(['tenant_id', 'container_id', 'occurred_at'], 'trip_events_container_idx');
            $table->index(['tenant_id', 'consignment_id', 'occurred_at'], 'trip_events_consignment_idx');
            // CTD §35's filters.
            $table->index(['tenant_id', 'category', 'occurred_at'], 'trip_events_category_idx');
            $table->index('corrects_event_id', 'trip_events_corrects_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_events');
    }
};
