<?php

namespace App\Services\Hr\Scoring\Employee\Dimensions;

use App\Models\Hr\HrEmployee;
use App\Services\Hr\Scoring\Dimensions\DimensionResult;

/**
 * #39 — time served, as a stability signal.
 *
 * Weighted lightly on purpose. Tenure is a fact, not an achievement: a strong
 * six-month hire should not be ranked below a mediocre five-year one, so this
 * contributes a small amount and saturates after five years.
 */
class TenureDimension implements EmployeeDimension
{
    public const KEY = 'tenure';

    private const SATURATION_YEARS = 5.0;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Tenure & Stability';
    }

    public function score(HrEmployee $employee, array $ctx): DimensionResult
    {
        if (! $employee->joining_date) {
            return DimensionResult::unavailable(self::KEY, $this->label(),
                'No joining date on the employee record.');
        }

        $years = $employee->joining_date->diffInDays(now()) / 365.25;
        $score = min(1.0, $years / self::SATURATION_YEARS) * 100;

        return DimensionResult::scored(self::KEY, $this->label(), $score,
            sprintf('%.1f year(s) with the company.', $years),
            ['years' => round($years, 2), 'joining_date' => $employee->joining_date->toDateString()]);
    }
}
