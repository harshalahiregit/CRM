<?php

namespace App\Repositories\Task;

use App\Models\Task\Task;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

class TaskRepository extends BaseRepository
{
    protected string $modelClass = Task::class;

    /** Filtered, tenant-scoped task list. */
    public function filtered(int $tenantId, array $filters): Collection
    {
        $query = Task::forTenant($tenantId)->with('creator:id,name');

        foreach (['status', 'priority', 'rel_type'] as $col) {
            if (! empty($filters[$col])) {
                $query->where($col, $filters[$col]);
            }
        }
        if (! empty($filters['rel_id'])) {
            $query->where('rel_id', $filters['rel_id']);
        }
        if (! empty($filters['search'])) {
            $s = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('name', 'like', $s)->orWhere('description', 'like', $s));
        }
        // Assignee filter uses the pivot added in Step 4 — guard until it exists.
        if (! empty($filters['assignee']) && Schema::hasTable('task_assignees')) {
            $query->whereHas('assignees', fn ($q) => $q->where('user_id', $filters['assignee']));
        }
        // Tags live in the shared taggables table — filter without joining it into
        // the model, so Task stays unaware of how tagging is stored.
        if (! empty($filters['tag']) && Schema::hasTable('taggables')) {
            $query->whereIn('id', function ($q) use ($filters, $tenantId) {
                $q->select('taggable_id')->from('taggables')
                    ->join('tags', 'tags.id', '=', 'taggables.tag_id')
                    ->where('taggables.tenant_id', $tenantId)
                    ->where('taggables.taggable_type', 'task')
                    ->where('tags.name', $filters['tag']);
            });
        }

        return $query->orderBy('kanban_order')->latest()->get();
    }

    public function findForTenant(int $id, int $tenantId): ?Task
    {
        return Task::forTenant($tenantId)->find($id);
    }
}
