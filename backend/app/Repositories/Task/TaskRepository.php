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

        // Subtasks are work INSIDE a task, not rows beside it. A task with thirty
        // subtasks would otherwise bury the board and inflate every count. They
        // stay hidden unless explicitly asked for, in which case each one carries
        // its parent so the row still makes sense out of context.
        if (Schema::hasColumn('tasks', 'parent_id')) {
            if (empty($filters['include_subtasks'])) {
                $query->whereNull('parent_id');
            } else {
                $query->with('parent:id,name');
            }
        }

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

        // A scope of BOTH type and id ("this project's tasks", "this vendor's
        // tasks") matches the primary link OR an additional "Related To" row.
        //
        // Meeting.docx §8 requires a MOM action to appear on the relevant
        // Project dashboard AND stay on the vendor it came from; a task has only
        // one primary link, so the second one has to be an additional relation,
        // and a scope that ignored those would never show it.
        if (! empty($filters['rel_type']) && ! empty($filters['rel_id']) && Schema::hasTable('task_relations')) {
            $type = $filters['rel_type'];
            $id = $filters['rel_id'];
            $query->where(function ($q) use ($type, $id) {
                $q->where(fn ($x) => $x->where('rel_type', $type)->where('rel_id', $id))
                    ->orWhereHas('relations', fn ($x) => $x->where('rel_type', $type)->where('rel_id', $id));
            });
        } else {
            foreach (['status', 'priority', 'rel_type'] as $col) {
                if (! empty($filters[$col])) {
                    $query->where($col, $filters[$col]);
                }
            }
        }
        foreach (['status', 'priority'] as $col) {
            if (! empty($filters[$col]) && ! empty($filters['rel_type']) && ! empty($filters['rel_id'])) {
                $query->where($col, $filters[$col]);
            }
        }
        // Multi-type scope, e.g. the Sales module's Tasks view asks for
        // rel_types=customer,contract so it shows only its own tasks.
        if (! empty($filters['rel_types'])) {
            $types = is_array($filters['rel_types']) ? $filters['rel_types'] : explode(',', (string) $filters['rel_types']);
            $types = array_filter(array_map('trim', $types));
            if ($types) {
                $query->whereIn('rel_type', $types);
            }
        }
        if (! empty($filters['rel_id']) && empty($filters['rel_type'])) {
            $query->where('rel_id', $filters['rel_id']);
        }

        // "Hide project tasks on main tasks table" (Project Settings): when the
        // list is NOT scoped to one project, drop tasks belonging to projects that
        // opted out of the global admin list. Inside a project (rel_id set) the
        // setting doesn't apply — you always see that project's own tasks there.
        if (empty($filters['rel_id']) && Schema::hasTable('projects')) {
            $hidden = \App\Models\Project\Project::where('tenant_id', $tenantId)
                ->where('hide_tasks_on_main', true)->pluck('id')->all();
            if ($hidden) {
                $query->where(function ($q) use ($hidden) {
                    $q->where('rel_type', '!=', 'project')
                        ->orWhereNull('rel_id')
                        ->orWhereNotIn('rel_id', $hidden);
                });
            }
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

            $predicate = function ($q) use ($uid, $pids) {
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
            };

            // Also visible when the top of its tree is: reaching a task means
            // reaching the work inside it, or the tree lists with holes in it.
            $query->where(function ($outer) use ($predicate) {
                $outer->where($predicate);
                if (Schema::hasColumn('tasks', 'root_id')) {
                    $outer->orWhereHas('rootTask', fn ($r) => $r->where($predicate));
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
