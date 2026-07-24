<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrEmployeeSalary;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class EmployeeSalaryRepository extends BaseRepository
{
    protected string $modelClass = HrEmployeeSalary::class;

    /** The one active salary for an employee (or null). */
    public function currentActive(int $employeeId, int $tenantId): ?HrEmployeeSalary
    {
        return HrEmployeeSalary::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('status', HrEmployeeSalary::ACTIVE)
            ->with('structure:id,name,code')
            ->latest('id')
            ->first();
    }

    /** Full salary history for an employee, newest first. */
    public function historyFor(int $employeeId, int $tenantId): Collection
    {
        return HrEmployeeSalary::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->with('structure:id,name,code')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }

    /** Tenant-safe fetch of a single salary row for mutations. */
    public function findForTenant(int $id, int $employeeId, int $tenantId): ?HrEmployeeSalary
    {
        return HrEmployeeSalary::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('id', $id)
            ->first();
    }
}
