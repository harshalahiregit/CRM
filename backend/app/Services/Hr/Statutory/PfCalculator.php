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
 *   eps_max_age          age at which EPS membership ends (58 under the scheme).
 *                        Past it the WHOLE employer share goes to EPF and nothing
 *                        to the pension fund.
 *   restrict_to_ceiling  true  → wages are capped at wage_ceiling
 *                        false → the ceiling only decides ELIGIBILITY; contribution
 *                                is on actual wages
 *
 * The PF wage base is the sum of components flagged pf_applicable — the flag that
 * already existed on the component master but was never read by anything.
 */
class PfCalculator
{
    public function calculate(float $pfWages, ?array $config, ?int $ageYears = null): array
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

        // EPFO works in whole rupees — the ECR has no paise column. It matters at
        // the ceiling: 8.33% of 15,000 is 1249.50, and the filed register shows
        // 1250 with EPF at 550. Left in paise the two are 1249.50 and 550.50, and
        // every month is fifty paise out against the portal.
        $employee = (float) round($base * $employeeRate / 100);
        $employer = (float) round($base * $employerRate / 100);

        // EPS is carved OUT of the employer share, never added on top.
        $eps      = (float) round(min($base, $ceiling ?? $base) * $epsRate / 100);
        $eps      = min($eps, $employer);

        // EPS membership ends at 58: from then on the employer's whole 12% goes to
        // EPF and none to the pension fund. Visible in the July register — one
        // employee born in 1966 shows EPS 0 and EPF 1800 where everybody else
        // shows 1250 and 550. Without this he is short-paid into EPF by 1,250 a
        // month and over-paid into a pension he can no longer join.
        $epsMaxAge = $config['eps_max_age'] ?? null;

        if ($epsMaxAge !== null && $ageYears !== null && $ageYears >= (int) $epsMaxAge) {
            $eps = 0.0;
        }

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
