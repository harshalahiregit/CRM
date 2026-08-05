<?php

namespace App\Services\Hr\Statutory;

use App\Support\Hr\WorkStates;
use Illuminate\Support\Carbon;

/**
 * Turns a salary breakdown into statutory figures.
 *
 * Kept deliberately separate from PayrollService: payroll decides WHO is paid and
 * freezes the result, this decides WHAT the statutory split is. Neither knows the
 * other's rules.
 *
 * The wage bases come from the component flags that already existed on the master
 * — `pf_applicable`, `esic_applicable`, `taxable` — which nothing read until now.
 * That is why no new per-component configuration was needed.
 */
class StatutoryEngine
{
    public function __construct(
        private StatutoryRuleResolver $rules,
        private PfCalculator $pf,
        private EsicCalculator $esic,
        private PtCalculator $pt,
        private BonusCalculator $bonus,
        private GratuityCalculator $gratuity,
        private TdsCalculator $tds,
        private TdsEngine $tdsEngine,
        private PremiumCalculator $premium,
    ) {
    }

    /**
     * @param  array  $lines  component lines: ['code','name','type','computed_amount',
     *                        'taxable','pf_applicable','esic_applicable']
     * @param  array  $ctx    ['state' => ?string, 'date' => ?Carbon, 'tds_paid_so_far' => float]
     */
    public function forSalary(array $lines, int $tenantId, array $ctx = []): array
    {
        $date = isset($ctx['date']) ? Carbon::parse($ctx['date']) : Carbon::today();

        // Normalised once here so a rule keyed "Maharashtra" still matches an
        // employee stored as "MH", and so a city that slipped in becomes null
        // (= "no state") instead of a value that silently matches nothing.
        $state = WorkStates::normalize($ctx['state'] ?? null);

        $earnings = array_filter($lines, fn ($l) => ($l['type'] ?? null) === 'Earning');

        $sum = fn (array $rows, ?string $flag = null) => round(array_sum(array_map(
            fn ($l) => $flag === null || ! empty($l[$flag]) ? (float) ($l['computed_amount'] ?? 0) : 0.0,
            $rows
        )), 2);

        $gross     = $sum($earnings);
        $pfWages   = $sum($earnings, 'pf_applicable');
        $esicWages = $sum($earnings, 'esic_applicable');
        $taxable   = $sum($earnings, 'taxable');

        $pf   = $this->pf->calculate($pfWages, $this->rules->resolve('pf', $tenantId, $date));
        $esic = $this->esic->calculate($gross, $esicWages, $this->rules->resolve('esic', $tenantId, $date));
        // strictState: no work state → no rule lookup at all. PT is levied BY a
        // state, so guessing one would deduct the wrong state's tax.
        $ptRule = $state ? $this->rules->resolve('pt', $tenantId, $date, $state, strictState: true) : null;
        $pt     = $this->pt->calculate($gross, $ptRule, (int) $date->format('n'), $state);
        $bon  = $this->bonus->calculate($gross, $pfWages, $this->rules->resolve('bonus', $tenantId, $date));
        $grat = $this->gratuity->provision($pfWages, $this->rules->resolve('gratuity', $tenantId, $date));

        // #30 — WCP and Mediclaim. Both use GROSS as the percentage base rather
        // than the PF wage base: they insure the person, not a subset of their
        // pay, so narrowing the base would under-insure them.
        $wcp  = $this->premium->calculate($gross, $gross, $this->rules->resolve('wcp', $tenantId, $date), 'WCP');
        $medi = $this->premium->calculate($gross, $gross, $this->rules->resolve('mediclaim', $tenantId, $date), 'Mediclaim');

        $tds = $this->resolveTds($lines, $taxable, $tenantId, $date, $ctx);

        // Employer contributions are a company cost, NOT an employee deduction —
        // adding them here would understate net pay. The WCP/Mediclaim EMPLOYEE
        // shares are deductions and do belong in this total; their employer
        // shares deliberately do not.
        $statutoryDeductions = round(
            $pf['employee'] + $esic['employee'] + $pt['amount'] + $tds['monthly_tds']
            + $wcp['employee'] + $medi['employee'], 2
        );

        return [
            'pf_wages'             => $pf['wages'],
            'pf_employee'          => $pf['employee'],
            'pf_employer'          => $pf['employer'],
            'eps_employer'         => $pf['eps'],
            'esic_wages'           => $esic['wages'],
            'esic_employee'        => $esic['employee'],
            'esic_employer'        => $esic['employer'],
            'pt_amount'            => $pt['amount'],
            'tds_amount'           => $tds['monthly_tds'],
            'bonus_amount'         => $bon['amount'],
            'gratuity_amount'      => $grat['amount'],
            // #30 — split like PF/ESIC so a payslip can show what the employee
            // paid and a cost report can show what the company paid.
            'wcp_employee'         => $wcp['employee'],
            'wcp_employer'         => $wcp['employer'],
            'mediclaim_employee'   => $medi['employee'],
            'mediclaim_employer'   => $medi['employer'],
            'taxable_earnings'     => $taxable,
            'statutory_deductions' => $statutoryDeductions,
            // Year-to-date tax context, frozen so this month's figure stays
            // explainable after the rules change next year.
            'financial_year'        => $tds['financial_year'] ?? null,
            'tax_regime'            => $tds['regime'] ?? null,
            'ytd_taxable_earnings'  => $tds['ytd_taxable'] ?? 0.0,
            'ytd_tds'               => $tds['ytd_tds'] ?? 0.0,
            'annual_taxable_income' => $tds['taxable_income'] ?? 0.0,
            'annual_tax_liability'  => $tds['annual_tax'] ?? 0.0,
            // Why each figure is what it is — answers "why was PF zero?" without
            // re-running the engine.
            'statutory_meta'       => [
                'state' => $state,
                'pf' => $pf['reason'], 'esic' => $esic['reason'], 'pt' => $pt['reason'],
                'bonus' => $bon['reason'], 'gratuity' => $grat['reason'], 'tds' => $tds['reason'],
                'wcp' => $wcp['reason'], 'mediclaim' => $medi['reason'],
                'regime' => $tds['regime'] ?? null,
                'tds_detail' => ['projected_annual' => $tds['projected_annual'],
                                 'taxable_income' => $tds['taxable_income'],
                                 'annual_tax' => $tds['annual_tax']]
                                 + (empty($tds['detail']) ? [] : ['working' => $tds['detail']]),
            ],
        ];
    }

    /**
     * TDS for the month.
     *
     * Prefers the year-to-date engine, which reads what was actually paid in earlier
     * months. Falls back to the original projection ONLY when there is no employee
     * context to look those months up with — which is how a caller written before
     * this engine existed keeps working unchanged.
     */
    private function resolveTds(array $lines, float $taxable, int $tenantId, $date, array $ctx): array
    {
        $rule = $this->rules->resolve('tds', $tenantId, $date);

        if (! empty($ctx['employee_id'])) {
            $result = $this->tdsEngine->compute([
                'tenant_id'       => $tenantId,
                'employee_id'     => $ctx['employee_id'],
                'period'          => $date->format('Y-m'),
                'fy_start_month'  => $ctx['fy_start_month'] ?? 4,
                'monthly_taxable' => $taxable,
                'hra_received'    => $this->componentTotal($lines, ['hra']),
                'salary_base'     => $this->componentTotal($lines, ['basic', 'da', 'dearness']),
            ], $rule);

            // A misconfigured rule must not silently fall back to a different
            // engine with different figures — surface it instead.
            if ($result['applicable'] || $rule === null) {
                return $result;
            }
        }

        $month     = (int) $date->format('n');
        $remaining = $month >= 4 ? (12 - $month + 4) : (4 - $month);

        return $this->tds->calculate($taxable, max(1, min(12, $remaining)), $rule,
            (float) ($ctx['tds_paid_so_far'] ?? 0)) + ['regime' => null, 'ytd_taxable' => 0.0, 'ytd_tds' => 0.0,
                'financial_year' => null, 'detail' => []];
    }

    /**
     * Sum the components whose code or name matches one of the given keys.
     *
     * Matching on the salary structure rather than a fixed column because HRA and
     * Basic are tenant-defined components — there is no "the HRA column" to read.
     */
    private function componentTotal(array $lines, array $keys): float
    {
        $total = 0.0;
        foreach ($lines as $l) {
            if (($l['type'] ?? null) !== 'Earning') {
                continue;
            }
            $code = mb_strtolower((string) ($l['code'] ?? ''));
            $name = mb_strtolower((string) ($l['component_name'] ?? $l['name'] ?? ''));
            foreach ($keys as $k) {
                if ($code === $k || str_contains($name, $k)) {
                    $total += (float) ($l['computed_amount'] ?? $l['amount'] ?? 0);
                    break;
                }
            }
        }

        return round($total, 2);
    }

    /** Statutory lines to append to a payroll record's breakdown. */
    public function toLines(array $s): array
    {
        $rows = [
            ['code' => 'PF_EE',  'name' => 'Provident Fund (Employee)', 'amount' => $s['pf_employee']],
            ['code' => 'ESIC_EE','name' => 'ESIC (Employee)',           'amount' => $s['esic_employee']],
            ['code' => 'PT',     'name' => 'Professional Tax',          'amount' => $s['pt_amount']],
            ['code' => 'TDS',    'name' => 'TDS / Income Tax',          'amount' => $s['tds_amount']],
            // #30 — employee shares only. The employer share is a company cost
            // and appears on the cost report, never as a line on someone's payslip.
            ['code' => 'WCP_EE',  'name' => 'Workmen\'s Compensation (Employee)', 'amount' => $s['wcp_employee'] ?? 0],
            ['code' => 'MEDI_EE', 'name' => 'Mediclaim (Employee)',              'amount' => $s['mediclaim_employee'] ?? 0],
        ];

        return array_values(array_map(
            fn ($r, $i) => $r + ['type' => 'Deduction', 'source' => 'statutory', 'sort_order' => 900 + $i],
            array_filter($rows, fn ($r) => $r['amount'] > 0),
            array_keys(array_filter($rows, fn ($r) => $r['amount'] > 0)),
        ));
    }
}
