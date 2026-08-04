<?php

namespace App\Services\Hr\Scoring\Employee\Dimensions;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeProbation;
use App\Services\Hr\Scoring\Dimensions\DimensionResult;

/**
 * #39 — how probation went.
 *
 * Confirmed on time is the expected outcome and scores full. Extensions are the
 * signal: each one means someone judged the employee not yet ready.
 */
class ProbationDimension implements EmployeeDimension
{
    public const KEY = 'probation';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Probation Outcome';
    }

    public function score(HrEmployee $employee, array $ctx): DimensionResult
    {
        $probation = $ctx['probation'] ?? null;

        if (! $probation) {
            return DimensionResult::unavailable(self::KEY, $this->label(),
                'No probation record for this employee.');
        }

        $status     = (string) $probation->current_status;
        $extensions = (int) ($probation->extension_count ?? 0);

        // The model's own vocabulary, not invented strings: OPEN = Assigned /
        // Active / Extended, TERMINAL = Confirmed / Failed / Cancelled.
        if (in_array($status, HrEmployeeProbation::OPEN, true)) {
            return DimensionResult::unavailable(self::KEY, $this->label(),
                'Probation is still in progress ('.$status.'), so there is no outcome to score.');
        }

        // Cancelled is an administrative act, not the employee's outcome.
        if ($status === HrEmployeeProbation::CANCELLED) {
            return DimensionResult::unavailable(self::KEY, $this->label(),
                'Probation was cancelled administratively — no outcome to score.');
        }

        if ($status === HrEmployeeProbation::FAILED) {
            return DimensionResult::scored(self::KEY, $this->label(), 0,
                'Probation was not passed.', ['status' => $status]);
        }

        $score = 100 - min(60, $extensions * 30);

        return DimensionResult::scored(self::KEY, $this->label(), $score,
            $extensions === 0
                ? 'Confirmed without extension.'
                : sprintf('Confirmed after %d extension(s).', $extensions),
            ['status' => $status, 'extensions' => $extensions]);
    }
}
