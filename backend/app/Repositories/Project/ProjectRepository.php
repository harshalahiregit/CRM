<?php

namespace App\Repositories\Project;

use App\Models\Project\Project;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class ProjectRepository extends BaseRepository
{
    protected string $modelClass = Project::class;

    /** Filtered, tenant-scoped project list. */
    public function filtered(int $tenantId, array $filters): Collection
    {
        $query = Project::forTenant($tenantId)->with('creator:id,name');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if (! empty($filters['search'])) {
            $s = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('name', 'like', $s)->orWhere('description', 'like', $s));
        }

        return $query->latest()->get();
    }

    public function findForTenant(int $id, int $tenantId): ?Project
    {
        return Project::forTenant($tenantId)->find($id);
    }
}
