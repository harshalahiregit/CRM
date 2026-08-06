<?php

namespace App\Services\Hr\Scoring\Employee\Dimensions;

use App\Models\Hr\HrEmployee;
use App\Services\Hr\Scoring\Dimensions\DimensionResult;

/**
 * #39 — attendance over the scoring window.
 *
 * Only days the employee was EXPECTED counts. Holidays, weekends and approved
 * leave are removed from the denominator: someone who took approved leave has
 * not attended badly, and scoring them down for it would make the score punish
 * a policy the company itself grants.
 */
class AttendanceDimension implements EmployeeDimension
{
    public const KEY = 'attendance';

    /** Days that are not working days at all. */
    private const NON_WORKING = ['Holiday', 'Weekend'];

    /** Present in some form. Remote and WFH are attendance, not absence. */
    private const PRESENT = ['Present', 'Work From Home', 'Remote'];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Attendance';
    }

    public function score(HrEmployee $employee, array $ctx): DimensionResult
    {
        $rows = collect($ctx['attendance'] ?? [])
            ->reject(fn ($a) => in_array($a->status, self::NON_WORKING, true))
            // Approved leave is neither presence nor absence.
            ->reject(fn ($a) => $a->status === 'Leave');

        if ($rows->isEmpty()) {
            return DimensionResult::unavailable(self::KEY, $this->label(),
                'No attendance has been recorded for this employee in the scoring window.');
        }

        $present  = $rows->whereIn('status', self::PRESENT)->count();
        $halfDays = $rows->where('status', 'Half Day')->count();
        $late     = $rows->where('status', 'Late')->count();
        $absent   = $rows->where('status', 'Absent')->count();

        // A half day is half attended; a late arrival is attended but flagged.
        $effective = $present + ($halfDays * 0.5) + ($late * 0.9);
        $score     = ($effective / $rows->count()) * 100;

        return DimensionResult::scored(self::KEY, $this->label(), $score,
            sprintf('%d of %d working days attended (%d late, %d absent, %d half-day).',
                $present, $rows->count(), $late, $absent, $halfDays),
            [
                'working_days' => $rows->count(),
                'present'      => $present,
                'late'         => $late,
                'absent'       => $absent,
                'half_days'    => $halfDays,
                'absent_rate'  => round($absent / $rows->count() * 100, 1),
            ]);
    }
}
