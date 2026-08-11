<?php

namespace App\Repositories\Hr;

use App\Support\Sql\SqlDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregation over existing Leave data (Leave Reports phase).
 *
 * Sources: hr_leave_applications, hr_employee_leave_balances, hr_leave_types,
 * hr_holidays. Nothing is written or recomputed — every figure comes from stored
 * Leave records. All queries tenant-scoped; aggregates done in SQL (grouped) to
 * avoid N+1. NO attendance data is touched (attendance lives in SangoeTrack).
 */
class LeaveReportRepository
{
    /** Base applications join with the shared filters applied. */
    private function apps(int $tenantId, array $f)
    {
        $q = DB::table('hr_leave_applications as a')
            ->join('hr_employees as e', 'a.employee_id', '=', 'e.id')
            ->join('hr_leave_types as lt', 'a.leave_type_id', '=', 'lt.id')
            ->where('a.tenant_id', $tenantId);

        if (! empty($f['year']))          { $q->whereYear('a.from_date', $f['year']); }
        if (! empty($f['month']))         { $q->whereMonth('a.from_date', $f['month']); }
        if (! empty($f['employee_id']))   { $q->where('a.employee_id', $f['employee_id']); }
        if (! empty($f['department']) && $f['department'] !== 'All')   { $q->where('e.department', $f['department']); }
        if (! empty($f['designation']) && $f['designation'] !== 'All') { $q->where('e.designation', $f['designation']); }
        if (! empty($f['leave_type_id'])) { $q->where('a.leave_type_id', $f['leave_type_id']); }
        if (! empty($f['status']) && $f['status'] !== 'All') { $q->where('a.status', $f['status']); }

        return $q;
    }

    /* ── Dashboard ────────────────────────────────────────── */
    public function dashboard(int $tenantId, string $today): array
    {
        $counts = DB::table('hr_leave_applications')->where('tenant_id', $tenantId)
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();

        $onLeaveToday = DB::table('hr_leave_applications')->where('tenant_id', $tenantId)
            ->where('status', 'Approved')->whereDate('from_date', '<=', $today)->whereDate('to_date', '>=', $today)
            ->distinct()->count('employee_id');

        $upcomingHolidays = DB::table('hr_holidays')->where('tenant_id', $tenantId)
            ->where('is_active', true)->whereDate('holiday_date', '>=', $today)->count();

        $bal = DB::table('hr_employee_leave_balances')->where('tenant_id', $tenantId)->where('status', 'active')
            ->selectRaw('COALESCE(SUM(allocated),0) alloc, COALESCE(SUM(used),0) used')->first();
        $utilization = ($bal->alloc ?? 0) > 0 ? round($bal->used / $bal->alloc * 100, 1) : 0.0;

        return [
            'total_applications' => (int) array_sum($counts),
            'approved'  => (int) ($counts['Approved'] ?? 0),
            'pending'   => (int) ($counts['Submitted'] ?? 0),
            'rejected'  => (int) ($counts['Rejected'] ?? 0),
            'cancelled' => (int) ($counts['Cancelled'] ?? 0),
            'on_leave_today'    => (int) $onLeaveToday,
            'upcoming_holidays' => (int) $upcomingHolidays,
            'utilization'       => $utilization,
        ];
    }

    /* ── Employee leave report (application-level rows) ───── */
    public function employees(int $tenantId, array $f): Collection
    {
        return collect(
            $this->apps($tenantId, $f)
                ->leftJoin('hr_employee_leave_balances as b', function ($j) use ($tenantId) {
                    $j->on('b.employee_id', '=', 'a.employee_id')->on('b.leave_type_id', '=', 'a.leave_type_id')
                      ->where('b.status', '=', 'active')->where('b.tenant_id', '=', $tenantId);
                })
                ->selectRaw("e.name, e.employee_code, e.department, e.designation, lt.name as leave_type,
                    a.days as applied_days,
                    CASE WHEN a.status = 'Approved' THEN a.days ELSE 0 END as approved_days,
                    b.available_balance as remaining, a.status, a.from_date, a.to_date")
                ->orderByDesc('a.id')->get()
        );
    }

    /* ── Department report ────────────────────────────────── */
    public function departmentApps(int $tenantId, array $f): Collection
    {
        return collect(
            $this->apps($tenantId, $f)
                ->groupBy('e.department')
                ->selectRaw("COALESCE(e.department,'Unassigned') as department, COUNT(*) as total,
                    SUM(CASE WHEN a.status='Approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN a.status='Submitted' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN a.status='Rejected' THEN 1 ELSE 0 END) as rejected")
                ->get()
        );
    }

    public function balancesByDept(int $tenantId): Collection
    {
        return collect(
            DB::table('hr_employee_leave_balances as b')->join('hr_employees as e', 'b.employee_id', '=', 'e.id')
                ->where('b.tenant_id', $tenantId)->where('b.status', 'active')
                ->groupBy('e.department')
                ->selectRaw("COALESCE(e.department,'Unassigned') as department, COALESCE(SUM(b.allocated),0) as allocated, COALESCE(SUM(b.used),0) as used")
                ->get()
        );
    }

    public function onLeaveTodayByDept(int $tenantId, string $today): Collection
    {
        return collect(
            DB::table('hr_leave_applications as a')->join('hr_employees as e', 'a.employee_id', '=', 'e.id')
                ->where('a.tenant_id', $tenantId)->where('a.status', 'Approved')
                ->whereDate('a.from_date', '<=', $today)->whereDate('a.to_date', '>=', $today)
                ->groupBy('e.department')
                ->selectRaw("COALESCE(e.department,'Unassigned') as department, COUNT(DISTINCT a.employee_id) as c")
                ->get()
        );
    }

    /* ── Leave type analysis + balance report (from balances) ── */
    public function typeAnalysis(int $tenantId, array $f): Collection
    {
        return collect(
            DB::table('hr_employee_leave_balances as b')->join('hr_leave_types as lt', 'b.leave_type_id', '=', 'lt.id')
                ->where('b.tenant_id', $tenantId)->where('b.status', 'active')
                ->when(! empty($f['leave_type_id']), fn ($q) => $q->where('b.leave_type_id', $f['leave_type_id']))
                ->groupBy('lt.id', 'lt.name', 'lt.code')
                ->selectRaw('lt.name, lt.code,
                    COALESCE(SUM(b.allocated),0) as allocated, COALESCE(SUM(b.used),0) as used,
                    COALESCE(SUM(b.available_balance),0) as remaining, COALESCE(SUM(b.carried_forward),0) as carry_forward')
                ->get()
        );
    }

    public function balances(int $tenantId, array $f): Collection
    {
        return collect(
            DB::table('hr_employee_leave_balances as b')
                ->join('hr_employees as e', 'b.employee_id', '=', 'e.id')
                ->join('hr_leave_types as lt', 'b.leave_type_id', '=', 'lt.id')
                ->where('b.tenant_id', $tenantId)->where('b.status', 'active')
                ->when(! empty($f['employee_id']), fn ($q) => $q->where('b.employee_id', $f['employee_id']))
                ->when(! empty($f['department']) && $f['department'] !== 'All', fn ($q) => $q->where('e.department', $f['department']))
                ->when(! empty($f['leave_type_id']), fn ($q) => $q->where('b.leave_type_id', $f['leave_type_id']))
                ->selectRaw('e.name, e.employee_code, e.department, lt.name as leave_type,
                    b.opening_balance, b.allocated, b.used, b.adjusted, b.carried_forward, b.available_balance')
                ->orderBy('e.name')->get()
        );
    }

    /* ── Holiday report ───────────────────────────────────── */
    public function holidays(int $tenantId, array $f): Collection
    {
        return collect(
            DB::table('hr_holidays as h')->leftJoin('hr_departments as d', 'h.department_id', '=', 'd.id')
                ->where('h.tenant_id', $tenantId)
                ->when(! empty($f['year']) && $f['year'] !== 'All', fn ($q) => $q->whereYear('h.holiday_date', $f['year']))
                ->when(! empty($f['holiday_type']) && $f['holiday_type'] !== 'All', fn ($q) => $q->where('h.holiday_type', $f['holiday_type']))
                ->when(! empty($f['department_id']) && $f['department_id'] !== 'All', fn ($q) => $q->where('h.department_id', $f['department_id']))
                ->selectRaw('h.id, h.title, h.holiday_date, h.holiday_type, h.applicable_for, h.is_optional, h.is_active, d.name as department_name')
                ->orderBy('h.holiday_date')->get()
        );
    }

    /* ── Monthly trends (aggregate in PHP — DB-agnostic) ──── */
    public function trendRows(int $tenantId, int $year): Collection
    {
        return collect(
            DB::table('hr_leave_applications')->where('tenant_id', $tenantId)->whereYear('from_date', $year)
                ->get(['from_date', 'status', 'days'])
        );
    }

    public function totalAllocated(int $tenantId): float
    {
        return (float) DB::table('hr_employee_leave_balances')->where('tenant_id', $tenantId)->where('status', 'active')->sum('allocated');
    }

    /* ── Filter options ───────────────────────────────────── */
    public function filterOptions(int $tenantId): array
    {
        return [
            'years' => DB::table('hr_leave_applications')->where('tenant_id', $tenantId)
                ->selectRaw('DISTINCT '.$this->yearExpr('from_date').' as y')->orderByDesc('y')->pluck('y')->filter()->values()->all(),
            'departments' => DB::table('hr_employees')->where('tenant_id', $tenantId)->whereNotNull('department')->where('department', '!=', '')
                ->distinct()->orderBy('department')->pluck('department')->all(),
            'designations' => DB::table('hr_employees')->where('tenant_id', $tenantId)->whereNotNull('designation')->where('designation', '!=', '')
                ->distinct()->orderBy('designation')->pluck('designation')->all(),
            'employees' => DB::table('hr_employees')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'employee_code'])->all(),
            'leave_types' => DB::table('hr_leave_types')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'code'])->all(),
        ];
    }

    private function yearExpr(string $col): string
    {
        return SqlDate::year($col);
    }
}
