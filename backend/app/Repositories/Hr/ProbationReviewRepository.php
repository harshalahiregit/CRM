<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrProbationReview;
use Illuminate\Database\Eloquent\Collection;

/** Read queries for Probation Reviews (Phase 3). Tenant-scoped; no writes. */
class ProbationReviewRepository
{
    private const EAGER = [
        'employee:id,name,employee_code,department,designation',
        'reviewer:id,name,employee_code',
        'probation:id,current_status,probation_policy_id', 'probation.policy:id,name',
    ];

    public function list(int $tenantId, array $f): Collection
    {
        return HrProbationReview::where('tenant_id', $tenantId)
            ->with(self::EAGER)
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['employee_probation_id']), fn ($q) => $q->where('employee_probation_id', $f['employee_probation_id']))
            ->when(! empty($f['reviewer_id']), fn ($q) => $q->where('reviewer_id', $f['reviewer_id']))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['recommendation']) && $f['recommendation'] !== 'All', fn ($q) => $q->where('recommendation', $f['recommendation']))
            ->when(! empty($f['department']) && $f['department'] !== 'All', fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department', $f['department'])))
            ->when(! empty($f['from']), fn ($q) => $q->whereDate('review_date', '>=', $f['from']))
            ->when(! empty($f['to']), fn ($q) => $q->whereDate('review_date', '<=', $f['to']))
            ->when(! empty($f['search']), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where(function ($w) use ($f) {
                $w->where('name', 'like', '%'.$f['search'].'%')->orWhere('employee_code', 'like', '%'.$f['search'].'%');
            })))
            ->orderByDesc('id')->get();
    }

    public function find(int $id, int $tenantId): ?HrProbationReview
    {
        return HrProbationReview::where('tenant_id', $tenantId)->with([...self::EAGER, 'auditLogs'])->find($id);
    }

    public function forEmployee(int $employeeId, int $tenantId): Collection
    {
        return HrProbationReview::where('tenant_id', $tenantId)->where('employee_id', $employeeId)
            ->with([...self::EAGER, 'auditLogs'])->orderByDesc('id')->get();
    }

    public function reviewNoExists(int $probationId, int $reviewNo, int $tenantId, ?int $ignoreId = null): bool
    {
        return HrProbationReview::where('tenant_id', $tenantId)
            ->where('employee_probation_id', $probationId)->where('review_no', $reviewNo)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    public function nextReviewNo(int $probationId, int $tenantId): int
    {
        return (int) HrProbationReview::where('tenant_id', $tenantId)
            ->where('employee_probation_id', $probationId)->max('review_no') + 1;
    }

    public function stats(int $tenantId): array
    {
        $rows = HrProbationReview::where('tenant_id', $tenantId)
            ->selectRaw("COUNT(*) as total,
                SUM(CASE WHEN status IN ('Draft','Submitted') THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN recommendation='Confirm' THEN 1 ELSE 0 END) as recommend_confirm,
                AVG(NULLIF(overall_rating,0)) as avg_rating")->first();

        return [
            'total'              => (int) ($rows->total ?? 0),
            'pending'            => (int) ($rows->pending ?? 0),
            'completed'          => (int) ($rows->completed ?? 0),
            'avg_rating'         => round((float) ($rows->avg_rating ?? 0), 1),
            'recommend_confirm'  => (int) ($rows->recommend_confirm ?? 0),
        ];
    }
}
