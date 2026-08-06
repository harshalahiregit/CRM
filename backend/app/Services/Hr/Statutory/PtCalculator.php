<?php

namespace App\Services\Hr\Statutory;

/**
 * Professional Tax — levied per state, so the rule is resolved by the employee's
 * work state before it reaches here.
 *
 * Config keys:
 *   slabs             [{ from, to (null = open-ended), amount }]
 *   month_overrides   { "2": 300 }  — e.g. Maharashtra's different February amount,
 *                     keyed by month number. Applied only when the slab matched.
 *
 * Every "no PT" path names its own cause, because "PT was zero" has three very
 * different fixes: set the employee's work state, configure that state's slabs, or
 * nothing at all (the gross genuinely falls below the first slab).
 */
class PtCalculator
{
    public function calculate(float $monthlyGross, ?array $config, ?int $month = null, ?string $state = null): array
    {
        if ($state === null || $state === '') {
            return $this->zero('Work state not set for this employee — PT not applied');
        }

        $slabs = $config['slabs'] ?? null;
        if (! $config || ! is_array($slabs) || $slabs === []) {
            return $this->zero("PT not configured for {$state}");
        }

        foreach ($slabs as $slab) {
            $from = (float) ($slab['from'] ?? 0);
            $to   = array_key_exists('to', $slab) && $slab['to'] !== null ? (float) $slab['to'] : INF;

            if ($monthlyGross >= $from && $monthlyGross <= $to) {
                $amount = (float) ($slab['amount'] ?? 0);

                $override = $config['month_overrides'][(string) $month] ?? null;
                if ($month !== null && $override !== null) {
                    $amount = (float) $override;
                }

                return ['applicable' => true, 'amount' => round($amount, 2), 'reason' => null];
            }
        }

        return $this->zero("Gross does not fall in any {$state} PT slab");
    }

    private function zero(string $reason): array
    {
        return ['applicable' => false, 'amount' => 0.0, 'reason' => $reason];
    }
}
