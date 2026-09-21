<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-116 — a record of which rows were repointed, and which could not be.
 *
 * ── WHY A LEDGER AND NOT A FLAG ───────────────────────────────────────────
 * `transport_trips.vehicle_id` holds a `transport_vehicles` id today and a
 * `vehicles` id after `stos:repoint-trip-fleet-refs --apply`. Two id spaces,
 * no foreign key, and nothing in the row saying which one the number is in.
 *
 * Person 1 showed what that costs: a trip pointing at a legacy id that no
 * longer exists, where the same number happens to be a live Fleet id, reads as
 * a match. The plate cannot settle it either — looking the number up in Fleet's
 * own table compares a vehicle's plate against itself and always agrees.
 *
 * A single "the repoint has run" flag does not settle it, because the rows the
 * repoint COULD NOT map are exactly the dangerous ones and they are still
 * sitting in the old space afterwards.
 *
 * So the repoint writes down its verdict on every row it looked at:
 *
 *   to_id = <fleet id>   this row was moved, and this is what it now means
 *   to_id = NULL         this row pointed at something that no longer exists;
 *                        it was left alone and must never be matched
 *
 * A row with no entry at all was created after the switch and is therefore in
 * the new space. Every case is then answerable from data rather than inferred.
 *
 * It also makes the repoint reversible, which matters for an operation nobody
 * can undo from the rows themselves once it has run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fleet_reference_repoints')) {
            return;
        }

        Schema::create('fleet_reference_repoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();

            // Which reference was rewritten — `transport_trips.vehicle_id` and
            // the three others the repoint touches.
            $table->string('table_name', 64);
            $table->string('column_name', 64);
            $table->unsignedBigInteger('row_id');

            $table->unsignedBigInteger('from_id');
            $table->unsignedBigInteger('to_id')->nullable();

            $table->timestamp('applied_at')->useCurrent();

            // One verdict per reference. Re-running the repoint therefore skips
            // what it has already ruled on as a FACT, rather than relying on a
            // repointed id happening not to collide with a legacy one.
            $table->unique(['table_name', 'column_name', 'row_id'], 'fleet_repoints_ref_uniq');
            $table->index(['table_name', 'column_name', 'to_id'], 'fleet_repoints_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_reference_repoints');
    }
};
