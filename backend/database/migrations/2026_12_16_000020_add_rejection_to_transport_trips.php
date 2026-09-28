<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STT-003's rejection reason — `viability_pending → draft`.
 *
 *   STT-003  viability_pending → draft | trigger "Reject for correction"
 *            | actor Operations | precondition "Rejection reason"
 *            | side effect "Return to edit" | audited | LOCKED
 *
 * ── ONE COLUMN, AND WHY IT IS A COLUMN AND NOT JUST AN AUDIT ROW ─────────
 * The audit trail already records who rejected and when, so those are not
 * duplicated here. The REASON is different: STT-003's side effect is "Return to
 * edit", and the person doing the editing has to see what to fix. A reason
 * readable only by opening the history is a reason most people will not read.
 *
 * It is cleared when the trip is resubmitted (see
 * TransportTripService::submitForViability) so a trip that went round the loop
 * and was approved does not still display the objection it answered. The audit
 * keeps the history; this column holds only the OUTSTANDING objection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_trips', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('transport_trips', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });
    }
};
