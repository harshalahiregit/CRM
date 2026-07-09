<?php

namespace App\Services\Hr;

use App\Models\Hr\HrEmployee;
use App\Repositories\Hr\EmployeeRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class EmployeeService
{
    public function __construct(private EmployeeRepository $employeeRepository)
    {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        return $this->employeeRepository->filtered($tenantId, $filters);
    }

    public function create(array $data, int $tenantId): HrEmployee
    {
        $data['tenant_id'] = $tenantId;
        $empCode = 'SNE-'.date('Y').'-'.str_pad(HrEmployee::where('tenant_id', $tenantId)->count() + 1, 3, '0', STR_PAD_LEFT);

        $employee = HrEmployee::create([...$data, 'employee_code' => $empCode]);

        Log::channel('hr')->info('Employee created', ['employee_id' => $employee->id, 'tenant_id' => $tenantId]);

        return $employee;
    }

    public function update(HrEmployee $employee, array $data): HrEmployee
    {
        $employee->update($data);

        Log::channel('hr')->info('Employee updated', ['employee_id' => $employee->id, 'tenant_id' => $employee->tenant_id]);

        return $employee;
    }

    public function destroy(HrEmployee $employee): void
    {
        $employee->delete();

        Log::channel('hr')->info('Employee deleted', ['employee_id' => $employee->id, 'tenant_id' => $employee->tenant_id]);
    }

    public function stats(int $tenantId): array
    {
        return [
            'total'    => HrEmployee::where('tenant_id', $tenantId)->count(),
            'active'   => HrEmployee::where('tenant_id', $tenantId)->where('status', 'Active')->count(),
            'on_leave' => HrEmployee::where('tenant_id', $tenantId)->where('status', 'On Leave')->count(),
            'inactive' => HrEmployee::where('tenant_id', $tenantId)->where('status', 'Inactive')->count(),
            'by_dept'  => HrEmployee::where('tenant_id', $tenantId)->select('department')
                ->selectRaw('count(*) as count')
                ->groupBy('department')->get(),
        ];
    }
}
