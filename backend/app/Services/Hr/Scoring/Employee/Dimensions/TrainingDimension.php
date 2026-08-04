<?php

namespace App\Services\Hr\Scoring\Employee\Dimensions;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeTraining;
use App\Services\Hr\Scoring\Dimensions\DimensionResult;

/**
 * #39 — does this person finish the training they are assigned?
 *
 * Cancelled assignments are excluded from BOTH sides: training pulled by HR is
 * not something the employee failed to do, and counting it against them would
 * penalise an administrative decision.
 */
class TrainingDimension implements EmployeeDimension
{
    public const KEY = 'training';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Training Completion';
    }

    public function score(HrEmployee $employee, array $ctx): DimensionResult
    {
        $all = collect($ctx['trainings'] ?? [])
            ->reject(fn ($t) => $t->status === HrEmployeeTraining::CANCELLED);

        if ($all->isEmpty()) {
            return DimensionResult::unavailable(self::KEY, $this->label(),
                'No training has been assigned to this employee.');
        }

        $completed = $all->where('status', HrEmployeeTraining::COMPLETED);

        // An assignment past its due date and still open is the signal that
        // matters — it is the one the risk factors read.
        $overdue = $all->filter(fn ($t) => in_array($t->status, HrEmployeeTraining::ACTIVE, true)
            && $t->due_date && $t->due_date->isPast());

        $rate  = $completed->count() / $all->count();
        $score = $rate * 100;

        // Overdue items cost more than an incomplete-but-in-time one.
        $score -= min(30, $overdue->count() * 10);

        return DimensionResult::scored(self::KEY, $this->label(), $score,
            sprintf('%d of %d assigned trainings completed%s.',
                $completed->count(), $all->count(),
                $overdue->count() ? sprintf(', %d overdue', $overdue->count()) : ''),
            [
                'assigned'  => $all->count(),
                'completed' => $completed->count(),
                'overdue'   => $overdue->count(),
                'overdue_titles' => $overdue->take(5)
                    ->map(fn ($t) => $t->program?->title ?? 'Training #'.$t->id)->values()->all(),
            ]);
    }
}
