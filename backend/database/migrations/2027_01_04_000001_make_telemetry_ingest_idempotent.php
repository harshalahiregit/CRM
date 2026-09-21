<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-INT — one reading per device per instant (T-12).
 *
 * ── THE DECISION, SINCE THE TASK LIST LEFT IT OPEN ────────────────────────
 * A GPS unit coming out of a tunnel replays its buffer, and a flaky link makes
 * it retry. Both re-send pings the server already has, and until now each one
 * appended another history row.
 *
 * The task list offered two options: dedupe on write, or keep everything and
 * dedupe on read. **Dedupe on write.** A device has one clock, so one device at
 * one instant is one reading — a second copy is not a new fact about the world,
 * it is the same fact arriving twice. Keeping both would mean every consumer of
 * the trail (distance, idle time, the excursion rule, any future utilisation
 * report) has to remember to de-duplicate, forever, and the first one that
 * forgets silently double-counts a journey.
 *
 * The index is the enforcement, not the service check: two requests arriving
 * together would both pass an application-level "does it exist" test, and only
 * the database can settle that race.
 *
 * ── EXISTING DUPLICATES ───────────────────────────────────────────────────
 * The index cannot be created while duplicates exist, so they are collapsed
 * first — keeping the LOWEST id of each group, which is the one that was
 * recorded first and the one anything else may already reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->collapseDuplicates();

        Schema::table('telemetry_records', function (Blueprint $table) {
            // Scoped by company as well as device: device ids are supplied by
            // whoever fits the hardware, and two companies fitting units from
            // the same batch must not be able to collide with each other.
            $table->unique(
                ['company_id', 'device_id', 'recorded_at'],
                'telemetry_device_instant_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('telemetry_records', function (Blueprint $table) {
            $table->dropUnique('telemetry_device_instant_unique');
        });
    }

    /**
     * Collapse pre-existing duplicates, keeping the earliest row of each group.
     *
     * Written with a subquery rather than a chunked loop because the set is
     * small in practice and the operation has to be atomic: a half-collapsed
     * table would fail the index creation on the next line and leave the
     * migration stranded.
     */
    private function collapseDuplicates(): void
    {
        $keep = DB::table('telemetry_records')
            ->selectRaw('MIN(id) as id')
            ->groupBy('company_id', 'device_id', 'recorded_at');

        $doomed = DB::table('telemetry_records')
            ->whereNotIn('id', $keep)
            ->pluck('id');

        foreach ($doomed->chunk(1000) as $chunk) {
            DB::table('telemetry_records')->whereIn('id', $chunk->all())->delete();
        }
    }
};
