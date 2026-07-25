<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrLeaveApplication;
use Illuminate\Database\Eloquent\Collection;

/** Read queries for leave applications / approvals (Phase 3 + 4). Tenant-scoped. */
class LeaveApplicationRepository
{
    /** Applications list with the common filters (used by both apply + approval views). */
    public function filtered(int $tenantId, array $f): Collection
    {
        return HrLeaveApplication::where('tenant_id', $tenantId)
            ->with(['employee:id,name,employee_code,department,designation', 'leaveType:id,name,code,color', 'policy:id,name'])
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['leave_type_id']), fn ($q) => $q->where('leave_type_id', $f['leave_type_id']))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['department']) && $f['department'] !== 'All', fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department', $f['department'])))
            ->when(! empty($f['from']), fn ($q) => $q->whereDate('from_date', '>=', $f['from']))
            ->when(! empty($f['to']), fn ($q) => $q->whereDate('to_date', '<=', $f['to']))
            ->orderByDesc('id')->get();
    }

    public function find(int $id, int $tenantId): ?HrLeaveApplication
    {
        return HrLeaveApplication::where('tenant_id', $tenantId)
            ->with(['employee:id,name,employee_code,department,designation', 'leaveType:id,name,code,color', 'policy:id,name,negative_balance_allowed', 'auditLogs'])
            ->find($id);
    }

    public function forEmployee(int $employeeId, int $tenantId): Collection
    {
        return HrLeaveApplication::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->with(['leaveType:id,name,code,color'])
            ->orderByDesc('id')->get();
    }

    /** Queue counters by status. */
    public function statusCounts(int $tenantId): array
    {
        $rows = HrLeaveApplication::where('tenant_id', $tenantId)
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();

        return [
            'pending'   => (int) ($rows[HrLeaveApplication::SUBMITTED] ?? 0),
            'approved'  => (int) ($rows[HrLeaveApplication::APPROVED] ?? 0),
            'rejected'  => (int) ($rows[HrLeaveApplication::REJECTED] ?? 0),
            'cancelled' => (int) ($rows[HrLeaveApplication::CANCELLED] ?? 0),
        ];
    }
}
