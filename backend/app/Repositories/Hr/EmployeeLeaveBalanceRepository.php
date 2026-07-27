<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrEmployeeLeaveBalance;
use App\Models\Hr\HrLeaveBalanceTransaction;
use Illuminate\Database\Eloquent\Collection;

/** Read queries for employee leave balances (Leave Phase 2). Tenant-scoped; no writes. */
class EmployeeLeaveBalanceRepository
{
    public function balances(int $tenantId, array $f): Collection
    {
        return HrEmployeeLeaveBalance::where('tenant_id', $tenantId)
            ->with(['employee:id,name,employee_code,department', 'policy:id,name', 'leaveType:id,name,code,color'])
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['leave_type_id']), fn ($q) => $q->where('leave_type_id', $f['leave_type_id']))
            ->when(isset($f['status']) && $f['status'] !== '' && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->orderByDesc('id')->get();
    }

    /** Active balances for one employee. */
    public function activeForEmployee(int $employeeId, int $tenantId): Collection
    {
        return HrEmployeeLeaveBalance::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('status', HrEmployeeLeaveBalance::ACTIVE)
            ->with(['policy:id,name', 'leaveType:id,name,code,color'])
            ->withCount('transactions')
            ->orderBy('leave_type_id')->get();
    }

    public function activeByType(int $employeeId, int $leaveTypeId, int $tenantId): ?HrEmployeeLeaveBalance
    {
        return HrEmployeeLeaveBalance::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('status', HrEmployeeLeaveBalance::ACTIVE)
            ->latest('id')->first();
    }

    public function allActiveForEmployee(int $employeeId, int $tenantId): Collection
    {
        return HrEmployeeLeaveBalance::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('status', HrEmployeeLeaveBalance::ACTIVE)->get();
    }

    public function findBalance(int $id, int $tenantId): ?HrEmployeeLeaveBalance
    {
        return HrEmployeeLeaveBalance::where('tenant_id', $tenantId)
            ->with(['employee:id,name,employee_code', 'policy:id,name,negative_balance_allowed', 'leaveType:id,name,code'])
            ->find($id);
    }

    public function transactions(int $balanceId, int $tenantId): Collection
    {
        return HrLeaveBalanceTransaction::where('tenant_id', $tenantId)
            ->where('employee_leave_balance_id', $balanceId)
            ->orderByDesc('id')->get();
    }

    public function stats(int $tenantId): array
    {
        $active = HrEmployeeLeaveBalance::where('tenant_id', $tenantId)->where('status', HrEmployeeLeaveBalance::ACTIVE);

        return [
            'employees_covered' => (clone $active)->distinct('employee_id')->count('employee_id'),
            'total_allocation'  => round((float) (clone $active)->sum('allocated'), 1),
            'total_available'   => round((float) (clone $active)->sum('available_balance'), 1),
            // Employees who currently have a policy-backed active balance.
            'policies_assigned' => (int) HrEmployeeLeaveBalance::where('tenant_id', $tenantId)
                ->where('status', HrEmployeeLeaveBalance::ACTIVE)->whereNotNull('leave_policy_id')
                ->distinct('employee_id')->count('employee_id'),
        ];
    }
}
