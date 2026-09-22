<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrEmployee;
use App\Repositories\BaseRepository;

class EmployeeRepository extends BaseRepository
{
    protected string $modelClass = HrEmployee::class;

    /**
     * @param  \App\Models\User|null  $actor  Whose view this is. Null keeps the
     *         query exactly as it was — the signature is additive so existing
     *         callers (console, jobs, anything not yet threaded through) behave
     *         identically.
     */
    public function filtered(int $tenantId, array $filters, ?\App\Models\User $actor = null)
    {
        // employeeOnboarding is eager-loaded for the derived onboarding_* fields.
        $query = HrEmployee::with('employeeOnboarding')->where('tenant_id', $tenantId);

        // Scope narrows WITHIN the tenant; it never replaces the tenant filter
        // above and never widens it. A global actor is left untouched, which is
        // what keeps today's behaviour byte-identical.
        //
        // BRANCH is deliberately not listed: hr_employees.branch is free text
        // with no master behind it and no values on this database, so a role
        // scoped to branch reads as global here and says so in the hr log. It
        // moves into this list the day branch data is real.
        $query = app(\App\Services\Auth\ScopeResolver::class)->applyToQuery(
            $query,
            $actor,
            'id',   // the directory IS the employee table
            [\App\Support\Hr\DataScope::OWN, \App\Support\Hr\DataScope::DEPARTMENT, \App\Support\Hr\DataScope::TEAM],
        );

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
