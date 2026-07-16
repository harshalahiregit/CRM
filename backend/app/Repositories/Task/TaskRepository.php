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
    public function filtered(int $tenantId, array $filters, ?array $visibility = null): Collection
    {
        $query = Task::forTenant($tenantId)->with('creator:id,name');

        // Counts the list view needs: checklist progress, and the comment/file
        // badges on kanban cards. withCount is one extra query total, versus
        // eager-loading every child row just to call ->count() on it.
        $query->withCount([
            'checklistItems',
            'checklistItems as checklist_done_count' => fn ($q) => $q->where('finished', true),
        ]);
        if (Schema::hasTable('task_comments')) {
            $query->withCount('comments');
        }
        if (Schema::hasTable('task_files')) {
            $query->withCount('files');
        }
        // Avatars on cards / the ASSIGNED TO column — without this the list N+1s.
        if (Schema::hasTable('task_assignees')) {
            $query->with('assignees.user:id,name');
        }

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

        // Access barrier: a non-admin only sees tasks they own, are assigned to,
        // follow, that are Public, or that belong to a project they're in. The
        // caller (TaskService) passes null for admins, which skips this entirely.
        if ($visibility) {
            $uid = (int) $visibility['user_id'];
            $pids = $visibility['project_ids'] ?? [];
            $query->where(function ($q) use ($uid, $pids) {
                $q->where('created_by', $uid)->orWhere('is_public', true);
                if (Schema::hasTable('task_assignees')) {
                    $q->orWhereHas('assignees', fn ($a) => $a->where('user_id', $uid));
                }
                if (Schema::hasTable('task_followers')) {
                    $q->orWhereHas('followers', fn ($f) => $f->where('user_id', $uid));
                }
                if (! empty($pids)) {
                    $q->orWhere(fn ($x) => $x->where('rel_type', 'project')->whereIn('rel_id', $pids));
                }
            });
        }

        return $query->orderBy('kanban_order')->latest()->get();
    }

    public function findForTenant(int $id, int $tenantId): ?Task
    {
        return Task::forTenant($tenantId)->find($id);
    }
}
