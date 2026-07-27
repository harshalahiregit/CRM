<?php

namespace App\Services\Hr;

use App\Repositories\Hr\ExitReportRepository;

/**
 * Exit Reports & Analytics (final Exit phase) — read-only.
 * Shapes aggregate Exit data into report payloads; never writes or recomputes
 * stored Exit / Payroll / Leave values. No attendance logic.
 */
class ExitReportService
{
    private const MONTHS = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** Departments that appear on every clearance (fixed order). */
    private const CLR_DEPTS = ['HR', 'IT', 'Admin', 'Finance', 'Reporting Manager'];

    public function __construct(private ExitReportRepository $repo)
    {
    }

    public function dashboard(int $tenantId): array
    {
        return $this->repo->dashboard($tenantId);
    }

    public function employees(int $tenantId, array $f): array
    {
        return $this->repo->employees($tenantId, $f)->map(fn ($r) => [
            'employee_name' => $r->name, 'employee_code' => $r->employee_code,
            'department' => $r->department, 'designation' => $r->designation,
            'exit_type' => $r->exit_type, 'status' => $r->status,
            'notice_days' => (int) $r->notice_days,
            'settlement' => $r->net_settlement !== null ? (float) $r->net_settlement : null,
            'settlement_status' => $r->settlement_status,
            'request_date' => $r->request_date, 'last_working_date' => $r->last_working_date,
            'timeline' => trim(($r->request_date ?: '—').' → '.($r->last_working_date ?: 'TBD')),
        ])->all();
    }

    public function departments(int $tenantId, array $f): array
    {
        $reqs = $this->repo->departmentRequests($tenantId, $f)->keyBy('department');
        $completed = $this->repo->completedClearancesByDept($tenantId)->keyBy('department');
        $settled = $this->repo->settledByDept($tenantId)->keyBy('department');
        $headcount = $this->repo->headcountByDept($tenantId)->keyBy('department');

        return $reqs->keys()->map(function ($dept) use ($reqs, $completed, $settled, $headcount) {
            $r = $reqs->get($dept);
            $requests = (int) ($r->requests ?? 0);
            $heads = (int) ($headcount->get($dept)->c ?? 0);

            return [
                'department' => $dept,
                'requests' => $requests,
                'approved' => (int) ($r->approved ?? 0),
                'completed' => (int) ($completed->get($dept)->c ?? 0),
                'settled' => (int) ($settled->get($dept)->c ?? 0),
                'avg_notice' => round((float) ($r->avg_notice ?? 0), 1),
                'exit_rate' => $heads > 0 ? round($requests / $heads * 100, 1) : 0.0,
            ];
        })->all();
    }

    public function exitTypes(int $tenantId, array $f): array
    {
        return $this->repo->exitTypes($tenantId, $f)->map(function ($r) {
            $count = (int) $r->count;

            return [
                'exit_type' => $r->exit_type, 'code' => $r->code, 'count' => $count,
                'avg_notice' => round((float) ($r->avg_notice ?? 0), 1),
                'avg_settlement' => $r->avg_settlement !== null ? round((float) $r->avg_settlement, 2) : 0.0,
                'approval_pct' => $count > 0 ? round((int) $r->approved / $count * 100, 1) : 0.0,
            ];
        })->all();
    }

    public function settlements(int $tenantId, array $f): array
    {
        return $this->repo->settlements($tenantId, $f)->map(fn ($r) => [
            'employee_name' => $r->name, 'employee_code' => $r->employee_code, 'department' => $r->department,
            'settlement_month' => $r->settlement_month,
            'gross' => $r->gross_earnings !== null ? (float) $r->gross_earnings : null,
            'recoveries' => $r->total_recoveries !== null ? (float) $r->total_recoveries : null,
            'net' => $r->net_settlement !== null ? (float) $r->net_settlement : null,
            'status' => $r->status,
        ])->all();
    }

    public function clearances(int $tenantId, array $f): array
    {
        $rows = $this->repo->clearanceByDepartment($tenantId)->keyBy('department');

        // Fixed department order; departments with no items still show as zeroed.
        return collect(self::CLR_DEPTS)->map(function ($dept) use ($rows) {
            $r = $rows->get($dept);
            $total = (int) ($r->total ?? 0);
            $cleared = (int) ($r->cleared ?? 0);

            return [
                'department' => $dept,
                'pending' => (int) ($r->pending ?? 0),
                'in_progress' => (int) ($r->in_progress ?? 0),
                'cleared' => $cleared,
                'rejected' => (int) ($r->rejected ?? 0),
                'completion_pct' => $total > 0 ? round($cleared / $total * 100, 1) : 0.0,
            ];
        })->all();
    }

    public function trends(int $tenantId, array $f): array
    {
        $year = (int) ($f['year'] ?? now()->year);
        $reqRows = $this->repo->trendRequests($tenantId, $year);
        $setRows = $this->repo->trendSettlements($tenantId, $year);

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = ['month' => self::MONTHS[$m], 'requests' => 0, 'approvals' => 0, 'settlements' => 0,
                '_notice_sum' => 0.0, '_notice_n' => 0, '_settle_sum' => 0.0, '_settle_n' => 0];
        }
        foreach ($reqRows as $r) {
            $m = (int) substr((string) $r->request_date, 5, 2);
            if ($m < 1 || $m > 12) {
                continue;
            }
            $months[$m]['requests']++;
            if ($r->status === 'Approved') {
                $months[$m]['approvals']++;
            }
            if ((int) $r->notice_days > 0) {
                $months[$m]['_notice_sum'] += (float) $r->notice_days;
                $months[$m]['_notice_n']++;
            }
        }
        foreach ($setRows as $s) {
            $m = (int) substr((string) $s->settlement_month, 5, 2);
            if ($m < 1 || $m > 12) {
                continue;
            }
            $months[$m]['settlements']++;
            if ($s->net_settlement !== null) {
                $months[$m]['_settle_sum'] += (float) $s->net_settlement;
                $months[$m]['_settle_n']++;
            }
        }

        return array_values(array_map(function ($row) {
            $row['avg_notice'] = $row['_notice_n'] > 0 ? round($row['_notice_sum'] / $row['_notice_n'], 1) : 0.0;
            $row['avg_settlement'] = $row['_settle_n'] > 0 ? round($row['_settle_sum'] / $row['_settle_n'], 2) : 0.0;
            unset($row['_notice_sum'], $row['_notice_n'], $row['_settle_sum'], $row['_settle_n']);

            return $row;
        }, $months));
    }

    public function filterOptions(int $tenantId): array
    {
        return $this->repo->filterOptions($tenantId);
    }

    /* ── Export rows (CSV / PDF share the shaped data) ────── */
    public function exportRows(string $report, int $tenantId, array $f): array
    {
        return match ($report) {
            'departments' => [
                'title' => 'Department Exit Report',
                'headers' => ['Department', 'Requests', 'Approved', 'Completed', 'Settled', 'Avg Notice', 'Exit Rate %'],
                'rows' => array_map(fn ($d) => [$d['department'], $d['requests'], $d['approved'], $d['completed'], $d['settled'], $d['avg_notice'], $d['exit_rate']], $this->departments($tenantId, $f)),
            ],
            'exit-types', 'types' => [
                'title' => 'Exit Type Analysis',
                'headers' => ['Exit Type', 'Count', 'Avg Notice', 'Avg Settlement', 'Approval %'],
                'rows' => array_map(fn ($t) => [$t['exit_type'], $t['count'], $t['avg_notice'], $t['avg_settlement'], $t['approval_pct']], $this->exitTypes($tenantId, $f)),
            ],
            'settlements' => [
                'title' => 'Settlement Report',
                'headers' => ['Employee', 'Code', 'Department', 'Settlement Month', 'Gross', 'Recoveries', 'Net', 'Status'],
                'rows' => array_map(fn ($s) => [$s['employee_name'], $s['employee_code'], $s['department'], $s['settlement_month'] ?? '—', $s['gross'] ?? 0, $s['recoveries'] ?? 0, $s['net'] ?? 0, $s['status']], $this->settlements($tenantId, $f)),
            ],
            'clearances' => [
                'title' => 'Clearance Report',
                'headers' => ['Department', 'Pending', 'In Progress', 'Cleared', 'Rejected', 'Completion %'],
                'rows' => array_map(fn ($c) => [$c['department'], $c['pending'], $c['in_progress'], $c['cleared'], $c['rejected'], $c['completion_pct']], $this->clearances($tenantId, $f)),
            ],
            'trends' => [
                'title' => 'Exit Trends',
                'headers' => ['Month', 'Requests', 'Approvals', 'Settlements', 'Avg Notice', 'Avg Settlement'],
                'rows' => array_map(fn ($t) => [$t['month'], $t['requests'], $t['approvals'], $t['settlements'], $t['avg_notice'], $t['avg_settlement']], $this->trends($tenantId, $f)),
            ],
            default => [ // employees
                'title' => 'Employee Exit Report',
                'headers' => ['Employee', 'Department', 'Exit Type', 'Status', 'Notice', 'Settlement', 'Timeline'],
                'rows' => array_map(fn ($e) => [$e['employee_name'], $e['department'], $e['exit_type'], $e['status'], $e['notice_days'], $e['settlement'] ?? '—', $e['timeline']], $this->employees($tenantId, $f)),
            ],
        };
    }
}
