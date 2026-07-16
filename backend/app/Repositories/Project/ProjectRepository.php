<?php

namespace App\Repositories\Project;

use App\Models\Project\Project;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

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
        if (! empty($filters['member'])) {
            $query->whereHas('members', fn ($q) => $q->where('user_id', $filters['member']));
        }
        // Tags live in the shared taggables table — filter without joining it into
        // the model, so Project stays unaware of how tagging is stored.
        if (! empty($filters['tag']) && Schema::hasTable('taggables')) {
            $query->whereIn('id', function ($q) use ($filters, $tenantId) {
                $q->select('taggable_id')->from('taggables')
                    ->join('tags', 'tags.id', '=', 'taggables.tag_id')
                    ->where('taggables.tenant_id', $tenantId)
                    ->where('taggables.taggable_type', 'project')
                    ->where('tags.name', $filters['tag']);
            });
        }

        return $query->latest()->get();
    }

    public function findForTenant(int $id, int $tenantId): ?Project
    {
        return Project::forTenant($tenantId)->find($id);
    }
}
