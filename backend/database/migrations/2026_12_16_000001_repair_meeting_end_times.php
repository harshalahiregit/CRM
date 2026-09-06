<?php

use App\Support\Shared\BusinessTime;
use App\Support\Shared\MeetingTiming;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair meeting end times that no longer agree with their own duration.
 *
 * Rescheduling a meeting moved only `scheduled_at`. `end_at` stayed on the old
 * absolute time, so a 09:00-10:00 meeting dragged to 17:06 kept ending at 10:00
 * THE NEXT DAY — seventeen hours long by the clock, still "60 minutes" in its
 * own duration column, and reported as in progress long after everyone had gone
 * home. The write path is fixed (the end now travels with the start); this
 * repairs the rows already written that way.
 *
 * Two cases are fixed, in both engines:
 *
 *   • end_at IS NULL — every row written before the column existed. Without an
 *     end these meetings could never expire.
 *   • end_at disagrees with duration_minutes by more than a minute — the stale
 *     end left behind by a reschedule.
 *
 * Where they disagree the DURATION is believed, because it is the value that
 * was computed when the meeting was created from the start and end the person
 * actually typed; the end is the half a later reschedule left behind.
 *
 * A row whose end and duration already agree is not touched, and neither is a
 * meeting with no start — there is nothing to derive an end from.
 */
return new class extends Migration
{
    private const TABLES = ['kickoff_meetings', 'purchase_kickoff_meetings'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::table($table)
                ->whereNotNull('scheduled_at')
                ->orderBy('id')
                ->select('id', 'tenant_id', 'scheduled_at', 'end_at', 'duration_minutes')
                ->chunk(200, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        $repaired = $this->repairedEnd($row);
                        if ($repaired !== null) {
                            DB::table($table)->where('id', $row->id)->update([
                                'end_at'           => $repaired,
                                'duration_minutes' => $row->duration_minutes ?: MeetingTiming::FALLBACK_MINUTES,
                            ]);
                        }
                    }
                });
        }
    }

    /** The corrected end, or null when the row is already consistent. */
    private function repairedEnd(object $row): ?string
    {
        $start = BusinessTime::parse($row->scheduled_at, $row->tenant_id ?? null);
        if (! $start) {
            return null;
        }

        $minutes = (int) ($row->duration_minutes ?: MeetingTiming::FALLBACK_MINUTES);
        $end     = BusinessTime::parse($row->end_at, $row->tenant_id ?? null);

        // Already agrees (within the rounding a stored second can introduce).
        if ($end && abs($start->diffInMinutes($end, false) - $minutes) <= 1) {
            return null;
        }

        return $start->copy()->addMinutes(max(1, $minutes))->format('Y-m-d H:i:s');
    }

    /**
     * Deliberately irreversible. The values replaced were wrong — a down()
     * would have to put the inconsistency back, and there is nothing to restore
     * it from.
     */
    public function down(): void
    {
    }
};
