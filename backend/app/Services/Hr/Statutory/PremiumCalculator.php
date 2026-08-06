<?php

namespace App\Services\Hr\Statutory;

/**
 * Review comment #30 — "PF, ESIC, PT, GRATUITY, BONUS, WCP, MEDICLAIM ETC. –
 * Setup and sec. in emp." PF, ESIC, PT, Gratuity and Bonus already existed; WCP
 * (Workmen's Compensation) and Mediclaim did not.
 *
 * Both are insurance PREMIUMS rather than statutory contributions, and they share
 * a shape: a premium computed either as a flat amount per employee or as a
 * percentage of wages, then split between the employee and the employer. One
 * calculator serves both — WCP is typically employer-borne in full and Mediclaim
 * is typically shared, but that is a matter of what the rates are set to, not of
 * different arithmetic, so there is no reason for two near-identical classes.
 *
 * NOTHING is hardcoded, per the comment's "configuration-driven". Config keys:
 *
 *   mode             'fixed' | 'percentage'      (default: percentage)
 *   amount           premium per employee per month   (mode=fixed)
 *   employee_rate    % of wages borne by the employee (mode=percentage)
 *   employer_rate    % of wages borne by the employer (mode=percentage)
 *   employee_amount  flat employee share              (mode=fixed)
 *   employer_amount  flat employer share              (mode=fixed)
 *   gross_threshold  apply only at or below this monthly gross (optional)
 *   min_gross        apply only at or above this monthly gross (optional)
 *
 * A tenant that configures neither rate gets zeros and a reason, exactly as an
 * unconfigured PF or ESIC does — an unconfigured deduction must never be guessed.
 */
class PremiumCalculator
{
    /**
     * @param  float  $gross  monthly gross, used for eligibility bands
     * @param  float  $wages  the wage base the percentage applies to
     */
    public function calculate(float $gross, float $wages, ?array $config, string $label): array
    {
        if (! $config) {
            return $this->zero("{$label} not configured");
        }

        $mode = mb_strtolower((string) ($config['mode'] ?? 'percentage'));

        // Eligibility bands are checked on GROSS, like ESIC: a scheme that only
        // covers workers under a wage ceiling is describing their gross pay.
        if (isset($config['gross_threshold']) && $gross > (float) $config['gross_threshold']) {
            return $this->zero("Gross above the {$label} ceiling");
        }
        if (isset($config['min_gross']) && $gross < (float) $config['min_gross']) {
            return $this->zero("Gross below the {$label} floor");
        }

        [$employee, $employer] = $mode === 'fixed'
            ? $this->fixed($config)
            : $this->percentage($config, $wages);

        if ($employee <= 0 && $employer <= 0) {
            return $this->zero("{$label} not configured");
        }

        return [
            'applicable' => true,
            'wages'      => round($mode === 'fixed' ? 0.0 : $wages, 2),
            'employee'   => $employee,
            'employer'   => $employer,
            'mode'       => $mode,
            'reason'     => null,
        ];
    }

    /**
     * A flat premium. `amount` alone means the employer bears it — the common
     * case for WCP, and a safer default than splitting a premium nobody asked to
     * have deducted from their pay.
     */
    private function fixed(array $config): array
    {
        $employee = (float) ($config['employee_amount'] ?? 0);
        $employer = (float) ($config['employer_amount'] ?? 0);

        if ($employee <= 0 && $employer <= 0 && isset($config['amount'])) {
            $employer = (float) $config['amount'];
        }

        return [round($employee, 2), round($employer, 2)];
    }

    private function percentage(array $config, float $wages): array
    {
        return [
            round($wages * (float) ($config['employee_rate'] ?? 0) / 100, 2),
            round($wages * (float) ($config['employer_rate'] ?? 0) / 100, 2),
        ];
    }

    private function zero(string $reason): array
    {
        return ['applicable' => false, 'wages' => 0.0, 'employee' => 0.0,
                'employer' => 0.0, 'mode' => null, 'reason' => $reason];
    }
}
