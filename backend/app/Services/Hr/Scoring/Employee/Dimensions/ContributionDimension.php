<?php

namespace App\Services\Hr\Scoring\Employee\Dimensions;

use App\Models\Hr\HrEmployee;
use App\Services\Hr\Scoring\Dimensions\DimensionResult;

/**
 * #39 — delivery on Projects and Tasks.
 *
 * Reads the SAME link EmployeeLifecycleService uses: hr_employees.user_id joined
 * to project/task membership. That link is optional in this product — an
 * employee who has never been given a login has no tasks to measure, which is a
 * missing link, not poor delivery.
 *
 * So an unlinked employee returns `unavailable()` and is dropped from the
 * weighted average with the reason stated. Scoring them zero would rank every
 * employee without a login as the worst in the company.
 */
class ContributionDimension implements EmployeeDimension
{
    public const KEY = 'contribution';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Project & Task Contribution';
    }

    public function score(HrEmployee $employee, array $ctx): DimensionResult
    {
        if (empty($employee->user_id)) {
            return DimensionResult::unavailable(self::KEY, $this->label(),
                'This employee has no linked user account, so their project and task work cannot be attributed.');
        }

        $tasks = collect($ctx['tasks'] ?? []);

        if ($tasks->isEmpty()) {
            return DimensionResult::unavailable(self::KEY, $this->label(),
                'No tasks have been assigned to this employee.');
        }

        // `date_finished` is the reliable "done" signal — `status` is a free-form
        // slug that each workspace configures differently.
        $done = $tasks->filter(fn ($t) => ! empty($t->date_finished));

        $overdue = $tasks->filter(fn ($t) => empty($t->date_finished)
            && ! empty($t->due_date) && \Carbon\Carbon::parse($t->due_date)->isPast());

        $score = ($done->count() / $tasks->count()) * 100;
        $score -= min(25, $overdue->count() * 5);

        return DimensionResult::scored(self::KEY, $this->label(), $score,
            sprintf('%d of %d assigned tasks completed%s.',
                $done->count(), $tasks->count(),
                $overdue->count() ? sprintf(', %d overdue', $overdue->count()) : ''),
            [
                'assigned'  => $tasks->count(),
                'completed' => $done->count(),
                'overdue'   => $overdue->count(),
            ]);
    }
}
