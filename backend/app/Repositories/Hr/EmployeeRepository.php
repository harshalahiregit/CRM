<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrEmployee;
use App\Repositories\BaseRepository;

class EmployeeRepository extends BaseRepository
{
    protected string $modelClass = HrEmployee::class;

    public function filtered(int $tenantId, array $filters)
    {
        // employeeOnboarding is eager-loaded for the derived onboarding_* fields.
        $query = HrEmployee::with('employeeOnboarding')->where('tenant_id', $tenantId);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['department']) && $filters['department'] !== 'All') {
            $query->where('department', $filters['department']);
        }
        if (! empty($filters['designation']) && $filters['designation'] !== 'All') {
            $query->where('designation', $filters['designation']);
        }
        if (! empty($filters['joined_from'])) {
            $query->whereDate('joining_date', '>=', $filters['joined_from']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                  ->orWhere('employee_code', 'like', '%'.$search.'%')
                  ->orWhere('email', 'like', '%'.$search.'%')
                  ->orWhere('department', 'like', '%'.$search.'%')
                  ->orWhere('designation', 'like', '%'.$search.'%');
            });
        }

        // Paginated to keep large tenants responsive. per_page is clamped so a client
        // cannot request an unbounded page; filters above are applied before paging.
        $perPage = (int) ($filters['per_page'] ?? 25);
        $perPage = max(1, min($perPage, 200));

        return $query->latest()->paginate($perPage);
    }
}
