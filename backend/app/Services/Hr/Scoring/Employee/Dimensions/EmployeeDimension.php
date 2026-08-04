<?php

namespace App\Services\Hr\Scoring\Employee\Dimensions;

use App\Models\Hr\HrEmployee;
use App\Services\Hr\Scoring\Dimensions\DimensionResult;

/**
 * #39 — one measurable aspect of an employee.
 *
 * Deliberately the same contract as the candidate dimensions, and returning the
 * SAME DimensionResult: that value object is domain-neutral, and its rule —
 * `unavailable()` means "nothing to measure", never zero — is exactly what
 * employee data needs, where most sources are sparse in a young tenant.
 *
 * `$ctx` carries everything already loaded for the employee so a dimension never
 * queries: the engine loads once and each dimension reads. That is what keeps
 * scoring 16 employees from becoming 16 × 8 queries.
 */
interface EmployeeDimension
{
    /** Stable key — also the weight key in config. */
    public function key(): string;

    public function label(): string;

    /** @param array $ctx pre-loaded employee data (see EmployeeScoringEngine::context) */
    public function score(HrEmployee $employee, array $ctx): DimensionResult;
}
