<?php

namespace App\Repositories\Hr;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregation over existing Exit data (Exit Reports — final phase).
 *
 * Sources: hr_exit_requests, hr_exit_clearances, hr_exit_clearance_items,
 * hr_exit_settlements, hr_exit_types, hr_employees, hr_departments. Nothing is
 * written or recomputed — every figure comes from stored Exit records. All
 * queries are tenant-scoped first, status-scoped where relevant, and aggregate in
 * grouped SQL to avoid N+1. No payroll/leave/attendance data is modified.
 */
class ExitReportRepository
{
    /** Base exit-request query with the shared filters applied (tenant first). */
    private function reqs(int $tenantId, array $f)
    {
        $q = DB::table('hr_exit_requests as r')
            ->join('hr_employees as e', 'r.employee_id', '=', 'e.id')
            ->join('hr_exit_types as t', 'r.exit_type_id', '=', 't.id')
            ->where('r.tenant_id', $tenantId);

        if (! empty($f['year']))        { $q->whereRaw($this->yearExpr('r.request_date').' = ?', [(int) $f['year']]); }
        if (! empty($f['month']))       { $q->whereRaw($this->monthExpr('r.request_date').' = ?', [(int) $f['month']]); }
        if (! empty($f['employee_id'])) { $q->where('r.employee_id', $f['employee_id']); }
        if (! empty($f['department']) && $f['department'] !== 'All')   { $q->where('e.department', $f['department']); }
        if (! empty($f['designation']) && $f['designation'] !== 'All') { $q->where('e.designation', $f['designation']); }
        if (! empty($f['exit_type_id']))  { $q->where('r.exit_type_id', $f['exit_type_id']); }
        if (! empty($f['status']) && $f['status'] !== 'All') { $q->where('r.status', $f['status']); }

        return $q;
    }

    /* ── Dashboard KPIs (tenant-scoped aggregates) ────────── */
    public function dashboard(int $tenantId): array
    {
        $req = DB::table('hr_exit_requests')->where('tenant_id', $tenantId)
            ->selectRaw("COUNT(*) as total,
                SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status IN ('Submitted','Under Review','Approved') THEN 1 ELSE 0 END) as active_cases,
                AVG(CASE WHEN notice_days > 0 THEN notice_days END) as avg_notice,
                AVG(CASE WHEN last_working_date IS NOT NULL AND last_working_date >= request_date
                    THEN julianday(last_working_date) - julianday(request_date) END) as avg_duration")
            ->first();

        $completedClearances = DB::table('hr_exit_clearances')->where('tenant_id', $tenantId)->where('status', 'Completed')->count();
        $settled = DB::table('hr_exit_settlements')->where('tenant_id', $tenantId)->where('status', 'Settled');
        $settledCount = (clone $settled)->count();
        $settledSum = (float) (clone $settled)->sum('net_settlement');

        // Pending cases = active (submitted/under review/approved) requests not yet settled.
        $settledExitIds = DB::table('hr_exit_settlements')->where('tenant_id', $tenantId)->where('status', 'Settled')->pluck('exit_request_id');
        $pending = DB::table('hr_exit_requests')->where('tenant_id', $tenantId)
            ->whereIn('status', ['Submitted', 'Under Review', 'Approved'])
            ->whereNotIn('id', $settledExitIds)->count();

        return [
            'total_requests'       => (int) ($req->total ?? 0),
            'approved_exits'       => (int) ($req->approved ?? 0),
            'completed_clearances' => (int) $completedClearances,
            'settled_employees'    => (int) $settledCount,
            'avg_notice_days'      => round((float) ($req->avg_notice ?? 0), 1),
            'avg_exit_duration'    => round((float) ($req->avg_duration ?? 0), 1),
            'total_settlement_amount' => round($settledSum, 2),
            'pending_exit_cases'   => (int) $pending,
        ];
    }

    /* ── Employee exit report (request-level rows) ────────── */
    public function employees(int $tenantId, array $f): Collection
    {
        return collect(
            $this->reqs($tenantId, $f)
                ->leftJoin('hr_exit_settlements as s', function ($j) {
                    $j->on('s.exit_request_id', '=', 'r.id');
                })
                ->selectRaw("e.name, e.employee_code, e.department, e.designation,
                    t.name as exit_type, r.status, r.notice_days, r.request_date, r.last_working_date,
                    s.status as settlement_status, s.net_settlement")
                ->orderByDesc('r.id')->get()
        );
    }

    /* ── Department exit report (grouped, merged in PHP) ──── */
    public function departmentRequests(int $tenantId, array $f): Collection
    {
        return collect(
            $this->reqs($tenantId, $f)
                ->groupBy('e.department')
                ->selectRaw("COALESCE(e.department,'Unassigned') as department, COUNT(*) as requests,
                    SUM(CASE WHEN r.status='Approved' THEN 1 ELSE 0 END) as approved,
                    AVG(CASE WHEN r.notice_days > 0 THEN r.notice_days END) as avg_notice")
                ->get()
        );
    }

    public function completedClearancesByDept(int $tenantId): Collection
    {
        return collect(
            DB::table('hr_exit_clearances as c')->join('hr_employees as e', 'c.employee_id', '=', 'e.id')
                ->where('c.tenant_id', $tenantId)->where('c.status', 'Completed')
                ->groupBy('e.department')
                ->selectRaw("COALESCE(e.department,'Unassigned') as department, COUNT(*) as c")->get()
        );
    }

    public function settledByDept(int $tenantId): Collection
    {
        return collect(
            DB::table('hr_exit_settlements as s')->join('hr_employees as e', 's.employee_id', '=', 'e.id')
                ->where('s.tenant_id', $tenantId)->where('s.status', 'Settled')
                ->groupBy('e.department')
                ->selectRaw("COALESCE(e.department,'Unassigned') as department, COUNT(*) as c")->get()
        );
    }

    public function headcountByDept(int $tenantId): Collection
    {
        return collect(
            DB::table('hr_employees')->where('tenant_id', $tenantId)
                ->groupBy('department')
                ->selectRaw("COALESCE(department,'Unassigned') as department, COUNT(*) as c")->get()
        );
    }

    /* ── Exit type analysis ───────────────────────────────── */
    public function exitTypes(int $tenantId, array $f): Collection
    {
        return collect(
            $this->reqs($tenantId, $f)
                ->leftJoin('hr_exit_settlements as s', 's.exit_request_id', '=', 'r.id')
                ->groupBy('t.id', 't.name', 't.code')
                ->selectRaw("t.name as exit_type, t.code, COUNT(DISTINCT r.id) as count,
                    AVG(CASE WHEN r.notice_days > 0 THEN r.notice_days END) as avg_notice,
                    SUM(CASE WHEN r.status='Approved' THEN 1 ELSE 0 END) as approved,
                    AVG(s.net_settlement) as avg_settlement")
                ->get()
        );
    }

    /* ── Settlement report ────────────────────────────────── */
    public function settlements(int $tenantId, array $f): Collection
    {
        $q = DB::table('hr_exit_settlements as s')
            ->join('hr_employees as e', 's.employee_id', '=', 'e.id')
            ->leftJoin('hr_exit_requests as r', 's.exit_request_id', '=', 'r.id')
            ->where('s.tenant_id', $tenantId);

        if (! empty($f['employee_id']))  { $q->where('s.employee_id', $f['employee_id']); }
        if (! empty($f['department']) && $f['department'] !== 'All')   { $q->where('e.department', $f['department']); }
        if (! empty($f['designation']) && $f['designation'] !== 'All') { $q->where('e.designation', $f['designation']); }
        if (! empty($f['exit_type_id']))     { $q->where('r.exit_type_id', $f['exit_type_id']); }
        if (! empty($f['status']) && $f['status'] !== 'All') { $q->where('s.status', $f['status']); }
        if (! empty($f['month']))            { $q->whereRaw($this->monthExpr('r.request_date').' = ?', [(int) $f['month']]); }
        if (! empty($f['year']) && empty($f['settlement_month'])) { $q->where('s.settlement_month', 'like', $f['year'].'-%'); }
        if (! empty($f['settlement_month'])) { $q->where('s.settlement_month', $f['settlement_month']); }

        return collect(
            $q->selectRaw('e.name, e.employee_code, e.department, s.settlement_month,
                s.gross_earnings, s.total_recoveries, s.net_settlement, s.status')
                ->orderByDesc('s.id')->get()
        );
    }

    /* ── Clearance report (per department across all items) ── */
    public function clearanceByDepartment(int $tenantId): Collection
    {
        return collect(
            DB::table('hr_exit_clearance_items')->where('tenant_id', $tenantId)
                ->groupBy('department')
                ->selectRaw("department,
                    SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status='In Progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN status='Cleared' THEN 1 ELSE 0 END) as cleared,
                    SUM(CASE WHEN status='Rejected' THEN 1 ELSE 0 END) as rejected,
                    COUNT(*) as total")
                ->get()
        );
    }

    /* ── Trends (rows fetched, aggregated in PHP — DB-agnostic) ── */
    public function trendRequests(int $tenantId, int $year): Collection
    {
        return collect(
            DB::table('hr_exit_requests')->where('tenant_id', $tenantId)
                ->whereRaw($this->yearExpr('request_date').' = ?', [$year])
                ->get(['request_date', 'status', 'notice_days'])
        );
    }

    public function trendSettlements(int $tenantId, int $year): Collection
    {
        return collect(
            DB::table('hr_exit_settlements')->where('tenant_id', $tenantId)
                ->where('settlement_month', 'like', $year.'-%')
                ->get(['settlement_month', 'net_settlement', 'status'])
        );
    }

    /* ── Filter options ───────────────────────────────────── */
    public function filterOptions(int $tenantId): array
    {
        return [
            'years' => DB::table('hr_exit_requests')->where('tenant_id', $tenantId)
                ->selectRaw('DISTINCT '.$this->yearExpr('request_date').' as y')->orderByDesc('y')->pluck('y')->filter()->values()->all(),
            'departments' => DB::table('hr_employees')->where('tenant_id', $tenantId)->whereNotNull('department')->where('department', '!=', '')
                ->distinct()->orderBy('department')->pluck('department')->all(),
            'designations' => DB::table('hr_employees')->where('tenant_id', $tenantId)->whereNotNull('designation')->where('designation', '!=', '')
                ->distinct()->orderBy('designation')->pluck('designation')->all(),
            'employees' => DB::table('hr_employees')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'employee_code'])->all(),
            'exit_types' => DB::table('hr_exit_types')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'code'])->all(),
            'statuses' => ['Draft', 'Submitted', 'Under Review', 'Approved', 'Rejected', 'Withdrawn'],
        ];
    }

    private function yearExpr(string $col): string
    {
        return "CAST(strftime('%Y', $col) AS INTEGER)";
    }

    private function monthExpr(string $col): string
    {
        return "CAST(strftime('%m', $col) AS INTEGER)";
    }
}
