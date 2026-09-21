<?php

namespace App\Repositories\Hr;

use App\Models\User;
use App\Services\Auth\ScopeResolver;
use App\Support\Hr\DataScope;
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
    /**
     * Base join: records ⨝ completed runs ⨝ employees, with the shared filters.
     *
     * Every figure this class produces — the summary tiles, the per-employee
     * rows, the department rollup, the CSV and the PDF — is built from here, so
     * the scope goes here rather than onto each of them. A total computed from
     * an unscoped set and shown beside a scoped list is worse than either: two
     * numbers on one screen that cannot both be right.
     *
     * Applied as an AND alongside the filters, which is what stops a filter
     * being used to widen: supplying employee_id for somebody in another
     * department narrows within the scope and cannot reach outside it.
     *
     * BRANCH is not in the supported list — hr_employees.branch is free text
     * with no master and no data, so a role scoped to it reads as global here,
     * exactly as in Phase 1.
     */
    private function base(int $tenantId, array $f, ?User $actor = null)
    {
        $q = DB::table('hr_payroll_records as r')
            ->join('hr_payroll_runs as run', 'r.payroll_run_id', '=', 'run.id')
            ->join('hr_employees as e', 'r.employee_id', '=', 'e.id')
            ->where('r.tenant_id', $tenantId)
            ->where('run.status', 'Completed');

        $q = $this->scoped($q, $actor, 'r.employee_id');

        if (! empty($f['year']))        { $q->where('run.payroll_year', $f['year']); }
        if (! empty($f['month']))       { $q->where('run.payroll_month', $f['month']); }
        if (! empty($f['department']))  { $q->where('e.department', $f['department']); }
        if (! empty($f['designation'])) { $q->where('e.designation', $f['designation']); }
        if (! empty($f['employee_id'])) { $q->where('r.employee_id', $f['employee_id']); }

        return $q;
    }

    /** The one place this class talks to the scope resolver. */
    private function scoped($query, ?User $actor, string $column)
    {
        return app(ScopeResolver::class)->applyToQuery($query, $actor, $column, [
            DataScope::OWN, DataScope::DEPARTMENT, DataScope::TEAM,
        ]);
    }

    /**
     * The period expressions, in SQL, mirroring HrPayrollRecord::periodGross(),
     * periodDeductions(), netPayable() and employerContributions().
     *
     * Reports used to aggregate r.gross_salary / r.total_deductions / r.net_salary
     * directly. Those three are the FROZEN SALARY-STRUCTURE snapshot: deductions
     * is 0 for every structure that defines none of its own — i.e. all of them,
     * because PF, ESIC, PT and LWF are statutory and resolved per period — and
     * net equals gross. So every payroll report showed a company withholding
     * nothing from anybody, beside a payroll hub that showed ₹8,261 withheld from
     * the same run.
     *
     * Kept as constants rather than repeated inline so summary() and
     * departments() cannot drift apart from each other or from the model.
     */
    private const GROSS_SQL = '(COALESCE(r.gross_salary,0) + COALESCE(r.variable_earnings,0) + COALESCE(r.overtime_amount,0) + COALESCE(r.adjustment_total,0))';

    private const DEDUCTIONS_SQL = '(COALESCE(r.total_deductions,0) + COALESCE(r.statutory_deductions,0) + COALESCE(r.loan_deduction,0) + COALESCE(r.late_mark_deduction,0))';

    private const NET_PAYABLE_SQL = '(COALESCE(r.net_salary,0) + COALESCE(r.variable_earnings,0) + COALESCE(r.overtime_amount,0) + COALESCE(r.adjustment_total,0) - COALESCE(r.statutory_deductions,0) - COALESCE(r.loan_deduction,0) - COALESCE(r.late_mark_deduction,0))';

    /** Company cost, NEVER withheld from an employee — reported separately. */
    private const EMPLOYER_SQL = '(COALESCE(r.pf_employer,0) + COALESCE(r.eps_employer,0) + COALESCE(r.esic_employer,0) + COALESCE(r.lwf_employer,0) + COALESCE(r.wcp_employer,0) + COALESCE(r.mediclaim_employer,0))';

    /** Single-row totals for the KPI cards. */
    public function summary(int $tenantId, array $f, ?User $actor = null): object
    {
        return $this->base($tenantId, $f, $actor)
            ->selectRaw('COUNT(*) as employees,
                COALESCE(SUM('.self::GROSS_SQL.'),0)      as gross,
                COALESCE(SUM(r.total_benefits),0)         as benefits,
                COALESCE(SUM('.self::DEDUCTIONS_SQL.'),0) as deductions,
                COALESCE(SUM('.self::EMPLOYER_SQL.'),0)   as employer_contributions,
                COALESCE(SUM('.self::NET_PAYABLE_SQL.'),0) as net')
            ->first();
    }

    /** Employee-wise rows (structure + payslip status via left joins). */
    public function employees(int $tenantId, array $f, ?User $actor = null): Collection
    {
        return collect(
            $this->base($tenantId, $f, $actor)
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
                    r.overtime_amount, r.late_mark_deduction, r.adjustment_total,
                    ".self::GROSS_SQL." as period_gross,
                    ".self::DEDUCTIONS_SQL." as period_deductions,
                    ".self::EMPLOYER_SQL." as employer_contributions,
                    ".self::NET_PAYABLE_SQL." as net_payable,
                    COALESCE(ps.status, 'Pending') as payslip_status,
                    run.payroll_year, run.payroll_month")
                ->orderBy('e.name')
                ->get()
        );
    }

    /** Department-wise aggregates. */
    public function departments(int $tenantId, array $f, ?User $actor = null): Collection
    {
        return collect(
            $this->base($tenantId, $f, $actor)
                ->groupBy('e.department')
                ->selectRaw("COALESCE(e.department,'Unassigned') as department,
                    COUNT(*) as employees,
                    COALESCE(SUM(".self::GROSS_SQL."),0)      as gross,
                    COALESCE(SUM(r.total_benefits),0)         as benefits,
                    COALESCE(SUM(".self::DEDUCTIONS_SQL."),0) as deductions,
                    COALESCE(SUM(".self::EMPLOYER_SQL."),0)   as employer_contributions,
                    COALESCE(SUM(".self::NET_PAYABLE_SQL."),0) as net")
                ->orderByDesc('net')
                ->get()
        );
    }

    /** Frozen payslip breakdown JSON for component analysis (decoded in the service). */
    public function payslipBreakdowns(int $tenantId, array $f, ?User $actor = null): Collection
    {
        // Its own query rather than base(), so it needs its own scope call —
        // a payslip breakdown is the most employee-level thing here.
        $q = DB::table('hr_payslips as ps')
            ->join('hr_employees as e', 'ps.employee_id', '=', 'e.id')
            ->where('ps.tenant_id', $tenantId)
            ->where('ps.status', 'Generated');

        $q = $this->scoped($q, $actor, 'ps.employee_id');

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
    /**
     * The dropdown contents — scoped, because a filter list is data too.
     *
     * `employees` here is a list of names and codes. Leaving it tenant-wide
     * would hand a department-scoped user the whole staff directory in a
     * <select>, which is the same disclosure as the report itself with extra
     * steps. Departments and designations are derived from the same scoped set
     * rather than from every employee, so the filters offered are the filters
     * that can actually return something.
     */
    public function filterOptions(int $tenantId, ?User $actor = null): array
    {
        $employees = $this->scoped(
            DB::table('hr_employees')->where('tenant_id', $tenantId),
            $actor,
            'id',
        );

        return [
            // Years come from runs, not people — a period is not employee data.
            'years' => DB::table('hr_payroll_runs')->where('tenant_id', $tenantId)->where('status', 'Completed')
                ->distinct()->orderByDesc('payroll_year')->pluck('payroll_year')->all(),
            'departments' => (clone $employees)->whereNotNull('department')
                ->where('department', '!=', '')->distinct()->orderBy('department')->pluck('department')->all(),
            'designations' => (clone $employees)->whereNotNull('designation')
                ->where('designation', '!=', '')->distinct()->orderBy('designation')->pluck('designation')->all(),
            'employees' => (clone $employees)->orderBy('name')->get(['id', 'name', 'employee_code'])->all(),
        ];
    }
}
