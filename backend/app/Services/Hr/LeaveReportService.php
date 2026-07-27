<?php

namespace App\Services\Hr;

use App\Repositories\Hr\LeaveReportRepository;
use Illuminate\Support\Carbon;

/**
 * Leave Reports & Analytics (final Leave phase) — read-only.
 * Shapes aggregate Leave data into report payloads; never writes or recomputes
 * stored Leave values. No attendance logic (attendance lives in SangoeTrack).
 */
class LeaveReportService
{
    private const MONTHS = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    public function __construct(private LeaveReportRepository $repo)
    {
    }

    public function dashboard(int $tenantId): array
    {
        return $this->repo->dashboard($tenantId, now()->toDateString());
    }

    public function employees(int $tenantId, array $f): array
    {
        return $this->repo->employees($tenantId, $f)->map(fn ($r) => [
            'employee_name' => $r->name, 'employee_code' => $r->employee_code,
            'department' => $r->department, 'designation' => $r->designation, 'leave_type' => $r->leave_type,
            'applied_days' => (float) $r->applied_days, 'approved_days' => (float) $r->approved_days,
            'remaining' => $r->remaining !== null ? (float) $r->remaining : null, 'status' => $r->status,
            'period' => $r->from_date.' → '.$r->to_date,
        ])->all();
    }

    public function departments(int $tenantId, array $f): array
    {
        $apps = $this->repo->departmentApps($tenantId, $f)->keyBy('department');
        $bal = $this->repo->balancesByDept($tenantId)->keyBy('department');
        $onLeave = $this->repo->onLeaveTodayByDept($tenantId, now()->toDateString())->keyBy('department');

        $depts = collect($apps->keys())->merge($bal->keys())->unique()->values();

        return $depts->map(function ($dept) use ($apps, $bal, $onLeave) {
            $a = $apps->get($dept);
            $b = $bal->get($dept);
            $allocated = (float) ($b->allocated ?? 0);
            $used = (float) ($b->used ?? 0);

            return [
                'department' => $dept,
                'total' => (int) ($a->total ?? 0), 'approved' => (int) ($a->approved ?? 0),
                'pending' => (int) ($a->pending ?? 0), 'rejected' => (int) ($a->rejected ?? 0),
                'employees_on_leave' => (int) ($onLeave->get($dept)->c ?? 0),
                'utilization' => $allocated > 0 ? round($used / $allocated * 100, 1) : 0.0,
            ];
        })->all();
    }

    public function types(int $tenantId, array $f): array
    {
        return $this->repo->typeAnalysis($tenantId, $f)->map(function ($r) {
            $allocated = (float) $r->allocated;

            return [
                'leave_type' => $r->name, 'code' => $r->code,
                'allocated' => $allocated, 'used' => (float) $r->used,
                'remaining' => (float) $r->remaining, 'carry_forward' => (float) $r->carry_forward,
                'utilization' => $allocated > 0 ? round((float) $r->used / $allocated * 100, 1) : 0.0,
            ];
        })->all();
    }

    public function balances(int $tenantId, array $f): array
    {
        return $this->repo->balances($tenantId, $f)->map(fn ($r) => [
            'employee_name' => $r->name, 'employee_code' => $r->employee_code, 'department' => $r->department,
            'leave_type' => $r->leave_type,
            'opening' => (float) $r->opening_balance, 'allocated' => (float) $r->allocated,
            'used' => (float) $r->used, 'adjusted' => (float) $r->adjusted,
            'carry_forward' => (float) $r->carried_forward, 'available' => (float) $r->available_balance,
        ])->all();
    }

    public function holidays(int $tenantId, array $f): array
    {
        $today = now()->toDateString();
        $rows = $this->repo->holidays($tenantId, $f);

        return [
            'counts' => [
                'total'     => $rows->count(),
                'upcoming'  => $rows->where('holiday_date', '>=', $today)->count(),
                'completed' => $rows->where('holiday_date', '<', $today)->count(),
                'optional'  => $rows->where('is_optional', true)->count(),
            ],
            'holidays' => $rows->map(fn ($h) => [
                'id' => $h->id, 'title' => $h->title, 'holiday_date' => $h->holiday_date,
                'holiday_type' => $h->holiday_type, 'applicable_for' => $h->applicable_for,
                'department_name' => $h->department_name, 'is_optional' => (bool) $h->is_optional,
                'is_upcoming' => $h->holiday_date >= $today,
            ])->all(),
        ];
    }

    public function trends(int $tenantId, array $f): array
    {
        $year = (int) ($f['year'] ?? now()->year);
        $rows = $this->repo->trendRows($tenantId, $year);
        $alloc = $this->repo->totalAllocated($tenantId);

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = ['month' => self::MONTHS[$m], 'applications' => 0, 'approvals' => 0, 'rejections' => 0, 'usage' => 0.0];
        }
        foreach ($rows as $r) {
            $m = (int) substr((string) $r->from_date, 5, 2);
            if ($m < 1 || $m > 12) {
                continue;
            }
            $months[$m]['applications']++;
            if ($r->status === 'Approved') {
                $months[$m]['approvals']++;
                $months[$m]['usage'] += (float) $r->days;
            }
            if ($r->status === 'Rejected') {
                $months[$m]['rejections']++;
            }
        }

        return array_values(array_map(function ($row) use ($alloc) {
            $row['usage'] = round($row['usage'], 1);
            $row['utilization'] = $alloc > 0 ? round($row['usage'] / $alloc * 100, 1) : 0.0;

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
                'title' => 'Department Leave Report',
                'headers' => ['Department', 'Applications', 'Approved', 'Pending', 'Rejected', 'On Leave', 'Utilization %'],
                'rows' => array_map(fn ($d) => [$d['department'], $d['total'], $d['approved'], $d['pending'], $d['rejected'], $d['employees_on_leave'], $d['utilization']], $this->departments($tenantId, $f)),
            ],
            'types' => [
                'title' => 'Leave Type Analysis',
                'headers' => ['Leave Type', 'Allocated', 'Used', 'Remaining', 'Carry Forward', 'Utilization %'],
                'rows' => array_map(fn ($t) => [$t['leave_type'], $t['allocated'], $t['used'], $t['remaining'], $t['carry_forward'], $t['utilization']], $this->types($tenantId, $f)),
            ],
            'balances' => [
                'title' => 'Leave Balance Report',
                'headers' => ['Employee', 'Code', 'Department', 'Leave Type', 'Opening', 'Allocated', 'Used', 'Adjusted', 'Carry Fwd', 'Available'],
                'rows' => array_map(fn ($b) => [$b['employee_name'], $b['employee_code'], $b['department'], $b['leave_type'], $b['opening'], $b['allocated'], $b['used'], $b['adjusted'], $b['carry_forward'], $b['available']], $this->balances($tenantId, $f)),
            ],
            'holidays' => [
                'title' => 'Holiday Report',
                'headers' => ['Holiday', 'Date', 'Type', 'Applies To', 'Optional'],
                'rows' => array_map(fn ($h) => [$h['title'], $h['holiday_date'], $h['holiday_type'], $h['applicable_for'], $h['is_optional'] ? 'Yes' : 'No'], $this->holidays($tenantId, $f)['holidays']),
            ],
            default => [ // employees
                'title' => 'Employee Leave Report',
                'headers' => ['Employee', 'Department', 'Designation', 'Leave Type', 'Applied Days', 'Approved Days', 'Remaining', 'Status'],
                'rows' => array_map(fn ($e) => [$e['employee_name'], $e['department'], $e['designation'], $e['leave_type'], $e['applied_days'], $e['approved_days'], $e['remaining'] ?? '—', $e['status']], $this->employees($tenantId, $f)),
            ],
        };
    }
}
