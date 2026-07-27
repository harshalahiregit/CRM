<?php

namespace App\Repositories\Hr;

use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregate queries for the Enterprise Salary Reports. Every figure comes
 * from the persisted structure totals and the frozen employee-salary snapshots (and
 * the revision ledger) — nothing is recalculated. Tenant-scoped throughout.
 */
class SalaryReportRepository
{
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
    public function employeeSalaries(int $tenantId, array $f)
    {
        return DB::table('hr_employee_salaries as es')
            ->join('hr_employees as e', 'es.employee_id', '=', 'e.id')
            ->leftJoin('hr_salary_structures as s', 'es.salary_structure_id', '=', 's.id')
            ->leftJoin('hr_grades as g', 'e.grade_id', '=', 'g.id')
            ->where('es.tenant_id', $tenantId)
            ->where('es.status', 'active')
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
    public function costByDimension(int $tenantId, string $dimension, array $f)
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
    public function revisions(int $tenantId, array $f)
    {
        return DB::table('hr_salary_revisions as r')
            ->join('hr_employees as e', 'r.employee_id', '=', 'e.id')
            ->leftJoin('hr_salary_structures as s', 'r.to_structure_id', '=', 's.id')
            ->leftJoin('users as u', 'r.changed_by', '=', 'u.id')
            ->where('r.tenant_id', $tenantId)
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

    public function filterOptions(int $tenantId): array
    {
        return [
            'departments'  => DB::table('hr_employees')->where('tenant_id', $tenantId)->whereNotNull('department')->distinct()->orderBy('department')->pluck('department')->all(),
            'designations' => DB::table('hr_employees')->where('tenant_id', $tenantId)->whereNotNull('designation')->distinct()->orderBy('designation')->pluck('designation')->all(),
            'grades'       => DB::table('hr_grades')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name'])->all(),
            'structures'   => DB::table('hr_salary_structures')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name'])->all(),
        ];
    }
}
