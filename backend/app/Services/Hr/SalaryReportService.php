<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Repositories\Hr\SalaryReportRepository;

/**
 * Enterprise Salary Reports (read-only). Ten reports over the salary structures,
 * frozen employee-salary snapshots and the revision ledger. Every report is shaped
 * into one uniform payload — {title, columns, rows} — so a single frontend viewer and
 * a single CSV/PDF exporter serve them all. No figure is recalculated here.
 */
class SalaryReportService
{
    public const REPORTS = [
        'structures'             => 'Salary Structure Report',
        'components'             => 'Salary Component Report',
        'employees'              => 'Employee Salary Report',
        'department-cost'        => 'Department Salary Cost',
        'designation-cost'       => 'Designation Salary Cost',
        'grade-cost'             => 'Grade Salary Cost',
        'gross-vs-net'           => 'Gross vs Net Salary',
        'employer-contribution'  => 'Employer Contribution Report',
        'deductions'             => 'Deduction Report',
        'revisions'              => 'Salary Revision History',
    ];

    public function __construct(private SalaryReportRepository $repo)
    {
    }

    /** KPI cards over the active employee salaries. */
    public function summary(int $tenantId, array $f): array
    {
        $rows = $this->repo->employeeSalaries($tenantId, $f);
        $n = $rows->count();
        $sum = fn ($k) => round((float) $rows->sum($k), 2);

        return [
            'employees'        => $n,
            'total_monthly_ctc'=> $sum('monthly_ctc'),
            'total_annual_ctc' => $sum('annual_ctc'),
            'average_ctc'      => $n ? round($sum('monthly_ctc') / $n, 2) : 0.0,
            'total_gross'      => $sum('gross_salary'),
            'total_employer'   => $sum('total_benefits'),
            'total_deductions' => $sum('total_deductions'),
            'total_net'        => $sum('net_salary'),
        ];
    }

    public function reports(): array
    {
        return array_map(fn ($k, $v) => ['key' => $k, 'label' => $v], array_keys(self::REPORTS), array_values(self::REPORTS));
    }

    public function filterOptions(int $tenantId): array
    {
        return $this->repo->filterOptions($tenantId);
    }

    /** Uniform report payload for the frontend viewer: {title, columns, rows}. */
    public function build(string $report, int $tenantId, array $f): array
    {
        if (! isset(self::REPORTS[$report])) {
            throw new BusinessException('Unknown salary report.', 404);
        }
        $title = self::REPORTS[$report];
        [$columns, $rows] = $this->rowsFor($report, $tenantId, $f);

        return ['report' => $report, 'title' => $title, 'columns' => $columns, 'rows' => $rows];
    }

    /** Export shape for CSV/PDF (reuses the generic pdf.payroll_report blade). */
    public function exportRows(string $report, int $tenantId, array $f): array
    {
        $built = $this->build($report, $tenantId, $f);
        $headers = array_map(fn ($c) => $c['label'], $built['columns']);
        $rows = array_map(function ($r) use ($built) {
            return array_map(fn ($c) => $r[$c['key']] ?? '', $built['columns']);
        }, $built['rows']);

        return ['title' => $built['title'], 'headers' => $headers, 'rows' => $rows];
    }

    /*
    |--------------------------------------------------------------------------
    | Per-report column definitions + row mapping
    |--------------------------------------------------------------------------
    */
    private function rowsFor(string $report, int $tenantId, array $f): array
    {
        $col = fn ($key, $label, $numeric = false) => ['key' => $key, 'label' => $label, 'numeric' => $numeric];
        $pct = fn ($part, $whole) => $whole > 0 ? round($part / $whole * 100, 2) : 0.0;
        $yn = fn ($v) => $v ? 'Yes' : 'No';

        switch ($report) {
            case 'structures':
                $columns = [$col('name', 'Structure'), $col('code', 'Code'), $col('grade', 'Grade'), $col('designation', 'Designation'),
                    $col('gross', 'Gross', true), $col('employer', 'Employer', true), $col('monthly_ctc', 'Monthly CTC', true),
                    $col('annual_ctc', 'Annual CTC', true), $col('deductions', 'Deductions', true), $col('net', 'Net', true), $col('status', 'Status')];
                $rows = $this->repo->structures($tenantId, $f)->map(fn ($s) => [
                    'name' => $s->name, 'code' => $s->code, 'grade' => $s->grade ?: '—', 'designation' => $s->designation ?: '—',
                    'gross' => (float) $s->gross_salary, 'employer' => (float) $s->employer_contribution, 'monthly_ctc' => (float) $s->monthly_ctc,
                    'annual_ctc' => (float) $s->annual_ctc, 'deductions' => (float) $s->total_deduction, 'net' => (float) $s->net_salary,
                    'status' => $s->is_active ? 'Active' : 'Inactive',
                ])->all();
                break;

            case 'components':
                $columns = [$col('name', 'Component'), $col('code', 'Code'), $col('type', 'Type'), $col('calc', 'Calculation'),
                    $col('taxable', 'Taxable'), $col('pf', 'PF'), $col('esic', 'ESIC'), $col('usage', 'Used In', true), $col('status', 'Status')];
                $rows = $this->repo->components($tenantId, $f)->map(fn ($c) => [
                    'name' => $c->name, 'code' => $c->code, 'type' => $c->type, 'calc' => $c->calculation_type,
                    'taxable' => $yn($c->taxable), 'pf' => $yn($c->pf_applicable), 'esic' => $yn($c->esic_applicable),
                    'usage' => (int) $c->usage_count, 'status' => $c->is_active ? 'Active' : 'Inactive',
                ])->all();
                break;

            case 'employees':
                $columns = [$col('name', 'Employee'), $col('code', 'Code'), $col('department', 'Department'), $col('designation', 'Designation'),
                    $col('structure', 'Structure'), $col('monthly_ctc', 'Monthly CTC', true), $col('annual_ctc', 'Annual CTC', true),
                    $col('gross', 'Gross', true), $col('employer', 'Employer', true), $col('deductions', 'Deductions', true), $col('net', 'Net', true)];
                $rows = $this->repo->employeeSalaries($tenantId, $f)->map(fn ($e) => [
                    'name' => $e->name, 'code' => $e->employee_code, 'department' => $e->department ?: '—', 'designation' => $e->designation ?: '—',
                    'structure' => $e->structure_name ?: '—', 'monthly_ctc' => (float) $e->monthly_ctc, 'annual_ctc' => (float) $e->annual_ctc,
                    'gross' => (float) $e->gross_salary, 'employer' => (float) $e->total_benefits, 'deductions' => (float) $e->total_deductions, 'net' => (float) $e->net_salary,
                ])->all();
                break;

            case 'department-cost':
            case 'designation-cost':
            case 'grade-cost':
                $dim = ['department-cost' => 'department', 'designation-cost' => 'designation', 'grade-cost' => 'grade'][$report];
                $label = ['department' => 'Department', 'designation' => 'Designation', 'grade' => 'Grade'][$dim];
                $columns = [$col('label', $label), $col('employees', 'Employees', true), $col('gross', 'Gross', true),
                    $col('employer', 'Employer', true), $col('deductions', 'Deductions', true), $col('monthly_ctc', 'Monthly CTC', true), $col('annual_ctc', 'Annual CTC', true)];
                $rows = $this->repo->costByDimension($tenantId, $dim, $f)->map(fn ($r) => [
                    'label' => $r->label, 'employees' => (int) $r->employees, 'gross' => (float) $r->gross, 'employer' => (float) $r->employer,
                    'deductions' => (float) $r->deductions, 'monthly_ctc' => (float) $r->monthly_ctc, 'annual_ctc' => (float) $r->annual_ctc,
                ])->all();
                break;

            case 'gross-vs-net':
                $columns = [$col('name', 'Employee'), $col('code', 'Code'), $col('department', 'Department'),
                    $col('gross', 'Gross', true), $col('deductions', 'Deductions', true), $col('net', 'Net', true), $col('net_pct', 'Net % of Gross', true)];
                $rows = $this->repo->employeeSalaries($tenantId, $f)->map(fn ($e) => [
                    'name' => $e->name, 'code' => $e->employee_code, 'department' => $e->department ?: '—',
                    'gross' => (float) $e->gross_salary, 'deductions' => (float) $e->total_deductions, 'net' => (float) $e->net_salary,
                    'net_pct' => $pct((float) $e->net_salary, (float) $e->gross_salary),
                ])->all();
                break;

            case 'employer-contribution':
                $columns = [$col('name', 'Employee'), $col('code', 'Code'), $col('department', 'Department'), $col('structure', 'Structure'),
                    $col('employer', 'Employer Contribution', true), $col('monthly_ctc', 'CTC', true), $col('pct', '% of CTC', true)];
                $rows = $this->repo->employeeSalaries($tenantId, $f)->map(fn ($e) => [
                    'name' => $e->name, 'code' => $e->employee_code, 'department' => $e->department ?: '—', 'structure' => $e->structure_name ?: '—',
                    'employer' => (float) $e->total_benefits, 'monthly_ctc' => (float) $e->monthly_ctc, 'pct' => $pct((float) $e->total_benefits, (float) $e->monthly_ctc),
                ])->all();
                break;

            case 'deductions':
                $columns = [$col('name', 'Employee'), $col('code', 'Code'), $col('department', 'Department'),
                    $col('gross', 'Gross', true), $col('deductions', 'Total Deductions', true), $col('pct', '% of Gross', true)];
                $rows = $this->repo->employeeSalaries($tenantId, $f)->map(fn ($e) => [
                    'name' => $e->name, 'code' => $e->employee_code, 'department' => $e->department ?: '—',
                    'gross' => (float) $e->gross_salary, 'deductions' => (float) $e->total_deductions, 'pct' => $pct((float) $e->total_deductions, (float) $e->gross_salary),
                ])->all();
                break;

            case 'revisions':
            default:
                $columns = [$col('name', 'Employee'), $col('code', 'Code'), $col('revision_no', 'Rev #', true), $col('effective_from', 'Effective'),
                    $col('to_structure', 'Structure'), $col('prev_ctc', 'Prev CTC', true), $col('new_ctc', 'New CTC', true), $col('reason', 'Reason'), $col('changed_by', 'By')];
                $rows = $this->repo->revisions($tenantId, $f)->map(fn ($r) => [
                    'name' => $r->name, 'code' => $r->employee_code, 'revision_no' => (int) $r->revision_no,
                    'effective_from' => $r->effective_from, 'to_structure' => $r->to_structure ?: '—',
                    'prev_ctc' => $r->previous_monthly_ctc !== null ? (float) $r->previous_monthly_ctc : '', 'new_ctc' => (float) $r->new_monthly_ctc,
                    'reason' => $r->reason ?: '—', 'changed_by' => $r->changed_by ?: 'System',
                ])->all();
                break;
        }

        return [$columns, $rows];
    }
}
