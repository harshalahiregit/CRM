<?php

namespace App\Services\Hr\Statutory;

use App\Models\Hr\HrInvestmentDeclaration;
use App\Models\Hr\HrPayrollRecord;
use App\Support\Hr\FinancialYear;
use App\Support\Hr\TaxSections;

/**
 * Year-to-date TDS.
 *
 * The projection engine it replaces asked "what if this month repeated twelve
 * times?". That is wrong the moment anything changes mid-year — a joiner, a
 * revision, a bonus — because months already paid are a FACT, not a forecast.
 *
 * This engine computes:
 *
 *   annual gross   = actual taxable pay already drawn this year
 *                  + this month
 *                  + this month projected across the months still to come
 *                  + income from a previous employer
 *
 *   taxable income = annual gross
 *                  − standard deduction        (regime config)
 *                  − HRA exemption             (regime config + declaration)
 *                  − Chapter VI-A deductions   (regime config caps + declaration)
 *
 *   month's TDS    = (tax on taxable income − TDS already deducted this year
 *                     − previous employer TDS) ÷ months remaining
 *
 * Only the projection of FUTURE months is an estimate; everything behind it is
 * read from frozen payroll records. As the year proceeds the estimate shrinks to
 * nothing, so the final month self-corrects.
 *
 * REGIMES. A rule's config may carry `regimes: {old: {...}, new: {...}}`. If it
 * does not, the flat config is used for every employee — which is exactly how the
 * rules written before regimes existed continue to behave.
 *
 * Nothing here decides which deductions a regime allows, or what any of them cap
 * at. Those are legal figures; they come from the config the business sets.
 */
class TdsEngine
{
    public function __construct(
        private TdsCalculator $tds,
        private HraCalculator $hra,
    ) {
    }

    /**
     * @param  array  $ctx  [
     *     'employee_id', 'tenant_id', 'period' (Y-m), 'fy_start_month',
     *     'monthly_taxable' => float,   taxable earnings for THIS month
     *     'hra_received'    => float,   monthly HRA component
     *     'salary_base'     => float,   monthly basic (+DA) — the HRA salary base
     *  ]
     */
    public function compute(array $ctx, ?array $ruleConfig): array
    {
        $tenantId   = (int) ($ctx['tenant_id'] ?? 0);
        $employeeId = (int) ($ctx['employee_id'] ?? 0);
        $period     = (string) ($ctx['period'] ?? '');
        $monthly    = (float) ($ctx['monthly_taxable'] ?? 0);

        if (! $ruleConfig) {
            return $this->zero('TDS not configured');
        }
        if (! $employeeId || ! $period) {
            return $this->zero('No employee context for TDS');
        }

        $fy = FinancialYear::forDate($period.'-01', (int) ($ctx['fy_start_month'] ?? 4));

        $declaration = $this->declarationFor($tenantId, $employeeId, $fy->label());
        $regime      = $declaration?->regime ?: HrInvestmentDeclaration::NEW;
        $config      = $this->regimeConfig($ruleConfig, $regime);

        if (! ($config['slabs'] ?? null)) {
            return $this->zero("No TDS slabs configured for the {$regime} regime", $regime);
        }

        // ── What has actually happened so far this year ──────────────────
        $prior = $this->priorRecords($tenantId, $employeeId, $fy, $period);
        $ytdTaxableBefore = round((float) $prior->sum('taxable_earnings'), 2);
        $ytdTdsBefore     = round((float) $prior->sum('tds_amount'), 2);

        $monthIndex = $fy->monthIndex((int) substr($period, 5, 2));
        $remaining  = $fy->remainingMonths((int) substr($period, 5, 2));
        $future     = $remaining - 1;   // months after this one

        // ── Annual gross ─────────────────────────────────────────────────
        $prevEmployerIncome = (float) ($declaration?->previous_employer_income ?? 0);
        $prevEmployerTds    = (float) ($declaration?->previous_employer_tds ?? 0);

        $annualGross = round($ytdTaxableBefore + $monthly + ($monthly * $future) + $prevEmployerIncome, 2);

        // ── Deductions and exemptions ────────────────────────────────────
        $standard = (float) ($config['standard_deduction'] ?? 0);

        $hraResult = $this->hraExemption($ctx, $declaration, $config, $remaining, $monthIndex);
        $chapter   = $this->chapterVia($declaration, $config);

        $taxableIncome = max(0.0, round(
            $annualGross - $standard - $hraResult['amount'] - $chapter['total'], 2
        ));

        // ── Tax, then this month's share of what is still owed ───────────
        $computed  = $this->tds->annual($taxableIncome, $config);
        $annualTax = $computed['annual_tax'];

        $outstanding = max(0.0, round($annualTax - $ytdTdsBefore - $prevEmployerTds, 2));
        $monthlyTds  = round($outstanding / max(1, $remaining), 2);

        return [
            'applicable'       => true,
            'regime'           => $regime,
            'financial_year'   => $fy->label(),
            'projected_annual' => $annualGross,
            'taxable_income'   => $taxableIncome,
            'annual_tax'       => $annualTax,
            'rebate'           => $computed['rebate'],
            'monthly_tds'      => $monthlyTds,
            'ytd_taxable'      => round($ytdTaxableBefore + $monthly, 2),
            'ytd_tds'          => round($ytdTdsBefore + $monthlyTds, 2),
            'reason'           => null,
            // The full working, so a payslip can answer "how did you get this?"
            'detail' => [
                'ytd_taxable_before_this_month' => $ytdTaxableBefore,
                'this_month_taxable'            => round($monthly, 2),
                'projected_future_months'       => $future,
                'projected_future_income'       => round($monthly * $future, 2),
                'previous_employer_income'      => $prevEmployerIncome,
                'previous_employer_tds'         => $prevEmployerTds,
                'standard_deduction'            => $standard,
                'hra_exemption'                 => $hraResult,
                'chapter_via'                   => $chapter,
                'tax_before_rebate'             => $computed['tax_before_rebate'],
                'surcharge'                     => $computed['surcharge'],
                'cess'                          => $computed['cess'],
                'tds_already_deducted'          => $ytdTdsBefore,
                'months_remaining'              => $remaining,
                'declaration_status'            => $declaration?->status,
                'declaration_counts_for_tax'    => (bool) $declaration?->countsForTax(),
            ],
        ];
    }

    /* ── Pieces ───────────────────────────────────────────────────────── */

    /**
     * The regime's slice of a rule config, or the flat config when the rule predates
     * regimes. A regime key that exists but is empty is treated as "not configured"
     * rather than silently borrowing the other regime's figures.
     */
    public function regimeConfig(array $ruleConfig, string $regime): array
    {
        if (! empty($ruleConfig['regimes']) && is_array($ruleConfig['regimes'])) {
            return (array) ($ruleConfig['regimes'][$regime] ?? []);
        }

        return $ruleConfig;
    }

    /** Records for this employee in this financial year, strictly before this month. */
    private function priorRecords(int $tenantId, int $employeeId, FinancialYear $fy, string $period)
    {
        $before = $fy->periodsBefore($period);
        if ($before === []) {
            return collect();
        }

        return HrPayrollRecord::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->whereIn('attendance_period', $before)
            ->where('status', HrPayrollRecord::PROCESSED)
            ->get(['taxable_earnings', 'tds_amount']);
    }

    private function declarationFor(int $tenantId, int $employeeId, string $fyLabel): ?HrInvestmentDeclaration
    {
        return HrInvestmentDeclaration::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('financial_year', $fyLabel)
            ->with('items')
            ->first();
    }

    /**
     * HRA exemption for the year.
     *
     * The monthly HRA and salary base are annualised over the SAME number of months
     * the declared rent covers — mixing an annual rent with a monthly salary is the
     * easiest way to get this badly wrong.
     */
    private function hraExemption(array $ctx, ?HrInvestmentDeclaration $declaration, array $config, int $remaining, int $monthIndex): array
    {
        if (! $declaration || ! $declaration->countsForTax()) {
            return ['amount' => 0.0, 'reason' => $declaration
                ? 'HRA exemption applies once the declaration is verified'
                : 'No declaration on record', 'legs' => []];
        }

        $hra = $declaration->hra ?: [];
        $rent = (float) ($hra['rent_paid_annual'] ?? 0);
        if ($rent <= 0) {
            return ['amount' => 0.0, 'reason' => 'No rent declared', 'legs' => []];
        }

        // Months the rent covers; defaults to the full year.
        $months = (int) ($hra['months'] ?? 12);
        $months = max(1, min(12, $months));

        $result = $this->hra->exempt(
            hraReceived: (float) ($ctx['hra_received'] ?? 0) * $months,
            salaryBase:  (float) ($ctx['salary_base'] ?? 0) * $months,
            rentPaid:    $rent,
            metro:       (bool) ($hra['metro'] ?? false),
            config:      $config['hra'] ?? null,
        );

        return ['amount' => $result['amount'], 'reason' => $result['reason'], 'legs' => $result['legs']];
    }

    /**
     * Chapter VI-A total: every section the regime allows, each capped at its
     * configured limit.
     *
     * `allowed_sections` and `section_limits` both come from config. An unlisted
     * section contributes nothing and says why — which is how the new regime's
     * narrower list is expressed without hardcoding it.
     */
    private function chapterVia(?HrInvestmentDeclaration $declaration, array $config): array
    {
        if (! $declaration || ! $declaration->countsForTax()) {
            return ['total' => 0.0, 'sections' => [], 'reason' => $declaration
                ? 'Deductions apply once the declaration is verified'
                : 'No declaration on record'];
        }

        $allowed = $config['allowed_sections'] ?? null;
        if (! is_array($allowed) || $allowed === []) {
            return ['total' => 0.0, 'sections' => [],
                    'reason' => 'No deduction sections are enabled for this regime'];
        }

        $limits   = (array) ($config['section_limits'] ?? []);
        $sections = [];
        $total    = 0.0;

        foreach ($allowed as $code) {
            if (! TaxSections::exists((string) $code) || $code === TaxSections::HRA) {
                continue;   // HRA is an exemption, handled separately
            }
            $claimed = $declaration->amountFor((string) $code);
            if ($claimed <= 0) {
                continue;
            }
            $cap     = array_key_exists((string) $code, $limits) ? (float) $limits[$code] : null;
            $allowedAmt = $cap !== null ? min($claimed, $cap) : $claimed;

            $sections[] = [
                'section' => $code, 'claimed' => round($claimed, 2),
                'limit' => $cap, 'allowed' => round($allowedAmt, 2),
            ];
            $total += $allowedAmt;
        }

        return ['total' => round($total, 2), 'sections' => $sections, 'reason' => null];
    }

    private function zero(string $reason, ?string $regime = null): array
    {
        return ['applicable' => false, 'regime' => $regime, 'financial_year' => null,
                'projected_annual' => 0.0, 'taxable_income' => 0.0, 'annual_tax' => 0.0,
                'rebate' => 0.0, 'monthly_tds' => 0.0, 'ytd_taxable' => 0.0, 'ytd_tds' => 0.0,
                'reason' => $reason, 'detail' => []];
    }
}
