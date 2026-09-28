<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrEmployeeGoal;
use App\Models\Hr\HrGoal;
use App\Models\Hr\HrIncrementRecommendation;
use App\Models\Hr\HrKpi;
use App\Models\Hr\HrPerformanceReview;
use App\Models\Hr\HrPromotionRecommendation;
use Illuminate\Database\Eloquent\Collection;
use App\Models\User;
use App\Repositories\Hr\Concerns\ScopesEmployeeData;

/**
 * Read queries for the Performance Management module. Tenant-scoped; no writes.
 * List filters are optional and applied conservatively.
 */
class PerformanceRepository
{
    use ScopesEmployeeData;

    /* ── KPIs ─────────────────────────────────────────────── */
    public function kpis(int $tenantId, array $f): Collection
    {
        return HrKpi::where('tenant_id', $tenantId)
            ->when(isset($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('is_active', $f['status'] === 'Active'))
            ->when(! empty($f['search']), fn ($q) => $q->where('name', 'like', '%'.$f['search'].'%'))
            ->orderBy('name')->get();
    }

    public function findKpi(int $id, int $tenantId): ?HrKpi
    {
        return HrKpi::where('tenant_id', $tenantId)->find($id);
    }

    /* ── Goals ────────────────────────────────────────────── */
    public function goals(int $tenantId, array $f): Collection
    {
        return HrGoal::where('tenant_id', $tenantId)
            ->withCount('assignments')
            ->when(! empty($f['department']) && $f['department'] !== 'All', fn ($q) => $q->where('department', $f['department']))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['search']), fn ($q) => $q->where('title', 'like', '%'.$f['search'].'%'))
            ->orderByDesc('id')->get();
    }

    public function findGoal(int $id, int $tenantId): ?HrGoal
    {
        return HrGoal::where('tenant_id', $tenantId)->find($id);
    }

    /* ── Employee goal assignments ────────────────────────── */
    public function employeeGoals(int $tenantId, array $f, ?User $actor = null): Collection
    {
        return $this->scopeToEmployees(HrEmployeeGoal::where('tenant_id', $tenantId), $actor)
            ->with(['goal:id,title,weightage,target,due_date', 'employee:id,name,employee_code,department'])
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->orderByDesc('id')->get();
    }

    public function findEmployeeGoal(int $id, int $tenantId, ?User $actor = null): ?HrEmployeeGoal
    {
        return $this->scopeToEmployees(HrEmployeeGoal::where('tenant_id', $tenantId), $actor)->find($id);
    }

    /* ── Reviews ──────────────────────────────────────────── */
    public function reviews(int $tenantId, array $f, ?User $actor = null): Collection
    {
        return $this->scopeToEmployees(HrPerformanceReview::where('tenant_id', $tenantId), $actor)
            ->with('employee:id,name,employee_code,department,designation')
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['review_type']) && $f['review_type'] !== 'All', fn ($q) => $q->where('review_type', $f['review_type']))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['year']), fn ($q) => $q->where('period_year', $f['year']))
            ->orderByDesc('id')->get();
    }

    public function findReview(int $id, int $tenantId, ?User $actor = null): ?HrPerformanceReview
    {
        return $this->scopeToEmployees(HrPerformanceReview::where('tenant_id', $tenantId), $actor)
            ->with(['employee:id,name,employee_code,department,designation', 'kpiRatings'])
            ->find($id);
    }

    /* ── Recommendations ──────────────────────────────────── */
    public function promotions(int $tenantId, array $f, ?User $actor = null): Collection
    {
        return $this->scopeToEmployees(HrPromotionRecommendation::where('tenant_id', $tenantId), $actor)
            ->with('employee:id,name,employee_code,department,designation')
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->orderByDesc('id')->get();
    }

    public function findPromotion(int $id, int $tenantId, ?User $actor = null): ?HrPromotionRecommendation
    {
        return $this->scopeToEmployees(HrPromotionRecommendation::where('tenant_id', $tenantId), $actor)->find($id);
    }

    public function increments(int $tenantId, array $f, ?User $actor = null): Collection
    {
        return $this->scopeToEmployees(HrIncrementRecommendation::where('tenant_id', $tenantId), $actor)
            ->with('employee:id,name,employee_code,department,designation')
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('approval_status', $f['status']))
            ->orderByDesc('id')->get();
    }

    public function findIncrement(int $id, int $tenantId, ?User $actor = null): ?HrIncrementRecommendation
    {
        return $this->scopeToEmployees(HrIncrementRecommendation::where('tenant_id', $tenantId), $actor)->find($id);
    }

    /* ── Dashboard aggregates ─────────────────────────────── */
    /**
     * The performance dashboard tiles.
     *
     * Every figure here is an aggregate over an employee-level table, so each
     * one takes the scope. An average is the most disclosing of them: an
     * unscoped avg_rating next to a scoped review list lets somebody infer the
     * ratings of people they cannot open.
     *
     * total_employees scopes on 'id' because the count is over hr_employees
     * itself, where the employee id IS the primary key.
     */
    public function dashboard(int $tenantId, ?User $actor = null): array
    {
        $goals     = fn () => $this->scopeToEmployees(HrEmployeeGoal::where('tenant_id', $tenantId), $actor);
        $reviews   = fn () => $this->scopeToEmployees(HrPerformanceReview::where('tenant_id', $tenantId), $actor);
        $reviewed  = fn () => $reviews()->whereIn('status', ['Reviewed', 'Approved']);

        return [
            'total_employees'   => $this->scopeToEmployees(\App\Models\Hr\HrEmployee::where('tenant_id', $tenantId), $actor, 'id')->count(),
            'goals_assigned'    => $goals()->count(),
            'goals_completed'   => $goals()->where('status', 'Completed')->count(),
            'reviews_pending'   => $reviews()->whereIn('status', ['Draft', 'Submitted'])->count(),
            'reviews_completed' => $reviewed()->count(),
            'avg_rating'        => round((float) $reviewed()->avg('overall_rating'), 2),
            'promotion_eligible'=> $this->scopeToEmployees(HrPromotionRecommendation::where('tenant_id', $tenantId), $actor)->where('eligible', true)->count(),
        ];
    }
}
