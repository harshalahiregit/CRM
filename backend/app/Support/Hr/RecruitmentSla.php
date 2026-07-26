<?php

namespace App\Support\Hr;

use Carbon\CarbonInterface;

/**
 * Recruitment Services — SLA targets and Red/Yellow/Green evaluation (Phase 3).
 *
 * Pure domain helper: given a start, an optional completion time, and a target
 * window, it reports elapsed time and a traffic-light status. No persistence,
 * no duplication of recruitment logic — it just interprets timestamps that the
 * hiring-request lifecycle already records.
 */
class RecruitmentSla
{
    /** Target windows, in days. */
    public const ASSIGNMENT_DAYS      = 2;   // request created → recruiter assigned
    public const FIRST_CANDIDATE_DAYS = 5;   // recruiter assigned → first candidate delivered
    public const CLOSURE_DAYS         = 30;  // request created → closed / completed

    public const GREEN  = 'green';
    public const YELLOW = 'yellow';
    public const RED    = 'red';

    /**
     * Evaluate one SLA line.
     *
     * @param  CarbonInterface|null  $start  when the clock started
     * @param  CarbonInterface|null  $done   when the milestone was met (null = still pending)
     * @param  int                   $targetDays  the SLA window
     * @return array{status:string,elapsed_days:?float,target_days:int,met:?bool,pending:bool}
     */
    public static function evaluate(?CarbonInterface $start, ?CarbonInterface $done, int $targetDays): array
    {
        if (! $start) {
            return ['status' => self::YELLOW, 'elapsed_days' => null, 'target_days' => $targetDays, 'met' => null, 'pending' => true];
        }

        $end = $done ?? now();
        $elapsed = round(max(0, $end->getTimestamp() - $start->getTimestamp()) / 86400, 1);

        if ($done) {
            $met = $elapsed <= $targetDays;

            return [
                'status'       => $met ? self::GREEN : self::RED,
                'elapsed_days' => $elapsed,
                'target_days'  => $targetDays,
                'met'          => $met,
                'pending'      => false,
            ];
        }

        // Still running: warn before the deadline, breach after it.
        $ratio = $targetDays > 0 ? $elapsed / $targetDays : 1;
        $status = $ratio >= 1 ? self::RED : ($ratio >= 0.75 ? self::YELLOW : self::GREEN);

        return ['status' => $status, 'elapsed_days' => $elapsed, 'target_days' => $targetDays, 'met' => null, 'pending' => true];
    }
}
