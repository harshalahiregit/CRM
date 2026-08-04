<?php

namespace App\Services\Hr\Statutory;

/**
 * Employees' State Insurance.
 *
 * Config keys:
 *   gross_threshold  monthly gross at or below which ESIC applies
 *   employee_rate    % of ESIC wages deducted from the employee
 *   employer_rate    % of ESIC wages contributed by the employer
 *
 * Eligibility is decided on GROSS, while the contribution is computed on the sum
 * of components flagged esic_applicable — the two are not necessarily the same
 * figure, so both are passed in rather than assumed equal.
 */
class EsicCalculator
{
    public function calculate(float $grossForEligibility, float $esicWages, ?array $config): array
    {
        if (! $config || (empty($config['employee_rate']) && empty($config['employer_rate']))) {
            return $this->zero('ESIC not configured');
        }

        $threshold = isset($config['gross_threshold']) ? (float) $config['gross_threshold'] : null;
        if ($threshold !== null && $grossForEligibility > $threshold) {
            return $this->zero('Gross above the ESIC threshold');
        }

        $employee = round($esicWages * (float) ($config['employee_rate'] ?? 0) / 100, 2);
        $employer = round($esicWages * (float) ($config['employer_rate'] ?? 0) / 100, 2);

        return ['applicable' => true, 'wages' => round($esicWages, 2),
                'employee' => $employee, 'employer' => $employer, 'reason' => null];
    }

    private function zero(string $reason): array
    {
        return ['applicable' => false, 'wages' => 0.0, 'employee' => 0.0, 'employer' => 0.0, 'reason' => $reason];
    }
}
