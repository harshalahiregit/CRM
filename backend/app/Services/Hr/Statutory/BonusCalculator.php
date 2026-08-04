<?php

namespace App\Services\Hr\Statutory;

/**
 * Statutory bonus.
 *
 * Config keys:
 *   rate                      % applied to the bonus wage base
 *   eligibility_gross_ceiling monthly gross above which no statutory bonus is due
 *   calculation_ceiling       wage base is capped at this before applying the rate
 *
 * Both ceilings are optional and independent: one decides WHETHER a bonus is due,
 * the other caps the amount it is computed on.
 */
class BonusCalculator
{
    public function calculate(float $monthlyGross, float $bonusWages, ?array $config): array
    {
        if (! $config || empty($config['rate'])) {
            return $this->zero('Bonus not configured');
        }

        $eligibility = isset($config['eligibility_gross_ceiling']) ? (float) $config['eligibility_gross_ceiling'] : null;
        if ($eligibility !== null && $monthlyGross > $eligibility) {
            return $this->zero('Gross above the bonus eligibility ceiling');
        }

        $base = $bonusWages;
        if (isset($config['calculation_ceiling'])) {
            $base = min($base, (float) $config['calculation_ceiling']);
        }

        return ['applicable' => true, 'wages' => round($base, 2),
                'amount' => round($base * (float) $config['rate'] / 100, 2), 'reason' => null];
    }

    private function zero(string $reason): array
    {
        return ['applicable' => false, 'wages' => 0.0, 'amount' => 0.0, 'reason' => $reason];
    }
}
