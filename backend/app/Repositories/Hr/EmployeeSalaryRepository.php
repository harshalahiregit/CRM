<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrEmployeeSalary;
use App\Models\User;
use App\Repositories\BaseRepository;
use App\Repositories\Hr\Concerns\ScopesEmployeeData;
use Illuminate\Database\Eloquent\Collection;

class EmployeeSalaryRepository extends BaseRepository
{
    use ScopesEmployeeData;

    protected string $modelClass = HrEmployeeSalary::class;

    /** The one active salary for an employee (or null). */
    public function currentActive(int $employeeId, int $tenantId, ?User $actor = null): ?HrEmployeeSalary
    {
        // Every method here takes the employee id from the caller, so each one
        // is a direct-id surface: a list filter would never see it. Salary is
        // among the most sensitive rows an employee owns.
        $this->assertEmployeeInScope($actor, $employeeId);

        return HrEmployeeSalary::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('status', HrEmployeeSalary::ACTIVE)
            ->with('structure:id,name,code')
            ->latest('id')
            ->first();
    }

    /** Full salary history for an employee, newest first. */
    public function historyFor(int $employeeId, int $tenantId, ?User $actor = null): Collection
    {
        $this->assertEmployeeInScope($actor, $employeeId);

        return HrEmployeeSalary::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->with('structure:id,name,code')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }

    /** Tenant-safe fetch of a single salary row for mutations. */
    public function findForTenant(int $id, int $employeeId, int $tenantId, ?User $actor = null): ?HrEmployeeSalary
    {
        // Used for MUTATIONS. Guarding the read is what stops a write being
        // aimed at somebody outside the actor's scope.
        $this->assertEmployeeInScope($actor, $employeeId);

        return HrEmployeeSalary::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('id', $id)
            ->first();
    }
}
