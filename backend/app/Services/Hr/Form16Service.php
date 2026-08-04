<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrInvestmentDeclaration;
use App\Models\Hr\HrPayrollRecord;
use App\Services\Hr\Statutory\StatutoryRuleResolver;
use App\Services\Hr\Statutory\TdsEngine;
use App\Services\Settings\SettingsService;
use App\Support\Hr\FinancialYear;
use App\Support\Hr\TaxSections;

/**
 * Form-16-READY tax data for one employee and financial year.
 *
 * The name is deliberate: this assembles the figures a Form 16 is built from, in
 * the Part-B order. It is NOT a Form 16 and must not be issued as one — that is a
 * TRACES-generated document, and only the deductor's filed return can produce it.
 *
 * Everything here is READ. Nothing is recomputed: the annexure is summed from the
 * frozen payroll records for the year, so a rule change next April cannot alter
 * what a past year reports.
 */
class Form16Service
{
    public function __construct(
        private SettingsService $settings,
        private StatutoryRuleResolver $rules,
        private TdsEngine $tdsEngine,
    ) {
    }

    public function forEmployee(int $employeeId, int $tenantId, ?string $fyLabel = null): array
    {
        $employee = HrEmployee::where('tenant_id', $tenantId)->find($employeeId);
        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }

        $startMonth = (int) $this->settings->get($tenantId, 'payroll', 'fy_start_month', 4);
        $fy = ($fyLabel ? FinancialYear::fromLabel($fyLabel, $startMonth) : null)
            ?? FinancialYear::forDate(now(), $startMonth);

        $records = HrPayrollRecord::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->whereIn('attendance_period', $fy->periods())
            ->where('status', HrPayrollRecord::PROCESSED)
            ->orderBy('attendance_period')
            ->get();

        $declaration = HrInvestmentDeclaration::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('financial_year', $fy->label())
            ->with('items')->first();

        $sum = fn (string $col) => round((float) $records->sum($col), 2);

        $grossSalary  = $sum('gross_salary');
        $taxable      = $sum('taxable_earnings');
        $tdsDeducted  = $sum('tds_amount');
        $ptPaid       = $sum('pt_amount');
        $pfEmployee   = $sum('pf_employee');

        $prevIncome = (float) ($declaration?->previous_employer_income ?? 0);
        $prevTds    = (float) ($declaration?->previous_employer_tds ?? 0);
        $prevPt     = (float) ($declaration?->previous_employer_pt ?? 0);

        // The regime and the deduction breakdown come from the LAST processed month,
        // because that record was computed with the most complete picture of the year.
        $last   = $records->last();
        $regime = $last?->tax_regime ?? $declaration?->regime;
        $working = $this->workingFrom($last);

        $chapterVia = $this->chapterViaBreakdown($declaration, $working);
        $hra        = $working['hra_exemption'] ?? null;
        $standard   = (float) ($working['standard_deduction'] ?? 0);

        $grossTotal   = round($grossSalary + $prevIncome, 2);
        $totalDeduct  = round(array_sum(array_column($chapterVia, 'allowed')), 2);
        $exemptions   = round((float) ($hra['amount'] ?? 0), 2);

        return [
            'disclaimer' => 'Form-16-ready figures assembled from processed payroll. '
                            .'This is not a Form 16 — that is issued from TRACES against the filed TDS return.',
            'employee' => [
                'id' => $employee->id, 'name' => $employee->name, 'employee_code' => $employee->employee_code,
                'department' => $employee->department, 'designation' => $employee->designation,
                'joining_date' => optional($employee->joining_date)->toDateString(),
                'work_state' => $employee->work_state,
            ],
            'financial_year' => [
                'label' => $fy->label(), 'short' => $fy->shortLabel(),
                'assessment_year' => ($fy->startYear + 1).'-'.substr((string) ($fy->startYear + 2), 2),
                'from' => $fy->startDate()->toDateString(), 'to' => $fy->endDate()->toDateString(),
            ],
            'regime' => $regime,
            'months_processed' => $records->count(),

            // Part B, in order.
            'salary' => [
                'gross_salary_this_employer' => $grossSalary,
                'previous_employer_income'   => $prevIncome,
                'gross_total_salary'         => $grossTotal,
                'taxable_earnings'           => $taxable,
                'exemptions' => [
                    'hra' => ['amount' => round((float) ($hra['amount'] ?? 0), 2),
                              'legs' => $hra['legs'] ?? [], 'reason' => $hra['reason'] ?? null],
                    'total' => $exemptions,
                ],
                'standard_deduction' => $standard,
            ],
            'chapter_via' => [
                'sections' => $chapterVia,
                'total'    => $totalDeduct,
            ],
            'tax' => [
                'taxable_income'   => (float) ($last->annual_taxable_income ?? 0),
                'tax_liability'    => (float) ($last->annual_tax_liability ?? 0),
                'tax_before_rebate' => (float) ($working['tax_before_rebate'] ?? 0),
                'surcharge'        => (float) ($working['surcharge'] ?? 0),
                'cess'             => (float) ($working['cess'] ?? 0),
            ],
            'tds' => [
                'deducted_this_employer'  => $tdsDeducted,
                'deducted_previous'       => $prevTds,
                'total_deducted'          => round($tdsDeducted + $prevTds, 2),
                // Positive = still owed, negative = over-deducted. Named this way
                // because "balance" alone reads as a refund to half the audience.
                'balance_payable'         => round((float) ($last->annual_tax_liability ?? 0) - $tdsDeducted - $prevTds, 2),
            ],
            'other' => [
                'professional_tax' => round($ptPaid + $prevPt, 2),
                'pf_employee'      => $pfEmployee,
            ],
            // Month-by-month annexure, straight from the frozen records.
            'monthly' => $records->map(fn ($r) => [
                'period'           => $r->attendance_period,
                'gross'            => (float) $r->gross_salary,
                'taxable'          => (float) $r->taxable_earnings,
                'pf_employee'      => (float) $r->pf_employee,
                'professional_tax' => (float) $r->pt_amount,
                'tds'              => (float) $r->tds_amount,
            ])->all(),
            'declaration' => $declaration ? [
                'status' => $declaration->status, 'regime' => $declaration->regime,
                'counts_for_tax' => $declaration->countsForTax(),
                'declared_total' => (float) $declaration->declared_total,
                'verified_total' => (float) $declaration->verified_total,
            ] : null,
            'warnings' => $this->warnings($records->count(), $declaration, $regime),
        ];
    }

    /** Financial years this employee actually has processed payroll in. */
    public function availableYears(int $employeeId, int $tenantId): array
    {
        $startMonth = (int) $this->settings->get($tenantId, 'payroll', 'fy_start_month', 4);

        $periods = HrPayrollRecord::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('status', HrPayrollRecord::PROCESSED)
            ->whereNotNull('attendance_period')
            ->distinct()->pluck('attendance_period');

        $years = [];
        foreach ($periods as $p) {
            $fy = FinancialYear::forDate($p.'-01', $startMonth);
            $years[$fy->label()] = ['label' => $fy->label(), 'short' => $fy->shortLabel()];
        }
        krsort($years);

        return array_values($years);
    }

    /* ── Helpers ──────────────────────────────────────────────────────── */

    /** The frozen TDS working from a record's statutory_meta, if the engine wrote one. */
    private function workingFrom(?HrPayrollRecord $record): array
    {
        if (! $record) {
            return [];
        }
        $meta = is_array($record->statutory_meta)
            ? $record->statutory_meta
            : json_decode((string) $record->statutory_meta, true);

        return (array) ($meta['tds_detail']['working'] ?? []);
    }

    /**
     * Chapter VI-A lines.
     *
     * Prefers the frozen working — those are the amounts tax was ACTUALLY computed
     * on, caps applied. Falls back to the declaration itself so a year with no
     * processed payroll still shows what was claimed, clearly marked as unapplied.
     */
    private function chapterViaBreakdown(?HrInvestmentDeclaration $declaration, array $working): array
    {
        if (! empty($working['chapter_via']['sections'])) {
            return array_map(fn ($s) => $s + ['label' => TaxSections::label($s['section'])],
                $working['chapter_via']['sections']);
        }

        if (! $declaration) {
            return [];
        }

        $out = [];
        foreach ($declaration->items->groupBy('section') as $section => $items) {
            $claimed = round((float) $items->sum(fn ($i) => (float) ($i->verified_amount ?? $i->declared_amount)), 2);
            if ($claimed <= 0) {
                continue;
            }
            $out[] = ['section' => $section, 'label' => TaxSections::label($section),
                      'claimed' => $claimed, 'limit' => null, 'allowed' => 0.0,
                      'note' => 'Claimed but not applied to any processed payroll month'];
        }

        return $out;
    }

    private function warnings(int $months, ?HrInvestmentDeclaration $declaration, ?string $regime): array
    {
        $w = [];

        if ($months === 0) {
            $w[] = 'No processed payroll exists for this year — every figure below is zero.';
        } elseif ($months < 12) {
            $w[] = "Only {$months} of 12 months are processed. Figures are year-to-date, not final.";
        }
        if (! $declaration) {
            $w[] = 'No investment declaration on record — tax was computed with no deductions.';
        } elseif (! $declaration->countsForTax()) {
            $w[] = "The declaration is {$declaration->status}, so its deductions did not reduce tax. Verify it to apply them.";
        }
        if (! $regime) {
            $w[] = 'No TDS rule applied in this year, so no regime was recorded.';
        }

        return $w;
    }
}
