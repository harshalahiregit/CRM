<?php

namespace App\Services\Hr\Statutory;

/**
 * Gratuity, in both senses the business asked for:
 *
 *   provision()  — the monthly CTC provision (the existing 4.81% Benefit component
 *                  keeps working; this just states the rate as config).
 *   settlement() — the amount payable on exit:
 *                      last_drawn x days_per_year / month_days x completed years
 *                  gated by min_years and capped at max_amount.
 *
 * Config keys: rate, days_per_year, month_days, min_years, max_amount,
 *              round_years_from_months (months at/after which a part year counts
 *              as a full one).
 */
class GratuityCalculator
{
    /** Monthly CTC provision — unchanged behaviour, now configurable. */
    public function provision(float $wages, ?array $config): array
    {
        if (! $config || empty($config['rate'])) {
            return ['applicable' => false, 'amount' => 0.0, 'reason' => 'Gratuity provision not configured'];
        }

        return ['applicable' => true, 'amount' => round($wages * (float) $config['rate'] / 100, 2), 'reason' => null];
    }

    /** Payable on exit. $months = completed months of service. */
    public function settlement(float $lastDrawnWages, int $months, ?array $config): array
    {
        if (! $config || empty($config['days_per_year']) || empty($config['month_days'])) {
            return $this->zeroSettlement('Gratuity settlement rules not configured');
        }

        $minYears = (int) ($config['min_years'] ?? 0);
        $years    = intdiv($months, 12);
        $rem      = $months % 12;

        // A part year counts as a full one at/after the configured month threshold.
        $roundFrom = $config['round_years_from_months'] ?? null;
        if ($roundFrom !== null && $rem >= (int) $roundFrom) {
            $years++;
        }

        if ($years < $minYears) {
            return $this->zeroSettlement("Service below the {$minYears}-year minimum", $years);
        }

        $amount = $lastDrawnWages * ((float) $config['days_per_year'] / (float) $config['month_days']) * $years;

        if (isset($config['max_amount'])) {
            $amount = min($amount, (float) $config['max_amount']);
        }

        return ['applicable' => true, 'amount' => round($amount, 2),
                'eligible_years' => $years, 'reason' => null];
    }

    private function zeroSettlement(string $reason, int $years = 0): array
    {
        return ['applicable' => false, 'amount' => 0.0, 'eligible_years' => $years, 'reason' => $reason];
    }
}
