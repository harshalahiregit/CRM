<?php

namespace App\Services\Hr\Statutory;

/**
 * Employees' State Insurance.
 *
 * Config keys:
 *   gross_threshold  the wage at or below which ESIC applies
 *   eligibility_base 'gross'  → the ceiling is tested against total gross pay
 *                    'wages'  → against the ESIC wage base (Basic + DA here)
 *
 * WHICH ONE IS A DECISION, NOT A FACT, and it changes who is covered at all.
 * Three people on this company's filed July register earn ABOVE the 42,000
 * ceiling on gross — 48,478, 46,491 and 64,171 — and are nonetheless on the
 * return, contributing on Basic + DA. Tested against gross they would be outside
 * ESIC altogether and contribute nothing, so their reading pays MORE than the
 * alternative, not less.
 *
 * The default is 'wages' because that is what was filed and what these figures
 * have to reproduce. A tenant whose consultant reads it the other way changes one
 * config key rather than editing code.
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

        // Which figure the ceiling is tested against — see the note above.
        $against = ($config['eligibility_base'] ?? 'wages') === 'gross'
            ? $grossForEligibility
            : $esicWages;

        if ($threshold !== null && $against > $threshold) {
            return $this->zero('Gross above the ESIC threshold');
        }

        // Rounding is not decorative here — it is how the filed register reads.
        // Derived from a month already filed (July 2026, 8 employees): the
        // employee's share rounds UP to the next rupee and the employer's to the
        // NEAREST. Plain round() on both is wrong on 3 of those 8 rows, and plain
        // ceil() on both is wrong on 4 — a rupee out per employee per month, in a
        // number that has to agree with a government portal.
        $roundUp = ($config['round_employee_up'] ?? true);

        $employeeRaw = $esicWages * (float) ($config['employee_rate'] ?? 0) / 100;
        $employerRaw = $esicWages * (float) ($config['employer_rate'] ?? 0) / 100;

        $employee = $roundUp ? (float) ceil($employeeRaw) : round($employeeRaw, 2);
        $employer = round($employerRaw);

        return ['applicable' => true, 'wages' => round($esicWages, 2),
                'employee' => $employee, 'employer' => $employer, 'reason' => null];
    }

    private function zero(string $reason): array
    {
        return ['applicable' => false, 'wages' => 0.0, 'employee' => 0.0, 'employer' => 0.0, 'reason' => $reason];
    }
}
