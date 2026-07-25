<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrEmployeeProbation;
use App\Models\Hr\HrProbationConfirmation;
use App\Models\Hr\HrProbationExtension;
use App\Models\Hr\HrProbationReview;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/** Read queries for Probation Confirmations (Phase 5). Tenant-scoped; no writes. */
class ProbationConfirmationRepository
{
    private const EAGER = [
        'employee:id,name,employee_code,department,designation,grade_id', 'employee.grade:id,name',
        'probation:id,current_status,probation_start_date,probation_end_date,probation_policy_id,probation_type_id,extension_count',
        'probation.policy:id,name', 'probation.probationType:id,name',
        'latestReview:id,review_no,overall_rating,recommendation,status,review_date',
        'latestExtension:id,extension_number,extension_days,extended_end_date,status',
    ];

    public function list(int $tenantId, array $f): Collection
    {
        return HrProbationConfirmation::where('tenant_id', $tenantId)
            ->with(self::EAGER)
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['recommendation']) && $f['recommendation'] !== 'All', fn ($q) => $q->where('recommendation', $f['recommendation']))
            ->when(! empty($f['department']) && $f['department'] !== 'All', fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department', $f['department'])))
            ->when(! empty($f['from']), fn ($q) => $q->whereDate('created_at', '>=', $f['from']))
            ->when(! empty($f['to']), fn ($q) => $q->whereDate('created_at', '<=', $f['to']))
            ->when(! empty($f['search']), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where(function ($w) use ($f) {
                $w->where('name', 'like', '%'.$f['search'].'%')->orWhere('employee_code', 'like', '%'.$f['search'].'%');
            })))
            ->orderByDesc('id')->get();
    }

    public function find(int $id, int $tenantId): ?HrProbationConfirmation
    {
        return HrProbationConfirmation::where('tenant_id', $tenantId)->with([...self::EAGER, 'auditLogs'])->find($id);
    }

    public function forEmployee(int $employeeId, int $tenantId): Collection
    {
        return HrProbationConfirmation::where('tenant_id', $tenantId)->where('employee_id', $employeeId)
            ->with([...self::EAGER, 'auditLogs'])->orderByDesc('id')->get();
    }

    public function history(int $tenantId, array $f): Collection
    {
        return HrProbationConfirmation::where('tenant_id', $tenantId)
            ->whereIn('status', [HrProbationConfirmation::CONFIRMED, HrProbationConfirmation::REJECTED])
            ->with(self::EAGER)
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->orderByDesc('id')->get();
    }

    public function findByProbation(int $probationId, int $tenantId): ?HrProbationConfirmation
    {
        return HrProbationConfirmation::where('tenant_id', $tenantId)->where('probation_id', $probationId)->first();
    }

    /** Latest review for a probation (completed preferred) — for the recommendation snapshot. */
    public function latestReview(int $probationId, int $tenantId): ?HrProbationReview
    {
        return HrProbationReview::where('tenant_id', $tenantId)->where('employee_probation_id', $probationId)
            ->orderByRaw("CASE WHEN status='Completed' THEN 0 ELSE 1 END")->orderByDesc('review_no')->first();
    }

    public function hasCompletedReview(int $probationId, int $tenantId): bool
    {
        return HrProbationReview::where('tenant_id', $tenantId)->where('employee_probation_id', $probationId)
            ->where('status', HrProbationReview::COMPLETED)->exists();
    }

    public function latestExtension(int $probationId, int $tenantId): ?HrProbationExtension
    {
        return HrProbationExtension::where('tenant_id', $tenantId)->where('probation_id', $probationId)
            ->orderByDesc('extension_number')->first();
    }

    public function stats(int $tenantId): array
    {
        $rows = HrProbationConfirmation::where('tenant_id', $tenantId)
            ->selectRaw("SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status='Rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status='Confirmed' THEN 1 ELSE 0 END) as confirmed")->first();

        // Due this month = active/extended probations ending in the current month, not yet confirmed.
        $monthStart = Carbon::today()->startOfMonth()->toDateString();
        $monthEnd = Carbon::today()->endOfMonth()->toDateString();
        $due = (int) HrEmployeeProbation::where('tenant_id', $tenantId)
            ->whereIn('current_status', [HrEmployeeProbation::ACTIVE, HrEmployeeProbation::EXTENDED])
            ->whereDate('probation_end_date', '>=', $monthStart)->whereDate('probation_end_date', '<=', $monthEnd)
            ->count();

        return [
            'pending'        => (int) ($rows->pending ?? 0),
            'approved'       => (int) ($rows->approved ?? 0),
            'rejected'       => (int) ($rows->rejected ?? 0),
            'confirmed'      => (int) ($rows->confirmed ?? 0),
            'due_this_month' => $due,
        ];
    }
}
