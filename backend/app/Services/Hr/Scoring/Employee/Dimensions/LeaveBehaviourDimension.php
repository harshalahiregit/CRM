<?php

namespace App\Services\Hr\Scoring\Employee\Dimensions;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrLeaveApplication;
use App\Services\Hr\Scoring\Dimensions\DimensionResult;

/**
 * #39 — how leave is TAKEN, not how much.
 *
 * Taking leave is an entitlement and is never scored down. What this measures is
 * process: leave applied for and approved, versus leave rejected or taken as
 * unplanned absence. An employee who uses their full allowance properly scores
 * full marks here.
 */
class LeaveBehaviourDimension implements EmployeeDimension
{
    public const KEY = 'leave_behaviour';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Leave Behaviour';
    }

    public function score(HrEmployee $employee, array $ctx): DimensionResult
    {
        $apps = collect($ctx['leave'] ?? [])
            ->reject(fn ($l) => in_array($l->status, [HrLeaveApplication::DRAFT, HrLeaveApplication::CANCELLED], true));

        if ($apps->isEmpty()) {
            return DimensionResult::unavailable(self::KEY, $this->label(),
                'No leave applications on record — nothing to assess.');
        }

        $approved = $apps->where('status', HrLeaveApplication::APPROVED)->count();
        $rejected = $apps->where('status', HrLeaveApplication::REJECTED)->count();

        // Rejections usually mean leave taken without notice or outside policy.
        $score = ($approved / $apps->count()) * 100;

        return DimensionResult::scored(self::KEY, $this->label(), $score,
            sprintf('%d of %d leave applications approved%s.',
                $approved, $apps->count(),
                $rejected ? sprintf(', %d rejected', $rejected) : ''),
            [
                'applications' => $apps->count(),
                'approved'     => $approved,
                'rejected'     => $rejected,
                'days_taken'   => round((float) $apps->where('status', HrLeaveApplication::APPROVED)->sum('days'), 1),
            ]);
    }
}
