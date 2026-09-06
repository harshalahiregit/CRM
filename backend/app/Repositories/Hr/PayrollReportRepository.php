<?php

namespace App\Repositories\Hr;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregation over the existing frozen payroll data (Payroll Phase 6).
 *
 * Nothing is recomputed and nothing is written — every figure comes from the
 * snapshots stored in hr_payroll_records / hr_payroll_runs / hr_payslips. All
 * queries are tenant-scoped and restricted to Completed runs. Aggregates are done
 * in SQL (single grouped queries) to avoid N+1.
 */
class PayrollReportRepository
{
    /** Base join: records ⨝ completed runs ⨝ employees, with the shared filters applied. */
    private function base(int $tenantId, array $f)
    {
        $q = DB::table('hr_payroll_records as r')
            ->join('hr_payroll_runs as run', 'r.payroll_run_id', '=', 'run.id')
            ->join('hr_employees as e', 'r.employee_id', '=', 'e.id')
            ->where('r.tenant_id', $tenantId)
            ->where('run.status', 'Completed');

        if (! empty($f['year']))        { $q->where('run.payroll_year', $f['year']); }
        if (! empty($f['month']))       { $q->where('run.payroll_month', $f['month']); }
        if (! empty($f['department']))  { $q->where('e.department', $f['department']); }
        if (! empty($f['designation'])) { $q->where('e.designation', $f['designation']); }
        if (! empty($f['employee_id'])) { $q->where('r.employee_id', $f['employee_id']); }

        return $q;
    }

    /** Single-row totals for the KPI cards. */
    public function summary(int $tenantId, array $f): object
    {
        return $this->base($tenantId, $f)
            ->selectRaw('COUNT(*) as employees,
                COALESCE(SUM(r.gross_salary),0)     as gross,
                COALESCE(SUM(r.total_benefits),0)   as benefits,
                COALESCE(SUM(r.total_deductions),0) as deductions,
                COALESCE(SUM(r.net_salary),0)       as net')
            ->first();
    }

    /** Employee-wise rows (structure + payslip status via left joins). */
    public function employees(int $tenantId, array $f): Collection
    {
        return collect(
            $this->base($tenantId, $f)
                ->leftJoin('hr_employee_salaries as es', 'r.employee_salary_id', '=', 'es.id')
                ->leftJoin('hr_salary_structures as st', 'es.salary_structure_id', '=', 'st.id')
                ->leftJoin('hr_payslips as ps', function ($j) use ($tenantId) {
                    $j->on('ps.payroll_record_id', '=', 'r.id')->where('ps.tenant_id', '=', $tenantId);
                })
                // statutory_deductions, loan_deduction and variable_earnings are
                // selected because `net_salary` on a payroll record is the FROZEN
                // structural net — it does not include this period's statutory
                // split (PF, ESIC, PT, TDS), any loan instalment, or variable
                // earnings. Without them the report cannot show what actually
                // reaches the bank. All three sit on the same row under the same
                // alias, so this costs no extra join.
                ->selectRaw("e.name, e.employee_code, e.department, e.designation,
                    st.name as structure_name,
                    r.gross_salary, r.total_benefits, r.total_deductions, r.net_salary,
                    r.statutory_deductions, r.loan_deduction, r.variable_earnings,
                    COALESCE(ps.status, 'Pending') as payslip_status,
                    run.payroll_year, run.payroll_month")
                ->orderBy('e.name')
                ->get()
        );
    }

    /** Department-wise aggregates. */
    public function departments(int $tenantId, array $f): Collection
    {
        return collect(
            $this->base($tenantId, $f)
                ->groupBy('e.department')
                ->selectRaw("COALESCE(e.department,'Unassigned') as department,
                    COUNT(*) as employees,
                    COALESCE(SUM(r.gross_salary),0)     as gross,
                    COALESCE(SUM(r.total_benefits),0)   as benefits,
                    COALESCE(SUM(r.total_deductions),0) as deductions,
                    COALESCE(SUM(r.net_salary),0)       as net")
                ->orderByDesc('net')
                ->get()
        );
    }

    /** Frozen payslip breakdown JSON for component analysis (decoded in the service). */
    public function payslipBreakdowns(int $tenantId, array $f): Collection
    {
        $q = DB::table('hr_payslips as ps')
            ->join('hr_employees as e', 'ps.employee_id', '=', 'e.id')
            ->where('ps.tenant_id', $tenantId)
            ->where('ps.status', 'Generated');

        if (! empty($f['year']))        { $q->where('ps.payslip_year', $f['year']); }
        if (! empty($f['month']))       { $q->where('ps.payslip_month', $f['month']); }
        if (! empty($f['department']))  { $q->where('e.department', $f['department']); }
        if (! empty($f['designation'])) { $q->where('e.designation', $f['designation']); }
        if (! empty($f['employee_id'])) { $q->where('ps.employee_id', $f['employee_id']); }

        return collect($q->pluck('ps.breakdown'));
    }

    /** Completed runs as a chronological series (for trends). */
    public function completedRuns(int $tenantId, array $f): Collection
    {
        $q = DB::table('hr_payroll_runs')->where('tenant_id', $tenantId)->where('status', 'Completed');
        if (! empty($f['year'])) { $q->where('payroll_year', $f['year']); }

        return collect($q->orderBy('payroll_year')->orderBy('payroll_month')
            ->get(['id', 'payroll_year', 'payroll_month', 'total_employees', 'total_gross', 'total_deductions', 'total_net']));
    }

    /** Employer-benefit totals per run (runs table stores gross/deductions/net but not benefits). */
    public function benefitsByRun(int $tenantId, array $runIds): array
    {
        if (empty($runIds)) {
            return [];
        }

        return DB::table('hr_payroll_records')
            ->where('tenant_id', $tenantId)
            ->whereIn('payroll_run_id', $runIds)
            ->groupBy('payroll_run_id')
            ->selectRaw('payroll_run_id, COALESCE(SUM(total_benefits),0) as benefits')
            ->pluck('benefits', 'payroll_run_id')
            ->all();
    }

    /** Distinct values that populate the report filter bar. */
    public function filterOptions(int $tenantId): array
    {
        return [
            'years' => DB::table('hr_payroll_runs')->where('tenant_id', $tenantId)->where('status', 'Completed')
                ->distinct()->orderByDesc('payroll_year')->pluck('payroll_year')->all(),
            'departments' => DB::table('hr_employees')->where('tenant_id', $tenantId)->whereNotNull('department')
                ->where('department', '!=', '')->distinct()->orderBy('department')->pluck('department')->all(),
            'designations' => DB::table('hr_employees')->where('tenant_id', $tenantId)->whereNotNull('designation')
                ->where('designation', '!=', '')->distinct()->orderBy('designation')->pluck('designation')->all(),
            'employees' => DB::table('hr_employees')->where('tenant_id', $tenantId)->orderBy('name')
                ->get(['id', 'name', 'employee_code'])->all(),
        ];
    }
}
