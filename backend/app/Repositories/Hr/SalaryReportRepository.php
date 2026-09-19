<?php

namespace App\Repositories\Hr;

use App\Models\User;
use App\Services\Auth\ScopeResolver;
use App\Support\Hr\DataScope;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregate queries for the Enterprise Salary Reports. Every figure comes
 * from the persisted structure totals and the frozen employee-salary snapshots (and
 * the revision ledger) — nothing is recalculated. Tenant-scoped throughout.
 */
class SalaryReportRepository
{
    /**
     * The one place this class talks to the scope resolver.
     *
     * Scope decides WHO contributes to a report and nothing else. The
     * effective-salary selection (`es.status = 'active'`), the frozen snapshot
     * columns and the revision ledger's ordering are untouched — a
     * department-scoped user sees the same figures for their own people that a
     * global user sees for everybody.
     *
     * structures() and components() are deliberately NOT scoped: a salary
     * structure is a template and a component is a definition. Neither names a
     * person, and hiding the pay-scale catalogue from a department head would
     * withhold policy rather than protect privacy.
     *
     * BRANCH is excluded as in Phase 1.
     */
    private function scoped($query, ?User $actor, string $column)
    {
        return app(ScopeResolver::class)->applyToQuery($query, $actor, $column, [
            DataScope::OWN, DataScope::DEPARTMENT, DataScope::TEAM,
        ]);
    }

    /** Salary structures with their (denormalised) computed totals. */
    public function structures(int $tenantId, array $f)
    {
        return DB::table('hr_salary_structures as s')
            ->leftJoin('hr_grades as g', 's.grade_id', '=', 'g.id')
            ->leftJoin('hr_designations as d', 's.designation_id', '=', 'd.id')
            ->where('s.tenant_id', $tenantId)
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('s.is_active', $f['status'] === 'Active'))
            ->when(! empty($f['structure_id']), fn ($q) => $q->where('s.id', $f['structure_id']))
            ->orderByDesc('s.monthly_ctc')
            ->get(['s.id', 's.name', 's.code', 'g.name as grade', 'd.name as designation', 's.is_active',
                's.gross_salary', 's.employer_contribution', 's.monthly_ctc', 's.annual_ctc', 's.total_deduction', 's.net_salary']);
    }

    /** Salary component master + how many structures reference each. */
    public function components(int $tenantId, array $f)
    {
        return DB::table('hr_salary_components as c')
            ->leftJoin('hr_salary_structure_lines as l', 'l.component_id', '=', 'c.id')
            ->where('c.tenant_id', $tenantId)
            ->when(! empty($f['type']) && $f['type'] !== 'All', fn ($q) => $q->where('c.type', $f['type']))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('c.is_active', $f['status'] === 'Active'))
            ->groupBy('c.id', 'c.name', 'c.code', 'c.type', 'c.calculation_type', 'c.taxable', 'c.pf_applicable', 'c.esic_applicable', 'c.is_active', 'c.sequence')
            ->orderBy('c.sequence')->orderBy('c.type')
            ->get([
                'c.name', 'c.code', 'c.type', 'c.calculation_type', 'c.taxable', 'c.pf_applicable', 'c.esic_applicable', 'c.is_active',
                DB::raw('COUNT(l.id) as usage_count'),
            ]);
    }

    /** Active employee salary snapshots joined to the employee + structure. */
    public function employeeSalaries(int $tenantId, array $f, ?User $actor = null)
    {
        $q = DB::table('hr_employee_salaries as es')
            ->join('hr_employees as e', 'es.employee_id', '=', 'e.id')
            ->leftJoin('hr_salary_structures as s', 'es.salary_structure_id', '=', 's.id')
            ->leftJoin('hr_grades as g', 'e.grade_id', '=', 'g.id')
            ->where('es.tenant_id', $tenantId)
            ->where('es.status', 'active');

        // Scope before the filters, so an employee_id from outside returns
        // nothing rather than reaching that person's CTC.
        $q = $this->scoped($q, $actor, 'es.employee_id');

        return $q
            ->when(! empty($f['department']), fn ($q) => $q->where('e.department', $f['department']))
            ->when(! empty($f['designation']), fn ($q) => $q->where('e.designation', $f['designation']))
            ->when(! empty($f['grade_id']), fn ($q) => $q->where('e.grade_id', $f['grade_id']))
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('e.id', $f['employee_id']))
            ->orderByDesc('es.monthly_ctc')
            ->get([
                'e.name', 'e.employee_code', 'e.department', 'e.designation', 'g.name as grade',
                's.name as structure_name',
                'es.monthly_ctc', 'es.annual_ctc', 'es.gross_salary', 'es.total_benefits', 'es.total_deductions', 'es.net_salary',
            ]);
    }

    /**
     * Salary cost grouped by a dimension: 'department' | 'designation' | 'grade'.
     * Uses the active snapshot's monthly/annual CTC. Tenant-scoped.
     */
    public function costByDimension(int $tenantId, string $dimension, array $f, ?User $actor = null)
    {
        $col = match ($dimension) {
            'designation' => 'e.designation',
            'grade'       => 'g.name',
            default       => 'e.department',
        };

        $q = DB::table('hr_employee_salaries as es')
            ->join('hr_employees as e', 'es.employee_id', '=', 'e.id')
            ->where('es.tenant_id', $tenantId)
            ->where('es.status', 'active');

        // Every SUM below is built from this set, so the scope goes on before
        // the grouping — the department subtotals and the headcount count the
        // same people.
        $q = $this->scoped($q, $actor, 'es.employee_id');

        if ($dimension === 'grade') {
            $q->leftJoin('hr_grades as g', 'e.grade_id', '=', 'g.id');
        }

        return $q->groupBy($col)
            ->orderByDesc(DB::raw('SUM(es.monthly_ctc)'))
            ->get([
                DB::raw("COALESCE($col, '—') as label"),
                DB::raw('COUNT(*) as employees'),
                DB::raw('SUM(es.gross_salary) as gross'),
                DB::raw('SUM(es.total_benefits) as employer'),
                DB::raw('SUM(es.total_deductions) as deductions'),
                DB::raw('SUM(es.monthly_ctc) as monthly_ctc'),
                DB::raw('SUM(es.annual_ctc) as annual_ctc'),
            ]);
    }

    /** Append-only salary revision ledger across all employees. */
    public function revisions(int $tenantId, array $f, ?User $actor = null)
    {
        $q = DB::table('hr_salary_revisions as r')
            ->join('hr_employees as e', 'r.employee_id', '=', 'e.id')
            ->leftJoin('hr_salary_structures as s', 'r.to_structure_id', '=', 's.id')
            ->leftJoin('users as u', 'r.changed_by', '=', 'u.id')
            ->where('r.tenant_id', $tenantId);

        // A revision row carries the previous and new CTC — a pay-rise history,
        // which is among the most sensitive rows in the module.
        $q = $this->scoped($q, $actor, 'r.employee_id');

        return $q
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('e.id', $f['employee_id']))
            ->when(! empty($f['department']), fn ($q) => $q->where('e.department', $f['department']))
            ->orderByDesc('r.id')
            ->get([
                'e.name', 'e.employee_code', 'e.department', 's.name as to_structure',
                'r.revision_no', 'r.effective_from', 'r.reason',
                'r.previous_monthly_ctc', 'r.new_monthly_ctc', 'r.new_annual_ctc', 'r.new_net_salary',
                'u.name as changed_by',
            ]);
    }

    /** Grades and structures are the pay-scale catalogue and stay whole. */
    public function filterOptions(int $tenantId, ?User $actor = null): array
    {
        $employees = $this->scoped(DB::table('hr_employees')->where('tenant_id', $tenantId), $actor, 'id');

        return [
            'departments'  => (clone $employees)->whereNotNull('department')->distinct()->orderBy('department')->pluck('department')->all(),
            'designations' => (clone $employees)->whereNotNull('designation')->distinct()->orderBy('designation')->pluck('designation')->all(),
            'grades'       => DB::table('hr_grades')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name'])->all(),
            'structures'   => DB::table('hr_salary_structures')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name'])->all(),
        ];
    }
}
