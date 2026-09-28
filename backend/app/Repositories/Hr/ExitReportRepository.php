<?php

namespace App\Repositories\Hr;

use App\Models\User;
use App\Services\Auth\ScopeResolver;
use App\Support\Hr\DataScope;
use App\Support\Sql\SqlDate;
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
    /**
     * The one place this class talks to the scope resolver.
     *
     * Like Leave and unlike Payroll, this class has no single base query: the
     * dashboard reads four tables directly, the department rollup merges four
     * sources and the trends read two more. So the scope is applied at every
     * employee-level query rather than at one choke point — reqs() alone covers
     * only three of the eleven methods, and a scoped exit list beside a
     * tenant-wide "total requests" tile is worse than either on its own.
     *
     * BRANCH is excluded as in Phase 1: hr_employees.branch is free text with no
     * master, so a role scoped to it reads as global here.
     */
    private function scoped($query, ?User $actor, string $column)
    {
        return app(ScopeResolver::class)->applyToQuery($query, $actor, $column, [
            DataScope::OWN, DataScope::DEPARTMENT, DataScope::TEAM,
        ]);
    }

    /** Base exit-request query with the shared filters applied (tenant first). */
    private function reqs(int $tenantId, array $f, ?User $actor = null)
    {
        $q = DB::table('hr_exit_requests as r')
            ->join('hr_employees as e', 'r.employee_id', '=', 'e.id')
            ->join('hr_exit_types as t', 'r.exit_type_id', '=', 't.id')
            ->where('r.tenant_id', $tenantId);

        // Scope before filters, so a filter can only narrow within it — an
        // employee_id from another department returns nothing rather than
        // reaching them.
        $q = $this->scoped($q, $actor, 'r.employee_id');

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
    public function dashboard(int $tenantId, ?User $actor = null): array
    {
        $req = $this->scoped(DB::table('hr_exit_requests')->where('tenant_id', $tenantId), $actor, 'employee_id')
            ->selectRaw("COUNT(*) as total,
                SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status IN ('Submitted','Under Review','Approved') THEN 1 ELSE 0 END) as active_cases,
                AVG(CASE WHEN notice_days > 0 THEN notice_days END) as avg_notice,
                AVG(CASE WHEN last_working_date IS NOT NULL AND last_working_date >= request_date
                    THEN ".SqlDate::days('request_date', 'last_working_date').' END) as avg_duration')
            ->first();

        $completedClearances = $this->scoped(
            DB::table('hr_exit_clearances')->where('tenant_id', $tenantId)->where('status', 'Completed'),
            $actor, 'employee_id'
        )->count();

        $settled = $this->scoped(
            DB::table('hr_exit_settlements')->where('tenant_id', $tenantId)->where('status', 'Settled'),
            $actor, 'employee_id'
        );
        $settledCount = (clone $settled)->count();
        $settledSum = (float) (clone $settled)->sum('net_settlement');

        // Pending cases = active (submitted/under review/approved) requests not yet settled.
        //
        // The subtrahend is scoped too. Left tenant-wide it would exclude exits
        // settled for people this actor cannot see, so the pending count would
        // be short by a number whose cause is invisible on the screen.
        $settledExitIds = $this->scoped(
            DB::table('hr_exit_settlements')->where('tenant_id', $tenantId)->where('status', 'Settled'),
            $actor, 'employee_id'
        )->pluck('exit_request_id');

        $pending = $this->scoped(
            DB::table('hr_exit_requests')->where('tenant_id', $tenantId), $actor, 'employee_id'
        )->whereIn('status', ['Submitted', 'Under Review', 'Approved'])
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
    public function employees(int $tenantId, array $f, ?User $actor = null): Collection
    {
        return collect(
            $this->reqs($tenantId, $f, $actor)
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
    public function departmentRequests(int $tenantId, array $f, ?User $actor = null): Collection
    {
        return collect(
            $this->reqs($tenantId, $f, $actor)
                ->groupBy('e.department')
                ->selectRaw("COALESCE(e.department,'Unassigned') as department, COUNT(*) as requests,
                    SUM(CASE WHEN r.status='Approved' THEN 1 ELSE 0 END) as approved,
                    AVG(CASE WHEN r.notice_days > 0 THEN r.notice_days END) as avg_notice")
                ->get()
        );
    }

    public function completedClearancesByDept(int $tenantId, ?User $actor = null): Collection
    {
        return collect(
            $this->scoped(
                DB::table('hr_exit_clearances as c')->join('hr_employees as e', 'c.employee_id', '=', 'e.id')
                    ->where('c.tenant_id', $tenantId)->where('c.status', 'Completed'),
                $actor, 'c.employee_id'
            )
                ->groupBy('e.department')
                ->selectRaw("COALESCE(e.department,'Unassigned') as department, COUNT(*) as c")->get()
        );
    }

    public function settledByDept(int $tenantId, ?User $actor = null): Collection
    {
        return collect(
            $this->scoped(
                DB::table('hr_exit_settlements as s')->join('hr_employees as e', 's.employee_id', '=', 'e.id')
                    ->where('s.tenant_id', $tenantId)->where('s.status', 'Settled'),
                $actor, 's.employee_id'
            )
                ->groupBy('e.department')
                ->selectRaw("COALESCE(e.department,'Unassigned') as department, COUNT(*) as c")->get()
        );
    }

    /**
     * Headcount per department — the denominator of the attrition percentage.
     *
     * Scoped for the same reason as the numerator. Exits from this actor's
     * department over a company-wide headcount would read as a fraction of the
     * true attrition rate, which is the more dangerous error of the two: it
     * looks plausible.
     */
    public function headcountByDept(int $tenantId, ?User $actor = null): Collection
    {
        return collect(
            $this->scoped(DB::table('hr_employees')->where('tenant_id', $tenantId), $actor, 'id')
                ->groupBy('department')
                ->selectRaw("COALESCE(department,'Unassigned') as department, COUNT(*) as c")->get()
        );
    }

    /* ── Exit type analysis ───────────────────────────────── */
    public function exitTypes(int $tenantId, array $f, ?User $actor = null): Collection
    {
        return collect(
            $this->reqs($tenantId, $f, $actor)
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
    public function settlements(int $tenantId, array $f, ?User $actor = null): Collection
    {
        $q = DB::table('hr_exit_settlements as s')
            ->join('hr_employees as e', 's.employee_id', '=', 'e.id')
            ->leftJoin('hr_exit_requests as r', 's.exit_request_id', '=', 'r.id')
            ->where('s.tenant_id', $tenantId);

        // A settlement carries gross earnings, recoveries and net payable —
        // salary data by another name, so it scopes like salary data.
        $q = $this->scoped($q, $actor, 's.employee_id');

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

    /**
     * Clearance progress per clearing department, across all items.
     *
     * `department` here is the department DOING the clearing — IT, Finance,
     * Admin — not the leaver's own. The row is still about specific people's
     * exits, so it scopes; hr_exit_clearance_items carries no employee_id, so
     * the scope is reached through its parent clearance, which does.
     *
     * The join is the only structural change, and it forced one more: both
     * tables have a `status`, so the buckets below are qualified as i.status.
     * Unqualified they were fine without the join and ambiguous with it —
     * SQLite rejects the query outright rather than picking a side, which is
     * the good failure.
     */
    public function clearanceByDepartment(int $tenantId, ?User $actor = null): Collection
    {
        return collect(
            $this->scoped(
                DB::table('hr_exit_clearance_items as i')
                    ->join('hr_exit_clearances as cl', 'i.clearance_id', '=', 'cl.id')
                    ->where('i.tenant_id', $tenantId),
                $actor, 'cl.employee_id'
            )
                ->groupBy('i.department')
                ->selectRaw("i.department as department,
                    SUM(CASE WHEN i.status='Pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN i.status='In Progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN i.status='Cleared' THEN 1 ELSE 0 END) as cleared,
                    SUM(CASE WHEN i.status='Rejected' THEN 1 ELSE 0 END) as rejected,
                    COUNT(*) as total")
                ->get()
        );
    }

    /* ── Trends (rows fetched, aggregated in PHP — DB-agnostic) ── */
    public function trendRequests(int $tenantId, int $year, ?User $actor = null): Collection
    {
        return collect(
            $this->scoped(DB::table('hr_exit_requests')->where('tenant_id', $tenantId), $actor, 'employee_id')
                ->whereRaw($this->yearExpr('request_date').' = ?', [$year])
                ->get(['request_date', 'status', 'notice_days'])
        );
    }

    public function trendSettlements(int $tenantId, int $year, ?User $actor = null): Collection
    {
        return collect(
            $this->scoped(DB::table('hr_exit_settlements')->where('tenant_id', $tenantId), $actor, 'employee_id')
                ->where('settlement_month', 'like', $year.'-%')
                ->get(['settlement_month', 'net_settlement', 'status'])
        );
    }

    /**
     * The dropdown contents — scoped, because a filter list is data too.
     *
     * `employees` is a list of names and codes; tenant-wide it hands a
     * department-scoped user the whole staff directory in a <select>, which is
     * the same disclosure as the report with extra steps. Exit types and the
     * status vocabulary are master data and stay whole.
     */
    public function filterOptions(int $tenantId, ?User $actor = null): array
    {
        $employees = $this->scoped(DB::table('hr_employees')->where('tenant_id', $tenantId), $actor, 'id');

        return [
            'years' => $this->scoped(DB::table('hr_exit_requests')->where('tenant_id', $tenantId), $actor, 'employee_id')
                ->selectRaw('DISTINCT '.$this->yearExpr('request_date').' as y')->orderByDesc('y')->pluck('y')->filter()->values()->all(),
            'departments' => (clone $employees)->whereNotNull('department')->where('department', '!=', '')
                ->distinct()->orderBy('department')->pluck('department')->all(),
            'designations' => (clone $employees)->whereNotNull('designation')->where('designation', '!=', '')
                ->distinct()->orderBy('designation')->pluck('designation')->all(),
            'employees' => (clone $employees)->orderBy('name')->get(['id', 'name', 'employee_code'])->all(),
            'exit_types' => DB::table('hr_exit_types')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'code'])->all(),
            'statuses' => ['Draft', 'Submitted', 'Under Review', 'Approved', 'Rejected', 'Withdrawn'],
        ];
    }

    private function yearExpr(string $col): string
    {
        return SqlDate::year($col);
    }

    private function monthExpr(string $col): string
    {
        return SqlDate::month($col);
    }
}
