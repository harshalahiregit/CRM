<?php

namespace App\Repositories\Hr;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregation over existing Probation data (Probation Reports — Phase 6).
 *
 * Sources: hr_employee_probations, hr_probation_reviews/extensions/confirmations,
 * hr_probation_policies/types, hr_employees. Nothing is written. Tenant-scoped
 * first; aggregates grouped in SQL to avoid N+1. No schema changes.
 */
class ProbationReportRepository
{
    /** Base probation query joined to employee / policy / type with shared filters. */
    private function base(int $tenantId, array $f)
    {
        $q = DB::table('hr_employee_probations as ep')
            ->join('hr_employees as e', 'ep.employee_id', '=', 'e.id')
            ->join('hr_probation_policies as pol', 'ep.probation_policy_id', '=', 'pol.id')
            ->join('hr_probation_types as pt', 'ep.probation_type_id', '=', 'pt.id')
            ->where('ep.tenant_id', $tenantId);

        if (! empty($f['year']))         { $q->whereRaw($this->yearExpr('ep.probation_start_date').' = ?', [(int) $f['year']]); }
        if (! empty($f['month']))        { $q->whereRaw($this->monthExpr('ep.probation_start_date').' = ?', [(int) $f['month']]); }
        if (! empty($f['employee_id']))  { $q->where('ep.employee_id', $f['employee_id']); }
        if (! empty($f['department']) && $f['department'] !== 'All')   { $q->where('e.department', $f['department']); }
        if (! empty($f['designation']) && $f['designation'] !== 'All') { $q->where('e.designation', $f['designation']); }
        if (! empty($f['policy_id']))    { $q->where('ep.probation_policy_id', $f['policy_id']); }
        if (! empty($f['status']) && $f['status'] !== 'All') { $q->where('ep.current_status', $f['status']); }

        return $q;
    }

    private function durationExpr(): string
    {
        return 'julianday(ep.probation_end_date) - julianday(ep.probation_start_date)';
    }

    /* ── Dashboard (tenant-scoped) ────────────────────────── */
    public function dashboard(int $tenantId): array
    {
        $prob = DB::table('hr_employee_probations')->where('tenant_id', $tenantId)
            ->selectRaw("COUNT(*) as total,
                SUM(CASE WHEN current_status='Active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN current_status='Extended' THEN 1 ELSE 0 END) as extended,
                SUM(CASE WHEN current_status='Confirmed' THEN 1 ELSE 0 END) as confirmed,
                AVG(julianday(probation_end_date) - julianday(probation_start_date)) as avg_duration")->first();

        $conf = DB::table('hr_probation_confirmations')->where('tenant_id', $tenantId)
            ->selectRaw("SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status='Rejected' THEN 1 ELSE 0 END) as rejected")->first();

        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $due = (int) DB::table('hr_employee_probations')->where('tenant_id', $tenantId)
            ->whereIn('current_status', ['Active', 'Extended'])
            ->whereDate('probation_end_date', '>=', $monthStart)->whereDate('probation_end_date', '<=', $monthEnd)->count();

        return [
            'total'                => (int) ($prob->total ?? 0),
            'active'               => (int) ($prob->active ?? 0),
            'extended'             => (int) ($prob->extended ?? 0),
            'pending_confirmation' => (int) ($conf->pending ?? 0),
            'confirmed'            => (int) ($prob->confirmed ?? 0),
            'rejected'             => (int) ($conf->rejected ?? 0),
            'avg_duration'         => round((float) ($prob->avg_duration ?? 0), 1),
            'due_this_month'       => $due,
        ];
    }

    /* ── Employee report ──────────────────────────────────── */
    public function employees(int $tenantId, array $f): Collection
    {
        return collect(
            $this->base($tenantId, $f)
                ->leftJoin('hr_probation_confirmations as c', 'c.probation_id', '=', 'ep.id')
                ->leftJoin('hr_probation_reviews as rv', function ($j) {
                    $j->on('rv.employee_probation_id', '=', 'ep.id')
                      ->whereRaw('rv.id = (select max(r2.id) from hr_probation_reviews r2 where r2.employee_probation_id = ep.id)');
                })
                ->selectRaw("e.name, e.employee_code, e.department, e.designation, pol.name as policy, pt.name as ptype,
                    ep.probation_start_date, ep.probation_end_date, ep.current_status, ep.extension_count,
                    rv.overall_rating as review_rating, rv.recommendation as review_reco,
                    c.status as confirmation_status")
                ->orderByDesc('ep.id')->get()
        );
    }

    /* ── Department report ────────────────────────────────── */
    public function departments(int $tenantId, array $f): Collection
    {
        return collect(
            $this->base($tenantId, $f)
                ->groupBy('e.department')
                ->selectRaw("COALESCE(e.department,'Unassigned') as department, COUNT(*) as employees,
                    SUM(CASE WHEN ep.current_status='Active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN ep.current_status='Extended' THEN 1 ELSE 0 END) as extended,
                    SUM(CASE WHEN ep.current_status='Confirmed' THEN 1 ELSE 0 END) as confirmed,
                    SUM(CASE WHEN ep.current_status='Cancelled' THEN 1 ELSE 0 END) as cancelled,
                    AVG(".$this->durationExpr().") as avg_duration")->get()
        );
    }

    /* ── Policy report ────────────────────────────────────── */
    public function policies(int $tenantId, array $f): Collection
    {
        return collect(
            $this->base($tenantId, $f)
                ->groupBy('pol.id', 'pol.name')
                ->selectRaw("pol.name as policy, COUNT(*) as employees,
                    SUM(CASE WHEN ep.current_status='Confirmed' THEN 1 ELSE 0 END) as confirmed,
                    SUM(CASE WHEN ep.current_status='Extended' THEN 1 ELSE 0 END) as extended,
                    AVG(".$this->durationExpr().") as avg_duration")->get()
        );
    }

    /* ── Review report ────────────────────────────────────── */
    public function reviewSummary(int $tenantId): array
    {
        $r = DB::table('hr_probation_reviews')->where('tenant_id', $tenantId)
            ->selectRaw("SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status IN ('Draft','Submitted') THEN 1 ELSE 0 END) as pending,
                AVG(NULLIF(overall_rating,0)) as avg_rating")->first();

        return ['completed' => (int) ($r->completed ?? 0), 'pending' => (int) ($r->pending ?? 0), 'avg_rating' => round((float) ($r->avg_rating ?? 0), 1)];
    }

    public function reviewRecommendations(int $tenantId): Collection
    {
        return collect(
            DB::table('hr_probation_reviews')->where('tenant_id', $tenantId)
                ->groupBy('recommendation')->selectRaw('recommendation, COUNT(*) as c')->get()
        );
    }

    /* ── Extension report (by department) ─────────────────── */
    public function extensions(int $tenantId, array $f): Collection
    {
        return collect(
            DB::table('hr_probation_extensions as x')->join('hr_employees as e', 'x.employee_id', '=', 'e.id')
                ->where('x.tenant_id', $tenantId)
                ->when(! empty($f['department']) && $f['department'] !== 'All', fn ($q) => $q->where('e.department', $f['department']))
                ->groupBy('e.department')
                ->selectRaw("COALESCE(e.department,'Unassigned') as department, COUNT(*) as requested,
                    SUM(CASE WHEN x.status='Approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN x.status='Rejected' THEN 1 ELSE 0 END) as rejected,
                    AVG(CASE WHEN x.status='Approved' THEN x.extension_days END) as avg_days")->get()
        );
    }

    /* ── Confirmation report ──────────────────────────────── */
    public function confirmations(int $tenantId, array $f): Collection
    {
        return collect(
            DB::table('hr_probation_confirmations as c')
                ->join('hr_employees as e', 'c.employee_id', '=', 'e.id')
                ->leftJoin('hr_employee_probations as ep', 'c.probation_id', '=', 'ep.id')
                ->leftJoin('hr_probation_policies as pol', 'ep.probation_policy_id', '=', 'pol.id')
                ->where('c.tenant_id', $tenantId)
                ->when(! empty($f['employee_id']), fn ($q) => $q->where('c.employee_id', $f['employee_id']))
                ->when(! empty($f['department']) && $f['department'] !== 'All', fn ($q) => $q->where('e.department', $f['department']))
                ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('c.status', $f['status']))
                ->selectRaw('e.name, e.employee_code, e.department, pol.name as policy, c.recommendation, c.decision,
                    c.confirmation_date, c.effective_date, c.status')
                ->orderByDesc('c.id')->get()
        );
    }

    /* ── Trends (rows fetched, aggregated in PHP) ─────────── */
    public function trendProbations(int $tenantId, int $year): Collection
    {
        return collect(DB::table('hr_employee_probations')->where('tenant_id', $tenantId)
            ->whereRaw($this->yearExpr('probation_start_date').' = ?', [$year])->get(['probation_start_date']));
    }

    public function trendReviews(int $tenantId, int $year): Collection
    {
        return collect(DB::table('hr_probation_reviews')->where('tenant_id', $tenantId)
            ->whereRaw($this->yearExpr('review_date').' = ?', [$year])->get(['review_date']));
    }

    public function trendExtensions(int $tenantId, int $year): Collection
    {
        return collect(DB::table('hr_probation_extensions')->where('tenant_id', $tenantId)
            ->whereRaw($this->yearExpr('created_at').' = ?', [$year])->get(['created_at']));
    }

    public function trendConfirmations(int $tenantId, int $year): Collection
    {
        return collect(DB::table('hr_probation_confirmations')->where('tenant_id', $tenantId)
            ->whereRaw($this->yearExpr('created_at').' = ?', [$year])->get(['created_at', 'status']));
    }

    /* ── Filter options ───────────────────────────────────── */
    public function filterOptions(int $tenantId): array
    {
        return [
            'years' => DB::table('hr_employee_probations')->where('tenant_id', $tenantId)
                ->selectRaw('DISTINCT '.$this->yearExpr('probation_start_date').' as y')->orderByDesc('y')->pluck('y')->filter()->values()->all(),
            'departments' => DB::table('hr_employees')->where('tenant_id', $tenantId)->whereNotNull('department')->where('department', '!=', '')
                ->distinct()->orderBy('department')->pluck('department')->all(),
            'designations' => DB::table('hr_employees')->where('tenant_id', $tenantId)->whereNotNull('designation')->where('designation', '!=', '')
                ->distinct()->orderBy('designation')->pluck('designation')->all(),
            'employees' => DB::table('hr_employees')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'employee_code'])->all(),
            'policies' => DB::table('hr_probation_policies')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name'])->all(),
            'statuses' => ['Assigned', 'Active', 'Extended', 'Confirmed', 'Failed', 'Cancelled'],
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
