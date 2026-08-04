<?php

namespace App\Services\Hr\Statutory;

/**
 * TDS on salary — monthly deduction derived from projected annual income.
 *
 * Config keys (every one optional; an absent key simply skips that step):
 *   slabs              [{ from, to (null = open-ended), rate }] on taxable income
 *   standard_deduction flat deduction from gross salary income
 *   rebate_87a         { taxable_income_limit, max_rebate }
 *   cess_rate          % applied to (tax - rebate)
 *   surcharge          [{ from, to, rate }] on tax, before cess
 *   exempt_allowances  flat annual exemption (HRA etc. — see the caveat below)
 *
 * This class is the SLAB ARITHMETIC only — given an annual taxable income and a
 * config, what is the tax? It knows nothing about years-to-date, regimes or
 * declarations; TdsEngine handles those and calls annual() here.
 *
 * calculate() is the original projection-based entry point. It is kept because
 * payroll still falls back to it when no year-to-date context is available (an
 * employee with no prior records and no declaration), and its behaviour is
 * deliberately unchanged. Its limitation stands: it projects the CURRENT month
 * across the year, so mid-year changes over- or under-deduct. Prefer TdsEngine.
 */
class TdsCalculator
{
    /**
     * @param  float  $monthlyTaxableEarnings  taxable components for this month
     * @param  int    $remainingMonths         months left in the financial year, incl. this one
     * @param  float  $taxPaidSoFar            TDS already deducted this year
     */
    public function calculate(
        float $monthlyTaxableEarnings,
        int $remainingMonths,
        ?array $config,
        float $taxPaidSoFar = 0.0,
        float $annualExemptions = 0.0,
    ): array {
        $slabs = $config['slabs'] ?? null;
        if (! $config || ! is_array($slabs) || $slabs === []) {
            return $this->zero('TDS not configured');
        }
        if ($remainingMonths < 1) {
            return $this->zero('No remaining months in the financial year');
        }

        // Projected annual gross from the current month's run rate.
        $projectedAnnual = $monthlyTaxableEarnings * 12;

        $taxable = $projectedAnnual
            - (float) ($config['standard_deduction'] ?? 0)
            - (float) ($config['exempt_allowances'] ?? 0)
            - $annualExemptions;

        $computed   = $this->annual($taxable, $config);
        $annualTax  = $computed['annual_tax'];
        $monthlyTds = round(max(0.0, $annualTax - $taxPaidSoFar) / $remainingMonths, 2);

        return [
            'applicable'       => true,
            'projected_annual' => round($projectedAnnual, 2),
            'taxable_income'   => $computed['taxable_income'],
            'annual_tax'       => $annualTax,
            'rebate'           => $computed['rebate'],
            'monthly_tds'      => $monthlyTds,
            'reason'           => null,
        ];
    }

    /**
     * Tax on a given annual taxable income: slabs → 87A rebate → surcharge → cess,
     * in that order, because each step operates on the output of the last.
     *
     * This is the primitive TdsEngine builds the year-to-date computation on. It
     * takes an income that has ALREADY had every deduction and exemption applied —
     * it does not know what those were.
     */
    public function annual(float $taxableIncome, ?array $config): array
    {
        $slabs   = $config['slabs'] ?? null;
        $taxable = max(0.0, $taxableIncome);

        if (! $config || ! is_array($slabs) || $slabs === []) {
            return ['taxable_income' => round($taxable, 2), 'tax_before_rebate' => 0.0,
                    'rebate' => 0.0, 'surcharge' => 0.0, 'cess' => 0.0, 'annual_tax' => 0.0];
        }

        $base = $this->applySlabs($taxable, $slabs);

        // 87A rebate — wipes tax entirely below the configured income limit.
        $rebate = 0.0;
        if (! empty($config['rebate_87a']['taxable_income_limit'])
            && $taxable <= (float) $config['rebate_87a']['taxable_income_limit']) {
            $rebate = min($base, (float) ($config['rebate_87a']['max_rebate'] ?? $base));
        }
        $tax = max(0.0, $base - $rebate);

        $surcharge = $this->applySlabs($taxable, $config['surcharge'] ?? [], $tax);
        $tax += $surcharge;

        $cess = $tax * (float) ($config['cess_rate'] ?? 0) / 100;
        $tax += $cess;

        return [
            'taxable_income'    => round($taxable, 2),
            'tax_before_rebate' => round($base, 2),
            'rebate'            => round($rebate, 2),
            'surcharge'         => round($surcharge, 2),
            'cess'              => round($cess, 2),
            'annual_tax'        => round($tax, 2),
        ];
    }

    /**
     * Marginal slab application: each band taxes only the income falling inside it.
     * When $onAmount is given the rate applies to THAT figure (used for surcharge,
     * which is a percentage of tax, banded by income).
     */
    private function applySlabs(float $income, array $slabs, ?float $onAmount = null): float
    {
        $total = 0.0;

        foreach ($slabs as $slab) {
            $from = (float) ($slab['from'] ?? 0);
            $to   = array_key_exists('to', $slab) && $slab['to'] !== null ? (float) $slab['to'] : INF;
            $rate = (float) ($slab['rate'] ?? 0);

            if ($income <= $from) {
                continue;
            }

            if ($onAmount !== null) {
                // Surcharge: the whole tax amount is charged at the matched band.
                if ($income > $from && $income <= $to) {
                    return $onAmount * $rate / 100;
                }
                continue;
            }

            $total += (min($income, $to) - $from) * $rate / 100;
        }

        return $total;
    }

    private function zero(string $reason): array
    {
        return ['applicable' => false, 'projected_annual' => 0.0, 'taxable_income' => 0.0,
                'annual_tax' => 0.0, 'rebate' => 0.0, 'monthly_tds' => 0.0, 'reason' => $reason];
    }
}
