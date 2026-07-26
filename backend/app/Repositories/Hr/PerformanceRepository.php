<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrEmployeeGoal;
use App\Models\Hr\HrGoal;
use App\Models\Hr\HrIncrementRecommendation;
use App\Models\Hr\HrKpi;
use App\Models\Hr\HrPerformanceReview;
use App\Models\Hr\HrPromotionRecommendation;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read queries for the Performance Management module. Tenant-scoped; no writes.
 * List filters are optional and applied conservatively.
 */
class PerformanceRepository
{
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
    public function employeeGoals(int $tenantId, array $f): Collection
    {
        return HrEmployeeGoal::where('tenant_id', $tenantId)
            ->with(['goal:id,title,weightage,target,due_date', 'employee:id,name,employee_code,department'])
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->orderByDesc('id')->get();
    }

    public function findEmployeeGoal(int $id, int $tenantId): ?HrEmployeeGoal
    {
        return HrEmployeeGoal::where('tenant_id', $tenantId)->find($id);
    }

    /* ── Reviews ──────────────────────────────────────────── */
    public function reviews(int $tenantId, array $f): Collection
    {
        return HrPerformanceReview::where('tenant_id', $tenantId)
            ->with('employee:id,name,employee_code,department,designation')
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['review_type']) && $f['review_type'] !== 'All', fn ($q) => $q->where('review_type', $f['review_type']))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['year']), fn ($q) => $q->where('period_year', $f['year']))
            ->orderByDesc('id')->get();
    }

    public function findReview(int $id, int $tenantId): ?HrPerformanceReview
    {
        return HrPerformanceReview::where('tenant_id', $tenantId)
            ->with(['employee:id,name,employee_code,department,designation', 'kpiRatings'])
            ->find($id);
    }

    /* ── Recommendations ──────────────────────────────────── */
    public function promotions(int $tenantId, array $f): Collection
    {
        return HrPromotionRecommendation::where('tenant_id', $tenantId)
            ->with('employee:id,name,employee_code,department,designation')
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->orderByDesc('id')->get();
    }

    public function findPromotion(int $id, int $tenantId): ?HrPromotionRecommendation
    {
        return HrPromotionRecommendation::where('tenant_id', $tenantId)->find($id);
    }

    public function increments(int $tenantId, array $f): Collection
    {
        return HrIncrementRecommendation::where('tenant_id', $tenantId)
            ->with('employee:id,name,employee_code,department,designation')
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('approval_status', $f['status']))
            ->orderByDesc('id')->get();
    }

    public function findIncrement(int $id, int $tenantId): ?HrIncrementRecommendation
    {
        return HrIncrementRecommendation::where('tenant_id', $tenantId)->find($id);
    }

    /* ── Dashboard aggregates ─────────────────────────────── */
    public function dashboard(int $tenantId): array
    {
        return [
            'total_employees'   => \App\Models\Hr\HrEmployee::where('tenant_id', $tenantId)->count(),
            'goals_assigned'    => HrEmployeeGoal::where('tenant_id', $tenantId)->count(),
            'goals_completed'   => HrEmployeeGoal::where('tenant_id', $tenantId)->where('status', 'Completed')->count(),
            'reviews_pending'   => HrPerformanceReview::where('tenant_id', $tenantId)->whereIn('status', ['Draft', 'Submitted'])->count(),
            'reviews_completed' => HrPerformanceReview::where('tenant_id', $tenantId)->whereIn('status', ['Reviewed', 'Approved'])->count(),
            'avg_rating'        => round((float) HrPerformanceReview::where('tenant_id', $tenantId)->whereIn('status', ['Reviewed', 'Approved'])->avg('overall_rating'), 2),
            'promotion_eligible'=> HrPromotionRecommendation::where('tenant_id', $tenantId)->where('eligible', true)->count(),
        ];
    }
}
