<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrSalaryStructure;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class SalaryStructureRepository extends BaseRepository
{
    protected string $modelClass = HrSalaryStructure::class;

    /** Tenant-scoped listing with optional search / grade / status filters. */
    public function filtered(int $tenantId, array $filters): Collection
    {
        $query = HrSalaryStructure::where('tenant_id', $tenantId)
            ->with(['lines.component', 'grade:id,name', 'designation:id,name']);

        if (! empty($filters['grade_id']) && $filters['grade_id'] !== 'All') {
            $query->where('grade_id', $filters['grade_id']);
        }
        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== 'All') {
            $query->where('is_active', $filters['status'] === 'Active');
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                  ->orWhere('code', 'like', '%'.$search.'%');
            });
        }

        return $query->orderBy('name')->get();
    }

    public function findForTenant(int $id, int $tenantId): ?HrSalaryStructure
    {
        return HrSalaryStructure::where('tenant_id', $tenantId)
            ->with(['lines.component', 'grade:id,name', 'designation:id,name'])
            ->find($id);
    }
}
