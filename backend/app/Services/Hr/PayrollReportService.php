<?php

namespace App\Services\Hr;

use App\Repositories\Hr\PayrollReportRepository;
use Illuminate\Support\Carbon;

/**
 * Payroll Reports & Analytics (Payroll Phase 6) — read-only.
 *
 * Shapes the aggregate data from PayrollReportRepository into report payloads.
 * Never recalculates payroll: every number originates from the frozen snapshots
 * in hr_payroll_records / hr_payroll_runs / hr_payslips.
 */
class PayrollReportService
{
    public function __construct(private PayrollReportRepository $repo)
    {
    }

    /** KPI cards. */
    public function summary(int $tenantId, array $filters): array
    {
        $s = $this->repo->summary($tenantId, $filters);
        $employees = (int) $s->employees;

        return [
            'total_payroll_cost' => round((float) $s->net, 2),          // net disbursed
            'employees_paid'     => $employees,
            'average_salary'     => $employees ? round((float) $s->net / $employees, 2) : 0.0,
            'total_earnings'     => round((float) $s->gross, 2),
            'total_deductions'   => round((float) $s->deductions, 2),
            'total_benefits'     => round((float) $s->benefits, 2),
            'total_ctc'          => round((float) $s->gross + (float) $s->benefits, 2),
            'filters'            => $this->echoFilters($filters),
        ];
    }

    public function employees(int $tenantId, array $filters): array
    {
        return $this->repo->employees($tenantId, $filters)->map(fn ($r) => [
            'employee_name'    => $r->name,
            'employee_code'    => $r->employee_code,
            'department'       => $r->department,
            'designation'      => $r->designation,
            'structure_name'   => $r->structure_name,
            'gross_salary'     => (float) $r->gross_salary,
            'total_benefits'   => (float) $r->total_benefits,
            'total_deductions' => (float) $r->total_deductions,
            'net_salary'       => (float) $r->net_salary,
            'payslip_status'   => $r->payslip_status,
            'period'           => $this->periodLabel((int) $r->payroll_year, (int) $r->payroll_month),
        ])->all();
    }

    public function departments(int $tenantId, array $filters): array
    {
        return $this->repo->departments($tenantId, $filters)->map(fn ($r) => [
            'department'       => $r->department,
            'employees'        => (int) $r->employees,
            'gross_salary'     => (float) $r->gross,
            'total_benefits'   => (float) $r->benefits,
            'total_deductions' => (float) $r->deductions,
            'net_payroll_cost' => (float) $r->net,
        ])->all();
    }

    /**
     * Component-wise analysis from the frozen payslip breakdown JSON. Aggregates
     * each component by name + type; percentage is of the grand total of all
     * component amounts. No recalculation.
     */
    public function components(int $tenantId, array $filters): array
    {
        $agg = [];
        foreach ($this->repo->payslipBreakdowns($tenantId, $filters) as $json) {
            $bd = is_array($json) ? $json : (json_decode((string) $json, true) ?: []);
            foreach (['earnings' => 'Earning', 'benefits' => 'Benefit', 'deductions' => 'Deduction'] as $key => $type) {
                foreach ($bd[$key] ?? [] as $row) {
                    $name = $row['name'] ?? 'Unknown';
                    $k = $type.'|'.$name;
                    $agg[$k] ??= ['component' => $name, 'type' => $type, 'total_amount' => 0.0, 'employee_count' => 0];
                    $agg[$k]['total_amount']   += (float) ($row['amount'] ?? 0);
                    $agg[$k]['employee_count'] += 1;
                }
            }
        }

        $grand = array_sum(array_column($agg, 'total_amount'));
        $rows = array_map(function ($r) use ($grand) {
            $r['total_amount'] = round($r['total_amount'], 2);
            $r['percentage']   = $grand > 0 ? round($r['total_amount'] / $grand * 100, 2) : 0.0;

            return $r;
        }, array_values($agg));

        usort($rows, fn ($a, $b) => $b['total_amount'] <=> $a['total_amount']);

        return ['components' => $rows, 'grand_total' => round($grand, 2)];
    }

    /** Monthly trend series from completed runs. */
    public function trends(int $tenantId, array $filters): array
    {
        $runs = $this->repo->completedRuns($tenantId, $filters);
        $benefits = $this->repo->benefitsByRun($tenantId, $runs->pluck('id')->all());

        return $runs->map(fn ($r) => [
            'period'         => $this->periodLabel((int) $r->payroll_year, (int) $r->payroll_month),
            'year'           => (int) $r->payroll_year,
            'month'          => (int) $r->payroll_month,
            'payroll_cost'   => (float) $r->total_net,
            'employee_count' => (int) $r->total_employees,
            'deductions'     => (float) $r->total_deductions,
            'benefits'       => (float) ($benefits[$r->id] ?? 0),
        ])->all();
    }

    public function filterOptions(int $tenantId): array
    {
        return $this->repo->filterOptions($tenantId);
    }

    /*
    |--------------------------------------------------------------------------
    | Export rows (CSV / PDF share the same shaped data)
    |--------------------------------------------------------------------------
    */
    public function exportRows(string $report, int $tenantId, array $filters): array
    {
        return match ($report) {
            'departments' => [
                'title'   => 'Department Payroll Report',
                'headers' => ['Department', 'Employees', 'Gross', 'Benefits', 'Deductions', 'Net Payroll Cost'],
                'rows'    => array_map(fn ($d) => [
                    $d['department'], $d['employees'], $d['gross_salary'], $d['total_benefits'], $d['total_deductions'], $d['net_payroll_cost'],
                ], $this->departments($tenantId, $filters)),
            ],
            'components' => [
                'title'   => 'Salary Component Analysis',
                'headers' => ['Component', 'Type', 'Total Amount', 'Employees', 'Contribution %'],
                'rows'    => array_map(fn ($c) => [
                    $c['component'], $c['type'], $c['total_amount'], $c['employee_count'], $c['percentage'].'%',
                ], $this->components($tenantId, $filters)['components']),
            ],
            default => [ // summary = employee-wise report
                'title'   => 'Payroll Summary Report',
                'headers' => ['Employee', 'Code', 'Department', 'Designation', 'Structure', 'Gross', 'Benefits', 'Deductions', 'Net', 'Payslip'],
                'rows'    => array_map(fn ($e) => [
                    $e['employee_name'], $e['employee_code'], $e['department'], $e['designation'], $e['structure_name'],
                    $e['gross_salary'], $e['total_benefits'], $e['total_deductions'], $e['net_salary'], $e['payslip_status'],
                ], $this->employees($tenantId, $filters)),
            ],
        };
    }

    private function periodLabel(int $year, int $month): string
    {
        try {
            return Carbon::create($year, $month, 1)->format('M Y');
        } catch (\Throwable $e) {
            return sprintf('%04d-%02d', $year, $month);
        }
    }

    private function echoFilters(array $f): array
    {
        return [
            'year'        => $f['year'] ?? null,
            'month'       => $f['month'] ?? null,
            'department'  => $f['department'] ?? null,
            'designation' => $f['designation'] ?? null,
            'employee_id' => $f['employee_id'] ?? null,
        ];
    }
}
