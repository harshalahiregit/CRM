<?php

namespace App\Services\Hr\Statutory;

/**
 * Provident Fund.
 *
 * Config keys (all optional — an absent key disables that behaviour):
 *   employee_rate        % of PF wages deducted from the employee
 *   employer_rate        % of PF wages contributed by the employer
 *   eps_rate             % of PF wages diverted to EPS out of the employer share
 *   wage_ceiling         monthly wage cap
 *   restrict_to_ceiling  true  → wages are capped at wage_ceiling
 *                        false → the ceiling only decides ELIGIBILITY; contribution
 *                                is on actual wages
 *
 * The PF wage base is the sum of components flagged pf_applicable — the flag that
 * already existed on the component master but was never read by anything.
 */
class PfCalculator
{
    public function calculate(float $pfWages, ?array $config): array
    {
        if (! $config || empty($config['employee_rate']) && empty($config['employer_rate'])) {
            return $this->zero('PF not configured');
        }

        $ceiling  = isset($config['wage_ceiling']) ? (float) $config['wage_ceiling'] : null;
        $restrict = (bool) ($config['restrict_to_ceiling'] ?? true);

        $base = $pfWages;
        if ($ceiling !== null && $restrict) {
            $base = min($base, $ceiling);
        }

        $employeeRate = (float) ($config['employee_rate'] ?? 0);
        $employerRate = (float) ($config['employer_rate'] ?? 0);
        $epsRate      = (float) ($config['eps_rate'] ?? 0);

        $employee = round($base * $employeeRate / 100, 2);
        $employer = round($base * $employerRate / 100, 2);
        // EPS is carved OUT of the employer share, never added on top.
        $eps      = round(min($base, $ceiling ?? $base) * $epsRate / 100, 2);
        $eps      = min($eps, $employer);

        return [
            'applicable' => true,
            'wages'      => round($base, 2),
            'employee'   => $employee,
            'employer'   => $employer,
            'eps'        => $eps,
            'epf'        => round($employer - $eps, 2),
            'reason'     => null,
        ];
    }

    private function zero(string $reason): array
    {
        return ['applicable' => false, 'wages' => 0.0, 'employee' => 0.0,
                'employer' => 0.0, 'eps' => 0.0, 'epf' => 0.0, 'reason' => $reason];
    }
}
