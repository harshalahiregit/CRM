<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrSalaryComponent;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class SalaryComponentRepository extends BaseRepository
{
    protected string $modelClass = HrSalaryComponent::class;

    /** Tenant-scoped listing with optional search / type / status filters. */
    public function filtered(int $tenantId, array $filters): Collection
    {
        $query = HrSalaryComponent::where('tenant_id', $tenantId);

        if (! empty($filters['type']) && $filters['type'] !== 'All') {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== 'All') {
            $query->where('is_active', $filters['status'] === 'Active');
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                  ->orWhere('code', 'like', '%'.$search.'%')
                  ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        // Grouped by type, then name — a master list, small by nature.
        return $query->orderByRaw("CASE type WHEN 'Earning' THEN 1 WHEN 'Deduction' THEN 2 WHEN 'Benefit' THEN 3 ELSE 4 END")
            ->orderBy('name')
            ->get();
    }

    /** Tenant-safe fetch for mutations (route ids are not implicitly bound). */
    public function findForTenant(int $id, int $tenantId): ?HrSalaryComponent
    {
        return HrSalaryComponent::where('tenant_id', $tenantId)->find($id);
    }
}
