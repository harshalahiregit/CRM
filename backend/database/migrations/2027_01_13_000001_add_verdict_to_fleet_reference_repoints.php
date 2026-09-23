<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger could describe two outcomes, and there are three — D-131.
 *
 * `to_id` carried the verdict implicitly: set meant moved, null meant
 * "stranded", which the command reports as *"points at a legacy row with no
 * Fleet counterpart"*.
 *
 * `trip_advances` #2 holds `driver_id = 1212010`. That value is in no table
 * anywhere — not a legacy driver, not a driver_profile, not a user. It never
 * pointed at a legacy row, so the stranded sentence is not a softer version of
 * the truth about it, it is a different claim that happens to be false. And
 * every downstream reader would then refuse the row on a stated ground that was
 * not the real one.
 *
 * A migration tool that has to describe bad data will eventually meet data none
 * of its descriptions fit, and the temptation is to use the nearest one. The
 * nearest one is a lie. So the ledger gets a third word instead.
 *
 *   moved            the reference was rewritten onto the Fleet id
 *   unmapped_legacy  a real legacy row, with no Fleet counterpart yet
 *   never_valid      the value is in no legacy table at all — not a mapping
 *                    that failed, a reference that was never valid
 *
 * The distinction is not cosmetic: `unmapped_legacy` is fixed by finishing the
 * migration, `never_valid` is fixed by somebody correcting the row, and those
 * are different people's work.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fleet_reference_repoints') || Schema::hasColumn('fleet_reference_repoints', 'verdict')) {
            return;
        }

        Schema::table('fleet_reference_repoints', function (Blueprint $table) {
            $table->string('verdict', 24)->after('to_id')->nullable();
            $table->index(['table_name', 'verdict'], 'fleet_repoints_verdict_index');
        });

        // Any row written before this column existed can be classified from
        // what it already carries, because the old code only had two outcomes.
        // Backfilled rather than defaulted so the column is never silently
        // wrong about history it did not witness.
        DB::table('fleet_reference_repoints')->whereNull('verdict')->update([
            'verdict' => DB::raw("CASE WHEN to_id IS NULL THEN 'unmapped_legacy' ELSE 'moved' END"),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('fleet_reference_repoints', 'verdict')) {
            return;
        }

        Schema::table('fleet_reference_repoints', function (Blueprint $table) {
            $table->dropIndex('fleet_repoints_verdict_index');
            $table->dropColumn('verdict');
        });
    }
};
