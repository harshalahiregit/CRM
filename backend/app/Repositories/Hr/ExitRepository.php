<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrExitPolicy;
use App\Models\Hr\HrExitRequest;
use App\Models\Hr\HrExitType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/** Read queries for Exit Management masters (Phase 1) + requests (Phase 2). Tenant-scoped; no writes. */
class ExitRepository
{
    /* ── Exit Types ───────────────────────────────────────── */
    public function types(int $tenantId, array $f): Collection
    {
        return HrExitType::where('tenant_id', $tenantId)
            ->when(isset($f['status']) && $f['status'] !== '' && $f['status'] !== 'All', fn ($q) => $q->where('is_active', $f['status'] === 'Active'))
            ->when(! empty($f['search']), fn ($q) => $q->where(function ($w) use ($f) {
                $w->where('name', 'like', '%'.$f['search'].'%')->orWhere('code', 'like', '%'.$f['search'].'%');
            }))
            ->orderBy('name')->get();
    }

    public function findType(int $id, int $tenantId): ?HrExitType
    {
        return HrExitType::where('tenant_id', $tenantId)->find($id);
    }

    public function typeStats(int $tenantId): array
    {
        $base = HrExitType::where('tenant_id', $tenantId);

        return [
            'total'    => (clone $base)->count(),
            'active'   => (clone $base)->where('is_active', true)->count(),
            'inactive' => (clone $base)->where('is_active', false)->count(),
        ];
    }

    /* ── Exit Policies ────────────────────────────────────── */
    public function policies(int $tenantId, array $f): Collection
    {
        return HrExitPolicy::where('tenant_id', $tenantId)
            ->with(['grade:id,name', 'designation:id,name', 'department:id,name', 'defaultExitType:id,name,code'])
            ->when(isset($f['status']) && $f['status'] !== '' && $f['status'] !== 'All', fn ($q) => $q->where('is_active', $f['status'] === 'Active'))
            ->when(! empty($f['search']), fn ($q) => $q->where('name', 'like', '%'.$f['search'].'%'))
            ->orderBy('name')->get();
    }

    public function findPolicy(int $id, int $tenantId): ?HrExitPolicy
    {
        return HrExitPolicy::where('tenant_id', $tenantId)
            ->with(['grade:id,name', 'designation:id,name', 'department:id,name', 'defaultExitType:id,name,code'])
            ->find($id);
    }

    /**
     * First active policy that best matches an employee's grade / designation /
     * department — most specific wins. Used to auto-attach a policy on request.
     */
    public function policyForEmployee(int $tenantId, ?int $gradeId, ?int $designationId, ?int $departmentId): ?HrExitPolicy
    {
        $base = fn () => HrExitPolicy::where('tenant_id', $tenantId)->where('is_active', true);

        foreach ([
            ['grade_id' => $gradeId, 'designation_id' => $designationId, 'department_id' => $departmentId],
            ['designation_id' => $designationId],
            ['grade_id' => $gradeId],
            ['department_id' => $departmentId],
        ] as $criteria) {
            $criteria = array_filter($criteria, fn ($v) => ! empty($v));
            if (! $criteria) {
                continue;
            }
            $match = $base();
            foreach ($criteria as $col => $val) {
                $match->where($col, $val);
            }
            if ($hit = $match->orderBy('id')->first()) {
                return $hit;
            }
        }

        return null;
    }

    /* ── Exit Requests (Phase 2) + Approval (Phase 3) ─────── */
    public function requests(int $tenantId, array $f): Collection
    {
        return HrExitRequest::where('tenant_id', $tenantId)
            ->with(['employee:id,name,employee_code,department,designation', 'exitType:id,name,code', 'policy:id,name,notice_days,buyout_allowed'])
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['exit_type_id']), fn ($q) => $q->where('exit_type_id', $f['exit_type_id']))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['statuses']), fn ($q) => $q->whereIn('status', (array) $f['statuses']))
            ->when(! empty($f['department']) && $f['department'] !== 'All', fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department', $f['department'])))
            ->when(! empty($f['search']), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where(function ($w) use ($f) {
                $w->where('name', 'like', '%'.$f['search'].'%')->orWhere('employee_code', 'like', '%'.$f['search'].'%');
            })))
            ->orderByDesc('id')->get();
    }

    /** Approval KPI counters (Phase 3). Pending = Submitted awaiting review. */
    public function approvalStats(int $tenantId): array
    {
        $base = fn () => HrExitRequest::where('tenant_id', $tenantId);
        $monthStart = Carbon::today()->startOfMonth()->toDateString();
        $monthEnd = Carbon::today()->endOfMonth()->toDateString();

        return [
            'pending'      => (int) $base()->where('status', HrExitRequest::SUBMITTED)->count(),
            'under_review' => (int) $base()->where('status', HrExitRequest::UNDER_REVIEW)->count(),
            'approved'     => (int) $base()->where('status', HrExitRequest::APPROVED)->count(),
            'rejected'     => (int) $base()->where('status', HrExitRequest::REJECTED)->count(),
            'exits_this_month' => (int) $base()->where('status', HrExitRequest::APPROVED)
                ->whereNotNull('last_working_date')
                ->whereDate('last_working_date', '>=', $monthStart)->whereDate('last_working_date', '<=', $monthEnd)->count(),
        ];
    }

    /** Decided requests (Approved / Rejected) — the approval history log. */
    public function approvalHistory(int $tenantId, array $f): Collection
    {
        return HrExitRequest::where('tenant_id', $tenantId)
            ->whereIn('status', [HrExitRequest::APPROVED, HrExitRequest::REJECTED])
            ->with(['employee:id,name,employee_code,department,designation', 'exitType:id,name,code', 'policy:id,name'])
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->orderByDesc('decided_at')->orderByDesc('id')->get();
    }

    public function findRequest(int $id, int $tenantId): ?HrExitRequest
    {
        return HrExitRequest::where('tenant_id', $tenantId)
            ->with(['employee:id,name,employee_code,department,designation', 'exitType:id,name,code', 'policy:id,name,notice_days,buyout_allowed', 'auditLogs'])
            ->find($id);
    }

    /** Latest non-withdrawn request for an employee — the profile's "current" exit. */
    public function currentRequestForEmployee(int $employeeId, int $tenantId): ?HrExitRequest
    {
        return HrExitRequest::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('status', '!=', HrExitRequest::WITHDRAWN)
            ->with(['exitType:id,name,code', 'policy:id,name', 'auditLogs'])
            ->orderByDesc('id')->first();
    }

    /** KPI counters: statuses, employees currently within notice, exits this month. */
    public function requestStats(int $tenantId): array
    {
        $base = fn () => HrExitRequest::where('tenant_id', $tenantId);
        $today = Carbon::today()->toDateString();
        $monthStart = Carbon::today()->startOfMonth()->toDateString();
        $monthEnd = Carbon::today()->endOfMonth()->toDateString();

        return [
            'draft'         => (int) $base()->where('status', HrExitRequest::DRAFT)->count(),
            'submitted'     => (int) $base()->where('status', HrExitRequest::SUBMITTED)->count(),
            'withdrawn'     => (int) $base()->where('status', HrExitRequest::WITHDRAWN)->count(),
            'active_notice' => (int) $base()->where('status', HrExitRequest::SUBMITTED)
                ->whereNotNull('notice_start_date')->whereNotNull('notice_end_date')
                ->whereDate('notice_start_date', '<=', $today)->whereDate('notice_end_date', '>=', $today)->count(),
            'exits_this_month' => (int) $base()->where('status', '!=', HrExitRequest::WITHDRAWN)
                ->whereNotNull('last_working_date')
                ->whereDate('last_working_date', '>=', $monthStart)->whereDate('last_working_date', '<=', $monthEnd)->count(),
        ];
    }
}
