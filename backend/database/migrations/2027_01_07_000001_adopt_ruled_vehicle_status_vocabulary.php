<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-FLEET — the vehicle asset state machine, as ruled (T-02 / T-51).
 *
 * The owner has ruled the vocabulary and named Fleet as the sole authority for
 * the vehicle asset state machine:
 *
 *   AVAILABLE · ALLOCATED · IN_TRANSIT · UNDER_MAINTENANCE
 *   COMPLIANCE_BLOCKED · IDLE · BREAKDOWN · RETIRED
 *
 * This closes T-02 and the vehicle half of T-51, which had been blocked on a
 * team answer since M2.
 *
 * ── TWO REAL CHANGES, NOT JUST A RENAME ───────────────────────────────────
 *
 * 1. `in_operation` SPLITS into ALLOCATED and IN_TRANSIT. Fleet had one value
 *    for "out on a trip", but the trip lifecycle distinguishes a truck that has
 *    been assigned from one that has actually departed, and a planner needs to
 *    know which — an allocated truck can still be swapped, a departed one is a
 *    recovery problem. Existing rows become ALLOCATED, the weaker claim: it is
 *    recoverable by dispatching, whereas calling a yard truck IN_TRANSIT would
 *    have to be discovered.
 *
 * 2. COMPLIANCE_BLOCKED becomes a status. It is NOT hand-typed: compliance is
 *    derived from the five statutory expiry dates and that stays the one truth.
 *    `stos:refresh-compliance` applies and clears this state, exactly as job
 *    cards are the only thing that apply UNDER_MAINTENANCE. It is applied only
 *    to a vehicle that is otherwise free — yanking a truck mid-trip because a
 *    PUC lapsed would strand a load rather than prevent a journey that has
 *    already started.
 *
 * ── WHY UPPERCASE IS WORTH THE CHURN ──────────────────────────────────────
 * These strings cross a module boundary. Dev 1's board and Dev 3's billing
 * switch on them, and two spellings of the same state is how a condition gets
 * tested for and silently never matches.
 */
return new class extends Migration
{
    /** Old value => ruled value. */
    private const MAP = [
        'active'         => 'AVAILABLE',
        'in_operation'   => 'ALLOCATED',
        'in_maintenance' => 'UNDER_MAINTENANCE',
        'breakdown'      => 'BREAKDOWN',
        'idle'           => 'IDLE',
        'retired'        => 'RETIRED',
    ];

    public function up(): void
    {
        // COMPLIANCE_BLOCKED is 18 characters; the column was sized for the
        // lowercase vocabulary.
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('status', 24)->default('AVAILABLE')->change();
        });

        foreach (self::MAP as $old => $new) {
            DB::table('vehicles')->where('status', $old)->update(['status' => $new]);
        }

        // Anything that is not in the map is a value nobody declared. Left
        // alone and reported rather than guessed at: silently folding an
        // unknown state into AVAILABLE could put a truck nobody cleared back
        // into allocation.
        $unknown = DB::table('vehicles')
            ->whereNotIn('status', array_values(self::MAP))
            ->where('status', '!=', 'COMPLIANCE_BLOCKED')
            ->where('status', '!=', 'IN_TRANSIT')
            ->pluck('status', 'id');

        if ($unknown->isNotEmpty()) {
            logger()->channel('stos')->warning('Vehicles carry a status outside the ruled vocabulary', [
                'vehicles' => $unknown->all(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('vehicles')->where('status', $new)->update(['status' => $old]);
        }

        // The split cannot be undone faithfully — both halves collapse back to
        // the single value they came from.
        DB::table('vehicles')->where('status', 'IN_TRANSIT')->update(['status' => 'in_operation']);
        DB::table('vehicles')->where('status', 'COMPLIANCE_BLOCKED')->update(['status' => 'active']);

        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->change();
        });
    }
};
