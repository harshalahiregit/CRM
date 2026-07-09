<?php

namespace App\Services\Hr;

use App\Models\HrEmployee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class EmployeeService
{
    public function list(int $tenantId, array $filters): Collection
    {
        $query = HrEmployee::where('tenant_id', $tenantId);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['department']) && $filters['department'] !== 'All') {
            $query->where('department', $filters['department']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                  ->orWhere('employee_code', 'like', '%'.$search.'%')
                  ->orWhere('designation', 'like', '%'.$search.'%');
            });
        }

        return $query->latest()->get();
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
