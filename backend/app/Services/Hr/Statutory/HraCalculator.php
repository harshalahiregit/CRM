<?php

namespace App\Services\Hr\Statutory;

/**
 * House Rent Allowance exemption — the classic "least of three" rule, with all
 * three legs configurable.
 *
 * Config (under the regime's `hra` key; every value supplied by the business):
 *   salary_percent_metro      % of salary used for the metro leg
 *   salary_percent_non_metro  % of salary used for the non-metro leg
 *   rent_excess_percent       % of salary subtracted from rent for the third leg
 *
 * Nothing here assumes 50/40/10. With no config the exemption is zero and the
 * reason says so, exactly like every other calculator in this namespace.
 *
 * "Salary" for HRA means basic + DA, not gross — the caller passes that base in,
 * because only the salary structure knows which components qualify.
 */
class HraCalculator
{
    /**
     * @param  float  $hraReceived   HRA actually paid over the period
     * @param  float  $salaryBase    basic + DA over the same period
     * @param  float  $rentPaid      rent paid over the same period
     * @param  bool   $metro         whether the rented home is in a metro
     */
    public function exempt(float $hraReceived, float $salaryBase, float $rentPaid, bool $metro, ?array $config): array
    {
        if (! $config) {
            return $this->zero('HRA exemption rules not configured');
        }
        if ($hraReceived <= 0) {
            return $this->zero('No HRA component in this salary structure');
        }
        if ($rentPaid <= 0) {
            return $this->zero('No rent declared');
        }

        $pctKey = $metro ? 'salary_percent_metro' : 'salary_percent_non_metro';
        $pct    = $config[$pctKey] ?? null;
        $excess = $config['rent_excess_percent'] ?? null;

        if ($pct === null || $excess === null) {
            return $this->zero('HRA exemption rules incomplete — set the salary and rent-excess percentages');
        }

        $legs = [
            'hra_received'  => round($hraReceived, 2),
            'salary_percent'=> round($salaryBase * (float) $pct / 100, 2),
            'rent_excess'   => round(max(0.0, $rentPaid - $salaryBase * (float) $excess / 100), 2),
        ];

        return [
            'applicable' => true,
            'amount'     => round(min($legs), 2),
            // Every leg is returned, not just the winner: an employee asking "why is
            // my exemption this figure?" needs to see which one bound.
            'legs'       => $legs,
            'reason'     => null,
        ];
    }

    private function zero(string $reason): array
    {
        return ['applicable' => false, 'amount' => 0.0, 'legs' => [], 'reason' => $reason];
    }
}
