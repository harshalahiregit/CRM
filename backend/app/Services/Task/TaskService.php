<?php

namespace App\Services\Task;

use App\Exceptions\BusinessException;
use App\Models\Helpdesk\Ticket;
use App\Models\Project\Project;
use App\Models\Task\Task;
use App\Models\Task\TaskChecklistItem;
use App\Models\Task\TaskChecklistTemplate;
use App\Models\Task\TaskComment;
use App\Models\Task\TaskFile;
use App\Models\Task\TaskReminder;
use App\Models\Task\TaskTimer;
use App\Models\User;
use App\Repositories\Task\TaskRepository;
use App\Services\Helpdesk\Contracts\CustomerServiceContract;
use App\Services\Helpdesk\Mocks\MockCustomerService;
use App\Services\NotificationService;
use App\Services\TagService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TaskService
{
    /** Portal-only roles — excluded from staff pickers, @mentions and reminders. */
    private const EXTERNAL_ROLES = ['client', 'vendor', 'third_party_vendor'];

    /**
     * Roles that may NOT be assigned/followed. Vendors and third-party vendors
     * ARE allowed (work can be delegated to them; they see it on their portal
     * dashboard) — only customers (`client`) are blocked.
     */
    private const NON_ASSIGNABLE_ROLES = ['client'];

    /** Human labels for the stored status keys — used in notification copy. */
    private const STATUS_LABELS = [
        'not_started'       => 'Not Started',
        'in_progress'       => 'In Progress',
        'awaiting_feedback' => 'Awaiting Feedback',
        'testing'           => 'Testing',
        'complete'          => 'Complete',
    ];

    private CustomerServiceContract $customers;

    public function __construct(
        private TaskRepository $tasks,
        private NotificationService $notifications,
        private TagService $tags,
        private \App\Services\StatusService $statuses,
        private TaskTreeService $tree,
        private TaskNotifier $notifier,
        private TaskVendorLinkNotifier $vendorNotifier,
        private \App\Services\Shared\PartyAssignmentService $parties,
        ?CustomerServiceContract $customers = null,
    ) {
        $this->customers = $customers ?? new MockCustomerService();
    }

    /** key => label for this tenant's task statuses; falls back to the const. */
    private function statusLabels(int $tenantId): array
    {
        return $this->statuses->labels('task', $tenantId) ?: self::STATUS_LABELS;
    }

    /** The status key that closes a task ('complete' unless reconfigured). */
    private function closedKey(int $tenantId): string
    {
        return $this->statuses->closedKey('task', $tenantId) ?? 'complete';
    }

    /* ── Access control ─────────────────────────────────────────────
     * Admins see every task in the tenant. A non-admin staff member only
     * sees a task they created, are assigned to, follow, that's marked
     * Public, or that lives in a project they belong to. Same predicate
     * decides whether they can open / edit / delete it.
     */

    private array $visibleProjectCache = [];

    /** Project ids this staff user can see (member of, or created). */
    private function visibleProjectIds(int $tenantId, int $userId): array
    {
        $key = "$tenantId:$userId";
        if (isset($this->visibleProjectCache[$key])) {
            return $this->visibleProjectCache[$key];
        }

        $ids = Project::forTenant($tenantId)
            ->where(fn ($q) => $q->where('created_by', $userId)
                ->orWhereHas('members', fn ($m) => $m->where('user_id', $userId)))
            ->pluck('id')->map(fn ($i) => (int) $i)->all();

        return $this->visibleProjectCache[$key] = $ids;
    }

    /** Narrow a Task query to what this staff user is allowed to see. */
    private function scopeVisible($query, int $tenantId, int $userId)
    {
        $pids = $this->visibleProjectIds($tenantId, $userId);

        // The same predicate is applied twice: to the row itself, and to the top
        // of its tree — so a subtask shows up in a listing when you can reach the
        // task it lives inside. Exhaustive ancestor-walking is left to
        // canSeeTask(); doing it per row in SQL would cost far more than it's
        // worth on a list, and erring toward showing LESS here is safe.
        $predicate = function ($q) use ($userId, $pids) {
            $q->where('created_by', $userId)->orWhere('is_public', true);
            if ($this->tableExists('task_assignees')) {
                $q->orWhereHas('assignees', fn ($a) => $a->where('user_id', $userId));
            }
            if ($this->tableExists('task_followers')) {
                $q->orWhereHas('followers', fn ($f) => $f->where('user_id', $userId));
            }
            if (! empty($pids)) {
                $q->orWhere(fn ($x) => $x->where('rel_type', 'project')->whereIn('rel_id', $pids));
            }
        };

        return $query->where(function ($outer) use ($predicate) {
            $outer->where($predicate);
            if ($this->columnExists('tasks', 'root_id')) {
                $outer->orWhereHas('rootTask', fn ($r) => $r->where($predicate));
            }
        });
    }

    /**
     * A subtask inherits visibility from ANY of its ancestors.
     *
     * Judged node-by-node instead, a tree renders with holes: you'd see
     * "Website" and "Design" but not "Homepage mockup" because nobody put you on
     * it — while the progress bar above still counted it. A percentage you
     * cannot account for is worse than no percentage.
     *
     * Checking only the root isn't enough either: put someone on "Design" and
     * they own that branch, so everything under Design must open for them even
     * though the root above it is somebody else's. Hence the whole chain, not
     * just the two ends.
     */
    private function canSeeTask(Task $task, int $tenantId, int $userId): bool
    {
        if ($this->canSeeNode($task, $tenantId, $userId)) {
            return true;
        }

        if (! $task->parent_id) {
            return false;
        }

        // ancestryOf() is top-first and includes this task; drop it and try each
        // ancestor. One query, bounded by MAX_DEPTH.
        $chain = $this->tree->ancestryOf($task, $tenantId);
        $ancestorIds = array_column($chain, 'id');
        array_pop($ancestorIds);

        if (! $ancestorIds) {
            return false;
        }

        foreach (Task::forTenant($tenantId)->whereIn('id', $ancestorIds)->get() as $ancestor) {
            if ($this->canSeeNode($ancestor, $tenantId, $userId)) {
                return true;
            }
        }

        return false;
    }

    /** The per-task rule, with no tree involved. */
    private function canSeeNode(Task $task, int $tenantId, int $userId): bool
    {
        if ((int) $task->created_by === $userId || $task->is_public) {
            return true;
        }
        if ($this->tableExists('task_assignees') && $task->assignees()->where('user_id', $userId)->exists()) {
            return true;
        }
        if ($this->tableExists('task_followers') && $task->followers()->where('user_id', $userId)->exists()) {
            return true;
        }

        return $task->rel_type === 'project' && $task->rel_id
            && in_array((int) $task->rel_id, $this->visibleProjectIds($tenantId, $userId), true);
    }

    /**
     * Load a task and confirm the actor may reach it. Admins always may;
     * a staff member must pass canSeeTask(). Used by every single-task and
     * sub-resource endpoint so ids can't be walked.
     */
    public function assertTaskVisible(int $taskId, int $tenantId, ?int $userId, bool $isAdmin): Task
    {
        $task = $this->find($taskId, $tenantId);

        if (! $isAdmin && $userId !== null && ! $this->canSeeTask($task, $tenantId, $userId)) {
            throw new BusinessException('You do not have access to this task.', 403);
        }

        return $task;
    }

    public function list(int $tenantId, array $filters = [], ?int $userId = null, bool $isAdmin = true): Collection
    {
        $visibility = (! $isAdmin && $userId !== null)
            ? ['user_id' => $userId, 'project_ids' => $this->visibleProjectIds($tenantId, $userId)]
            : null;

        $tasks = $this->decorateMany($this->tasks->filtered($tenantId, $filters, $visibility), $tenantId);

        // Batched — one tag query for the whole page, not one per row.
        $ids = $tasks->pluck('id')->all();
        $tagMap = $this->tags->tagsForMany('task', $ids, $tenantId);
        // Same batching reason: a board of fifty tasks must not become fifty
        // queries for the chips beside each name.
        $partyMap = $this->parties->forSubjects(\App\Models\Shared\PartyAssignee::SUBJECT_TASK, $ids);

        return $tasks->each(function (Task $t) use ($tagMap, $partyMap) {
            $t->setAttribute('tags', $tagMap[$t->id] ?? []);
            $t->setAttribute('party_assignees', $partyMap[$t->id] ?? []);
        });
    }

    public function show(int $id, int $tenantId): Task
    {
        $task = $this->find($id, $tenantId);
        $task->load([
            'creator:id,name', 'milestone:id,name',
            'assignees.user:id,name,email', 'followers.user:id,name',
            'checklistItems.assignee:id,name', 'comments.user:id,name', 'comments.attachments', 'timers.user:id,name',
        ]);
        $task->setAttribute('tags', $this->tags->tagsFor('task', $task->id, $tenantId));

        // The bar at the top of the modal. Computed server-side so the modal,
        // the board and the project percentage can never quote different numbers
        // for the same work.
        $task->setAttribute('progress', $this->tree->progressForTask($task, $tenantId));
        $task->setAttribute('ancestry', $this->tree->ancestryOf($task, $tenantId));
        // People at the client / vendor / TPV who own this one. A separate
        // attribute from `assignees` because they are not users and the screen
        // must not blur the two — see PartyAssignmentService.
        $task->setAttribute('party_assignees', $this->parties->forSubject(\App\Models\Shared\PartyAssignee::SUBJECT_TASK, $task->id));
        // PR1 — the effective billable amount (fixed if set, else rate × hours).
        // Visibility is gated to admins in the controller.
        $task->setAttribute('billable_amount_effective', $task->effectiveBillableAmount());

        return $this->decorateRelation($task, $tenantId);
    }

    /* ── Subtasks ───────────────────────────────────────────────── */

    /** The whole tree under a task, each node with its own rolled-up progress. */
    public function subtree(int $taskId, int $tenantId): array
    {
        return $this->tree->tree($taskId, $tenantId);
    }

    /** Rolled-up completion for one task: checklist items + the subtask tree. */
    public function progressFor(int $taskId, int $tenantId): array
    {
        return $this->tree->progressFor($taskId, $tenantId);
    }

    /**
     * Add a child task. Everything about it is its own — deadline, assignees,
     * status — which is the entire point of a subtask over a checklist line.
     */
    public function addSubtask(int $parentId, array $data, int $tenantId, int $userId): Task
    {
        $parent = $this->find($parentId, $tenantId);

        // A subtask belongs to whatever the parent belongs to, so a project's
        // task tree stays inside that project rather than leaking to standalone.
        $child = $this->create([
            ...$data,
            'parent_id' => $parent->id,
            'rel_type'  => $data['rel_type'] ?? $parent->rel_type,
            'rel_id'    => $data['rel_id'] ?? $parent->rel_id,
        ], $tenantId, $userId);

        $this->notifier->subtaskAdded($child, $parent, $userId);

        return $child;
    }

    /** Re-parent a task (drag it elsewhere in the tree, or out to the top). */
    public function moveSubtask(int $taskId, ?int $newParentId, int $tenantId): Task
    {
        return $this->tree->moveTo($taskId, $newParentId, $tenantId);
    }

    /**
     * Create a task. Auto-status rule (spec): when no status is supplied,
     * status = in_progress if today >= start_date, else not_started.
     */
    public function create(array $data, int $tenantId, int $userId): Task
    {
        $relType = $data['rel_type'] ?? 'standalone';

        // The create form now sends the description as rich-text HTML, so it must
        // pass the same allowlist sanitizer as the inline edit path (update()) —
        // otherwise a hand-crafted payload would be stored raw and rendered.
        if (array_key_exists('description', $data) && $data['description'] !== null) {
            $data['description'] = \App\Support\HtmlSanitizer::clean($data['description']);
        }

        // Customer link resolves through the contract (same mock as Helpdesk).
        if ($relType === 'customer' && ! empty($data['rel_id'])
            && ! $this->customers->exists((int) $data['rel_id'], $tenantId)) {
            throw new BusinessException('The selected customer does not exist.', 422);
        }

        if (empty($data['status'])) {
            $start = Carbon::parse($data['start_date'])->startOfDay();
            $data['status'] = now()->startOfDay()->gte($start) ? 'in_progress' : 'not_started';
        }

        // People, tags and extra relations are child rows, not columns — pull them
        // out before the insert.
        $assignees = $data['assignee_ids'] ?? [];
        $followers = $data['follower_ids'] ?? [];
        $tags = $data['tags'] ?? null;
        $relations = $data['relations'] ?? null;
        unset($data['assignee_ids'], $data['follower_ids'], $data['tags'], $data['relations']);

        // Where this sits in the subtask tree. A subtask deliberately inherits
        // NOTHING from its parent except its position — not the assignees, not
        // the deadline. It is a task in its own right that happens to live under
        // another one.
        $place = $this->tree->placeUnder(
            ! empty($data['parent_id']) ? (int) $data['parent_id'] : null,
            $tenantId
        );
        unset($data['parent_id'], $data['root_id'], $data['depth']);

        $task = $this->tasks->create([...$data, ...$place, 'tenant_id' => $tenantId, 'created_by' => $userId]);

        // A top-level task is the root of its own tree, which can only be
        // stamped once the insert has given us an id.
        if (! $place['parent_id']) {
            $task->forceFill(['root_id' => $task->id])->save();
        }

        // syncPivot notifies, so assigning at creation tells people right away.
        if ($assignees) {
            $this->syncAssignees($task->id, $assignees, $tenantId, $userId);
        }
        if ($followers) {
            $this->syncFollowers($task->id, $followers, $tenantId, $userId);
        }
        if ($tags !== null) {
            $this->tags->sync('task', $task->id, $tags, $tenantId);
        }
        $this->syncRelations($task->id, $relations, $tenantId);

        // Filing a task against a vendor is how a Purchase vendor is reached at
        // all — it has no User to assign — so the link itself has to announce
        // itself. See TaskVendorLinkNotifier.
        $this->notifyVendorLink($task, $userId);

        $task = $this->decorateRelation($task->fresh('creator'), $tenantId);
        $task->setAttribute('tags', $this->tags->tagsFor('task', $task->id, $tenantId));

        return $task;
    }

    /**
     * Ring the vendor a task is filed against, if it is filed against one.
     *
     * Kept in one place so create() and update() cannot drift apart — a task
     * linked on the edit screen has to notify exactly as one linked at creation.
     */
    private function notifyVendorLink(Task $task, ?int $actorId): void
    {
        if (in_array($task->rel_type, ['tpv_vendor', 'purchase_vendor'], true) && $task->rel_id) {
            $this->vendorNotifier->linked($task, (string) $task->rel_type, (int) $task->rel_id, $actorId);
        }
    }

    public function update(int $id, array $data, int $tenantId, ?int $actorId = null): Task
    {
        $task = $this->find($id, $tenantId);

        $tags = $data['tags'] ?? null;
        $relations = array_key_exists('relations', $data) ? ($data['relations'] ?? []) : null;
        unset($data['tags'], $data['relations']);

        // The description is now edited inline with a rich-text editor, so it arrives
        // as HTML — sanitize it with the same allowlist as comments before saving.
        if (array_key_exists('description', $data) && $data['description'] !== null) {
            $data['description'] = \App\Support\HtmlSanitizer::clean($data['description']);
        }

        $task->fill($data);

        // Moving the deadline re-arms the "due soon" nudge — otherwise a task
        // pushed out by a month would never warn again.
        if ($task->isDirty('due_date')) {
            $task->deadline_notified = false;
        }

        // Captured BEFORE the save, because after it isDirty() is clean again.
        // Only a genuine change announces itself: editing a description on a task
        // that has been linked to the same vendor for a month must not tell them
        // it is new.
        $linkChanged = $task->isDirty('rel_type') || $task->isDirty('rel_id');

        $task->save();

        if ($linkChanged) {
            $this->notifyVendorLink($task, $actorId);
        }

        if ($tags !== null) {
            $this->tags->sync('task', $task->id, $tags, $tenantId);
        }
        $this->syncRelations($task->id, $relations, $tenantId);

        $fresh = $this->decorateRelation($task->fresh('creator'), $tenantId);
        $fresh->setAttribute('tags', $this->tags->tagsFor('task', $task->id, $tenantId));

        return $fresh;
    }

    /** Change status; stamp date_finished on the transition into the closing status. */
    public function changeStatus(int $id, string $status, int $tenantId, ?int $actorId = null): Task
    {
        $task = $this->find($id, $tenantId);
        $from = $task->status;

        // Honour a configured workflow (can_be_changed_to); a no-op until one exists.
        $this->statuses->assertTransition('task', $from, $status, $tenantId);

        $closed = $this->closedKey($tenantId);
        $task->status = $status;
        $task->date_finished = $status === $closed ? now() : null;
        $task->save();

        if ($from !== $status) {
            $labels = $this->statusLabels($tenantId);
            $label = $labels[$status] ?? $status;
            $title = "Task {$label}: {$task->name}";
            $body = ($labels[$from] ?? $from)." → {$label}";

            // A subtask CLOSING is its own event, announced up the tree, because
            // what the people above care about is the parent's new percentage —
            // not that a status field changed. Everything else is plain activity.
            if ($status === $closed && $task->parent_id) {
                $this->notifier->subtaskCompleted($task, (int) $actorId);
            } else {
                $watchers = $this->watcherIds($task, $actorId);
                foreach ($watchers as $uid) {
                    $this->notifications->notify(
                        $uid, $tenantId, 'task.status_changed', $title, $body,
                        "/app/tasks/{$task->id}", $actorId,
                    );
                }
                $this->notifier->activity($task, $watchers, 'task.status_changed', $title, $body, (int) $actorId);
            }
        }

        return $this->decorateRelation($task->fresh('creator'), $tenantId);
    }

    /**
     * Everyone watching a task: assignees + followers + creator, minus the actor.
     * This is what makes the followers table readable rather than write-only.
     */
    private function watcherIds(Task $task, ?int $actorId): array
    {
        $ids = $task->assignees()->pluck('user_id')
            ->merge($task->followers()->pluck('user_id'))
            ->push($task->created_by)
            ->map(fn ($i) => (int) $i)
            ->unique()
            ->reject(fn ($i) => $actorId !== null && $i === $actorId);

        return $ids->values()->all();
    }

    /**
     * Delete a task AND everything nested inside it.
     *
     * Subtasks are the work that makes up this task, not neighbours of it —
     * "Pick colours" outliving "Website" is not a task anyone can act on, and
     * left behind it points at a parent that no longer exists, so it vanishes
     * from every tree while still counting nowhere. Deleting the branch is the
     * only answer that leaves the data describing something real.
     *
     * Soft deletes throughout, so this is recoverable at the database level.
     */
    public function delete(int $id, int $tenantId): void
    {
        $task = $this->find($id, $tenantId);

        $descendants = $this->tree->descendantIds($task->id, $tenantId);
        if ($descendants) {
            Task::forTenant($tenantId)->whereIn('id', $descendants)->delete();
        }

        $task->delete();
    }

    /**
     * Soft-deleted tasks that can be recovered. Only the TOP of each deleted branch
     * is returned — a task's subtree is cascade-deleted with it and comes back as a
     * unit, so listing every buried descendant separately would just be noise.
     * Admins see the whole trash; staff see only what they created.
     */
    public function trashed(int $tenantId, int $userId, bool $isAdmin): Collection
    {
        $q = Task::forTenant($tenantId)->onlyTrashed();
        if (! $isAdmin) {
            $q->where('created_by', $userId);
        }
        $rows = $q->orderByDesc('deleted_at')
            ->get(['id', 'name', 'parent_id', 'rel_type', 'rel_id', 'deleted_at', 'created_by']);

        $trashedIds = $rows->pluck('id')->all();

        return $rows
            ->filter(fn ($t) => $t->parent_id === null || ! in_array($t->parent_id, $trashedIds, true))
            ->values();
    }

    /**
     * Put a soft-deleted task back, along with any descendants that were
     * cascade-deleted with it, so the tree returns whole rather than with holes.
     */
    public function restore(int $id, int $tenantId, int $userId, bool $isAdmin): void
    {
        $task = Task::forTenant($tenantId)->onlyTrashed()->find($id);
        if (! $task) {
            throw new BusinessException('That task is not in the trash.', 404);
        }
        if (! $isAdmin && (int) $task->created_by !== $userId) {
            throw new BusinessException('You can only restore tasks you created.', 403);
        }

        $ids = $this->trashedSubtreeIds($id, $tenantId);
        Task::forTenant($tenantId)->onlyTrashed()->whereIn('id', $ids)->restore();
    }

    /** BFS over parent_id among trashed rows to gather a deleted branch. */
    private function trashedSubtreeIds(int $rootId, int $tenantId): array
    {
        $ids = [$rootId];
        $frontier = [$rootId];
        while ($frontier) {
            $children = Task::forTenant($tenantId)->onlyTrashed()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')->map(fn ($i) => (int) $i)->all();
            $children = array_values(array_diff($children, $ids));
            if (! $children) {
                break;
            }
            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return $ids;
    }

    /**
     * Persist a kanban column's order after a drag. Rewrites kanban_order to the
     * given sequence and moves any card that changed column into $status.
     * Ids not in this tenant are ignored rather than throwing — a stale board
     * shouldn't 500 the drop.
     */
    public function reorder(array $orderedIds, string $status, int $tenantId, ?int $actorId = null, bool $isAdmin = true): int
    {
        $ids = array_values(array_unique(array_map('intval', $orderedIds)));
        if (! $ids) {
            return 0;
        }

        // A drag can move a card to another status — only let a staff member
        // reorder tasks they're allowed to touch.
        if (! $isAdmin && $actorId !== null) {
            $visible = $this->scopeVisible(Task::forTenant($tenantId)->whereIn('id', $ids), $tenantId, $actorId)
                ->pluck('id')->map(fn ($i) => (int) $i)->all();
            $ids = array_values(array_intersect($ids, $visible));
            if (! $ids) {
                return 0;
            }
        }

        $tasks = Task::forTenant($tenantId)->whereIn('id', $ids)->get()->keyBy('id');
        $moved = [];
        $closed = $this->closedKey($tenantId);

        DB::transaction(function () use ($ids, $status, $tasks, $closed, &$moved) {
            foreach ($ids as $i => $id) {
                $task = $tasks->get($id);
                if (! $task) {
                    continue;
                }
                if ($task->status !== $status) {
                    $moved[] = $task->id;
                    $task->status = $status;
                    $task->date_finished = $status === $closed ? now() : null;
                }
                $task->kanban_order = $i;
                $task->save();
            }
        });

        // A drag across columns is a status change — notify like one.
        $labels = $this->statusLabels($tenantId);
        foreach ($moved as $id) {
            $task = $tasks->get($id);
            $label = $labels[$status] ?? $status;
            foreach ($this->watcherIds($task, $actorId) as $uid) {
                $this->notifications->notify(
                    $uid, $tenantId, 'task.status_changed',
                    "Task {$label}: {$task->name}",
                    "Moved to {$label}", "/app/tasks/{$task->id}", $actorId,
                );
            }
        }

        return count($ids);
    }

    /* ── Assignees & followers ──────────────────────────────────── */

    public function syncAssignees(int $taskId, array $userIds, int $tenantId, ?int $actorId = null): Collection
    {
        return $this->syncPivot($taskId, 'assignees', $userIds, $tenantId, $actorId);
    }

    public function syncFollowers(int $taskId, array $userIds, int $tenantId, ?int $actorId = null): Collection
    {
        return $this->syncPivot($taskId, 'followers', $userIds, $tenantId, $actorId);
    }

    private function syncPivot(int $taskId, string $relation, array $userIds, int $tenantId, ?int $actorId = null): Collection
    {
        $task = $this->find($taskId, $tenantId);
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        $valid = User::where('tenant_id', $tenantId)->whereIn('id', $userIds)
            ->whereNotIn('role', self::NON_ASSIGNABLE_ROLES)
            ->pluck('id')->all();
        if (count($valid) !== count($userIds)) {
            throw new BusinessException('One or more selected people cannot be assigned in this workspace.', 422);
        }

        // Who is newly added — captured inside the transaction, notified after it
        // commits so a notification failure can't roll back the assignment.
        $added = [];

        DB::transaction(function () use ($task, $relation, $valid, $tenantId, &$added) {
            $task->{$relation}()->whereNotIn('user_id', $valid ?: [0])->delete();
            $existing = $task->{$relation}()->pluck('user_id')->all();
            $added = array_values(array_diff($valid, $existing));
            foreach ($added as $uid) {
                $task->{$relation}()->create(['tenant_id' => $tenantId, 'user_id' => $uid]);
            }
        });

        $this->notifyPivotAdded($task, $relation, $added, $tenantId, $actorId);

        return $task->{$relation}()->with('user:id,name,email')->get();
    }

    /** Tell newly-added assignees/followers that the task landed on their plate. */
    private function notifyPivotAdded(Task $task, string $relation, array $added, int $tenantId, ?int $actorId): void
    {
        if (! $added) {
            return;
        }

        $isAssignee = $relation === 'assignees';
        $type  = $isAssignee ? 'task.assigned' : 'task.follower_added';
        $title = $isAssignee ? "Task assigned: {$task->name}" : "You're now following: {$task->name}";
        $due   = $task->due_date ? ' · due '.$task->due_date->format('M j') : '';

        foreach ($added as $uid) {
            $this->notifications->notify(
                $uid, $tenantId, $type, $title,
                ucfirst($task->priority).' priority'.$due,
                "/app/tasks/{$task->id}",
                $actorId,
            );
        }

        // Email leg. Only for assignees — being added as a follower is something
        // you find out when you next look, not something worth an inbox.
        if ($isAssignee) {
            $this->notifier->subtaskAssigned($task, $added, (int) $actorId);
        }
    }

    /* ── Checklist ──────────────────────────────────────────────── */

    public function listChecklist(int $taskId, int $tenantId): Collection
    {
        return $this->find($taskId, $tenantId)
            ->checklistItems()
            ->with(['assignee:id,name', 'assignees.user:id,name'])
            ->get();
    }

    public function addChecklistItem(int $taskId, string $description, int $tenantId, array|int|null $assignedTo = null): TaskChecklistItem
    {
        $task = $this->find($taskId, $tenantId);
        $order = ((int) $task->checklistItems()->max('order')) + 1;

        $item = $task->checklistItems()->create([
            'tenant_id'   => $tenantId,
            'description' => $description,
            'order'       => $order,
        ]);

        return $this->assignChecklistItem($item, $this->userIdList($assignedTo), $tenantId);
    }

    /**
     * Edit a checklist item in place — used to (re)assign it or fix its text.
     * Only the keys passed are touched, so assigning someone never clears the
     * description and vice-versa.
     */
    public function updateChecklistItem(int $itemId, array $data, int $tenantId): TaskChecklistItem
    {
        $item = TaskChecklistItem::forTenant($tenantId)->find($itemId);
        if (! $item) {
            throw new BusinessException('Checklist item not found.', 404);
        }

        if (array_key_exists('description', $data)) {
            $item->description = $data['description'];
            $item->save();
        }

        // Absent means "leave the people alone"; present-but-empty means
        // "take everyone off". null and [] must not be the same as not sending
        // the key at all, or renaming a line would silently unassign it.
        if (array_key_exists('assigned_to', $data)) {
            return $this->assignChecklistItem($item, $this->userIdList($data['assigned_to']), $tenantId);
        }

        return $item->load(['assignee:id,name', 'assignees.user:id,name']);
    }

    /**
     * Put a checklist line on a set of people — the whole set, replacing whoever
     * was on it.
     *
     * A line used to hold ONE user id, so "Priya and Rohit are doing this"
     * became two lines, or one line with one name and the other person told
     * verbally. The pivot is now the truth and `assigned_to` is kept pointing at
     * the first of the set, so the notification leg and anything outside this
     * module that reads the column keep working. This is the only place either
     * is written.
     *
     * @param  int[]  $userIds
     */
    public function assignChecklistItem(TaskChecklistItem $item, array $userIds, int $tenantId): TaskChecklistItem
    {
        $valid = $userIds
            ? User::where('tenant_id', $tenantId)->whereIn('id', $userIds)
                ->whereNotIn('role', self::NON_ASSIGNABLE_ROLES)
                ->pluck('id')->map(fn ($i) => (int) $i)->all()
            : [];

        if (count($valid) !== count(array_unique($userIds))) {
            throw new BusinessException('One or more selected people cannot be assigned in this workspace.', 422);
        }

        // Preserve the order they were picked in — the first is the one the
        // mirror column and the single-name callers will show.
        $ordered = array_values(array_filter($userIds, fn ($id) => in_array($id, $valid, true)));

        DB::transaction(function () use ($item, $ordered, $tenantId) {
            $item->assignees()->whereNotIn('user_id', $ordered ?: [0])->delete();

            $existing = $item->assignees()->pluck('user_id')->map(fn ($i) => (int) $i)->all();
            foreach (array_diff($ordered, $existing) as $uid) {
                $item->assignees()->create(['tenant_id' => $tenantId, 'user_id' => $uid]);
            }

            $item->forceFill(['assigned_to' => $ordered[0] ?? null])->save();
        });

        return $item->load(['assignee:id,name', 'assignees.user:id,name']);
    }

    /**
     * One id, a list of ids, or nothing — all the shapes the API accepts.
     *
     * The endpoint took a single `assigned_to` integer before this, and old
     * callers still send one. Normalising here rather than at each call site is
     * what lets both shapes mean the same thing.
     *
     * @return int[]
     */
    private function userIdList(array|int|null $input): array
    {
        if ($input === null || $input === '') {
            return [];
        }

        return collect(is_array($input) ? $input : [$input])
            ->map(fn ($i) => (int) $i)->filter()->unique()->values()->all();
    }

    /**
     * Remove a checklist line.
     *
     * A checklist is a scratchpad — a line gets added by mistake, or the work it
     * described stops being part of the task — and there was no way to take one
     * off, only to tick it, which reads as "we did it" and is the wrong record.
     *
     * The assignee rows go with it. They are the line's own pivot and mean
     * nothing without it; leaving them behind is what puts a user id in a
     * notification query for a line that no longer exists.
     */
    public function deleteChecklistItem(int $itemId, int $tenantId): int
    {
        $item = TaskChecklistItem::forTenant($tenantId)->find($itemId);
        if (! $item) {
            throw new BusinessException('Checklist item not found.', 404);
        }

        $taskId = (int) $item->task_id;

        DB::transaction(function () use ($item) {
            $item->assignees()->delete();
            $item->delete();
        });

        return $taskId;
    }

    public function toggleChecklistItem(int $itemId, int $tenantId, int $userId): TaskChecklistItem
    {
        $item = TaskChecklistItem::forTenant($tenantId)->find($itemId);
        if (! $item) {
            throw new BusinessException('Checklist item not found.', 404);
        }
        $item->finished = ! $item->finished;
        $item->finished_by = $item->finished ? $userId : null;
        $item->save();

        return $item->fresh();
    }

    /* ── Comments ───────────────────────────────────────────────── */

    public function listComments(int $taskId, int $tenantId): Collection
    {
        return $this->find($taskId, $tenantId)->comments()->with('user:id,name', 'attachments')->get();
    }

    /**
     * Post a comment on a task.
     *
     * $userId is the author when the author is a User (staff, TPV). It is null
     * for an author that has no User row -- a Purchase vendor writing from its
     * portal -- and $author then carries who it actually was:
     *
     *     ['kind' => 'purchase_vendor', 'id' => 7, 'name' => 'Acme Ltd']
     *
     * The name is snapshotted rather than joined, because the shared Task module
     * must not learn how to read a Purchase table. See TaskComment.
     */
    public function addComment(int $taskId, string $content, int $tenantId, ?int $userId, array $files = [], ?array $author = null): TaskComment
    {
        $task = $this->find($taskId, $tenantId);

        // Comments are now authored in a rich-text editor, so the content arrives as
        // HTML. Run it through the same strict allowlist sanitizer the helpdesk reply
        // path uses before persisting — safe tags/attributes survive, script/style and
        // event handlers are dropped — which removes the XSS risk that previously kept
        // comments plain-text. strip_tags() below still derives a plain excerpt/mentions.
        $content = \App\Support\HtmlSanitizer::clean($content);

        $comment = $task->comments()->create([
            'tenant_id'   => $tenantId,
            'user_id'     => $userId,
            'content'     => $content,
            'author_kind' => $author['kind'] ?? 'user',
            'author_id'   => $author['id'] ?? $userId,
            'author_name' => $author['name'] ?? null,
        ]);

        // Attachments dropped on the comment are stored as task files carrying this
        // comment_id — same storage/download as any task file, just scoped to the
        // comment so the task-level Files card doesn't also list them.
        foreach ($files as $file) {
            $comment->attachments()->create([
                'tenant_id'   => $tenantId,
                'task_id'     => $task->id,
                'file_path'   => $file->store("tasks/attachments/{$tenantId}/{$task->id}", 'local'),
                'file_name'   => $file->getClientOriginalName(),
                'file_size'   => $file->getSize(),
                'mime_type'   => $file->getClientMimeType(),
                'uploaded_by' => $userId,
                'author_kind' => $author['kind'] ?? 'user',
                'author_id'   => $author['id'] ?? $userId,
                'author_name' => $author['name'] ?? null,
            ]);
        }

        // Who the notifications will say this came from. A non-User author has
        // only the name it supplied -- there is nothing to look up.
        $author = $author['name'] ?? ($userId ? (User::find($userId)?->name ?? 'Someone') : 'Someone');
        $excerpt = Str::limit(trim(strip_tags($content)), 120) ?: 'shared a file';

        // @mentioned people are told they were named; everyone else watching gets
        // the quieter "new comment". Nobody gets both.
        $mentioned = $this->mentionedUserIds($content, $tenantId, $userId);
        foreach ($mentioned as $uid) {
            $this->notifications->notify(
                $uid, $tenantId, 'task.mentioned',
                "{$author} mentioned you on: {$task->name}",
                $excerpt, "/app/tasks/{$task->id}", $userId,
            );
        }

        $commented = array_values(array_diff($this->watcherIds($task, $userId), $mentioned));
        foreach ($commented as $uid) {
            $this->notifications->notify(
                $uid, $tenantId, 'task.commented',
                "{$author} commented on: {$task->name}",
                $excerpt, "/app/tasks/{$task->id}", $userId,
            );
        }

        // Email leg. Being named is louder than being copied, so the two groups
        // get different subject lines — and nobody gets both.
        if ($mentioned) {
            $this->notifier->activity($task, $mentioned, 'task.mentioned',
                "{$author} mentioned you on: {$task->name}", $excerpt, $userId);
        }
        if ($commented) {
            $this->notifier->activity($task, $commented, 'task.commented',
                "{$author} commented on: {$task->name}", $excerpt, $userId);
        }

        return $comment->load('user:id,name', 'attachments');
    }

    /**
     * The same thread, written to by a vendor that is not a User.
     *
     * A thin, deliberately named front door onto addComment() so the portal
     * controllers do not each hand-assemble an author array -- and so anyone
     * reading the portal code can see at a glance that a vendor comment lands in
     * the SAME task_comments thread the admin reads, not a parallel one.
     */
    public function addVendorComment(int $taskId, string $content, int $tenantId, string $kind, int $vendorId, string $vendorName, array $files = []): TaskComment
    {
        return $this->addComment($taskId, $content, $tenantId, null, $files, [
            'kind' => $kind,
            'id'   => $vendorId,
            'name' => $vendorName,
        ]);
    }

    /**
     * Resolve "@Name Surname" mentions to staff ids. Names are matched longest-first
     * so "@Anna Marie" doesn't get claimed by a user called "Anna".
     */
    /**
     * The shortest name that may be matched from free text.
     *
     * Names are matched as substrings, so a two-letter name turns every comment
     * containing "@Dr..." — "@Drive the update over" — into a notification for
     * whoever is called Dr. Anyone with a name this short is reachable through
     * the picker, which carries an id and needs no guessing.
     */
    private const MIN_LOOSE_MENTION = 4;

    /**
     * Who was @mentioned in this comment.
     *
     * Two ways in, and the order matters.
     *
     * The picker inserts a marker carrying the person's id
     * (`<span data-mention="12">@Priya Sharma</span>`), which is exact: it
     * survives a rename, a middle name, a nickname, and cannot match the wrong
     * person. That is now the primary path.
     *
     * The fallback reads plain "@Name" text, for a comment typed without the
     * picker, and it used to be the ONLY path — which is why mentions barely
     * worked. It required the person's full name, character for character:
     * "@Priya" reached nobody, and "@Priya Sharma" reached nobody either
     * whenever the editor put a non-breaking space between the words, which a
     * browser does routinely. Both are handled here, and the loose match is
     * bounded so short names stop matching the insides of ordinary words.
     */
    private function mentionedUserIds(string $content, int $tenantId, ?int $actorId): array
    {
        $hits = [];

        // 1. Explicit markers from the picker — an id, not a guess.
        if (preg_match_all('~data-mention=["\'](\d{1,12})["\']~i', $content, $m)) {
            $hits = array_map('intval', $m[1]);
        }

        if (! str_contains($content, '@')) {
            return $this->keepMentionable($hits, $tenantId, $actorId);
        }

        // 2. Free text. &nbsp; (and its entity) read as ordinary spaces, and runs
        //    of whitespace collapse, so "@Priya&nbsp;Sharma" is "@Priya Sharma".
        $text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('~[\x{00A0}\s]+~u', ' ', $text);

        $staff = User::where('tenant_id', $tenantId)
            ->whereNotIn('role', self::EXTERNAL_ROLES)
            // Guarded: a null actor is a non-User author, and `id != NULL` is
            // never true, which would hand back an empty roster.
            ->when($actorId, fn ($q) => $q->where('id', '!=', $actorId))
            ->get(['id', 'name'])
            // Longest first, so "@Priya Sharma" is credited to Priya Sharma and
            // not to a colleague who happens to be called Priya.
            ->sortByDesc(fn ($u) => mb_strlen((string) $u->name));

        foreach ($staff as $u) {
            $name = trim((string) $u->name);
            if ($name === '' || in_array((int) $u->id, $hits, true)) {
                continue;
            }

            // The whole name, then the first name on its own — people type what
            // they call each other, which is almost never the full record.
            foreach ($this->mentionForms($name) as $form) {
                if (mb_strlen($form) < self::MIN_LOOSE_MENTION) {
                    continue;
                }
                // Ends on a word boundary, so "@Ann" does not match "@Annabel".
                if (preg_match('~@'.preg_quote($form, '~').'\b~iu', $text)) {
                    $hits[] = (int) $u->id;
                    break;
                }
            }
        }

        return $this->keepMentionable($hits, $tenantId, $actorId);
    }

    /** "Priya Sharma" is written as itself, or as "Priya". */
    private function mentionForms(string $name): array
    {
        $first = explode(' ', $name)[0];

        return $first !== $name ? [$name, $first] : [$name];
    }

    /**
     * Only real, internal colleagues — and never the author.
     *
     * The marker in the HTML is whatever reached the server, so an id in it is
     * a claim, not a fact: it is checked against the same roster the loose match
     * uses before anybody is notified.
     */
    private function keepMentionable(array $ids, int $tenantId, ?int $actorId): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (! $ids) {
            return [];
        }

        return User::where('tenant_id', $tenantId)
            ->whereNotIn('role', self::EXTERNAL_ROLES)
            ->when($actorId, fn ($q) => $q->where('id', '!=', $actorId))
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /* ── Timers ─────────────────────────────────────────────────── */

    public function startTimer(int $taskId, ?string $note, int $tenantId, int $userId): TaskTimer
    {
        $task = $this->find($taskId, $tenantId);

        $running = $task->timers()->where('user_id', $userId)->whereNull('end_time')->exists();
        if ($running) {
            throw new BusinessException('You already have a running timer on this task.', 422);
        }

        return $task->timers()->create([
            'tenant_id'   => $tenantId,
            'user_id'     => $userId,
            'start_time'  => now(),
            'hourly_rate' => $task->hourly_rate ?? 0,
            'note'        => $note,
        ]);
    }

    public function stopTimer(int $taskId, int $tenantId, int $userId): TaskTimer
    {
        $task = $this->find($taskId, $tenantId);
        $timer = $task->timers()->where('user_id', $userId)->whereNull('end_time')->latest('start_time')->first();
        if (! $timer) {
            throw new BusinessException('No running timer to stop.', 422);
        }
        $timer->update(['end_time' => now()]);

        return $timer->fresh();
    }

    public function totalTime(int $taskId, int $tenantId, ?int $userId = null): array
    {
        $task = $this->find($taskId, $tenantId);
        $timers = $task->timers()->get();
        $seconds = $timers->sum(fn (TaskTimer $t) => $t->durationSeconds());
        // The caller's own logged time — so the Task Info panel can show "your"
        // vs "total" logged time, like the reference task view.
        $mySeconds = $userId
            ? $timers->where('user_id', $userId)->sum(fn (TaskTimer $t) => $t->durationSeconds())
            : 0;

        return [
            'task_id'       => $task->id,
            'total_seconds' => $seconds,
            'total_hours'   => round($seconds / 3600, 2),
            'my_seconds'    => $mySeconds,
            'my_hours'      => round($mySeconds / 3600, 2),
        ];
    }

    /* ── Attachments ────────────────────────────────────────────── */

    public function listFiles(int $taskId, int $tenantId): Collection
    {
        // Only task-level files here — a comment's attachments render under the
        // comment, not in the Files card.
        return $this->find($taskId, $tenantId)->files()->whereNull('comment_id')->with('uploader:id,name')->get();
    }

    /** $files: [['file_path','file_name','file_size','mime_type'], ...] already on disk. */
    public function addFiles(int $taskId, array $files, int $tenantId, int $userId): SupportCollection
    {
        $task = $this->find($taskId, $tenantId);

        $created = collect($files)->map(fn (array $f) => $task->files()->create([
            'tenant_id'   => $tenantId,
            'file_path'   => $f['file_path'],
            'file_name'   => $f['file_name'],
            'file_size'   => $f['file_size'] ?? 0,
            'mime_type'   => $f['mime_type'] ?? null,
            'uploaded_by' => $userId,
        ]));

        $author = User::find($userId)?->name ?? 'Someone';
        $noun = $created->count() === 1 ? 'a file' : "{$created->count()} files";
        foreach ($this->watcherIds($task, $userId) as $uid) {
            $this->notifications->notify(
                $uid, $tenantId, 'task.commented',
                "{$author} attached {$noun} to: {$task->name}",
                $created->pluck('file_name')->implode(', '),
                "/app/tasks/{$task->id}", $userId,
            );
        }

        return $created->map->load('uploader:id,name');
    }

    public function findFile(int $fileId, int $taskId, int $tenantId): TaskFile
    {
        $file = TaskFile::forTenant($tenantId)->where('task_id', $taskId)->find($fileId);
        if (! $file) {
            throw new BusinessException('File not found.', 404);
        }

        return $file;
    }

    /** Removes the row and the file on disk; a missing disk file is not an error. */
    public function deleteFile(int $fileId, int $taskId, int $tenantId): void
    {
        $file = $this->findFile($fileId, $taskId, $tenantId);
        $path = $file->file_path;
        $file->delete();

        try {
            Storage::disk('local')->delete($path);
        } catch (\Throwable $e) {
            Log::warning("Task file delete failed ({$path}): {$e->getMessage()}");
        }
    }

    /* ── Reminders ──────────────────────────────────────────────── */

    public function listReminders(int $taskId, int $tenantId): Collection
    {
        return $this->find($taskId, $tenantId)->reminders()->with('user:id,name')->get();
    }

    public function addReminder(int $taskId, array $data, int $tenantId, int $userId): TaskReminder
    {
        $task = $this->find($taskId, $tenantId);

        $target = (int) ($data['user_id'] ?? $userId);
        $staff = User::where('tenant_id', $tenantId)->whereNotIn('role', self::EXTERNAL_ROLES)->find($target);
        if (! $staff) {
            throw new BusinessException('That person cannot be reminded about this task.', 422);
        }

        return $task->reminders()->create([
            'tenant_id'   => $tenantId,
            'user_id'     => $target,
            'created_by'  => $userId,
            'description' => $data['description'] ?? null,
            'remind_at'   => Carbon::parse($data['remind_at']),
        ])->load('user:id,name');
    }

    public function deleteReminder(int $reminderId, int $taskId, int $tenantId): void
    {
        $reminder = TaskReminder::forTenant($tenantId)->where('task_id', $taskId)->find($reminderId);
        if (! $reminder) {
            throw new BusinessException('Reminder not found.', 404);
        }
        $reminder->delete();
    }

    /* ── Checklist templates ────────────────────────────────────── */

    public function listTemplates(int $tenantId): Collection
    {
        return TaskChecklistTemplate::forTenant($tenantId)->orderBy('name')->get();
    }

    public function createTemplate(array $data, int $tenantId, int $userId): TaskChecklistTemplate
    {
        return TaskChecklistTemplate::create([
            'tenant_id'  => $tenantId,
            'name'       => $data['name'],
            'items'      => $this->cleanItems($data['items'] ?? []),
            'created_by' => $userId,
        ]);
    }

    public function deleteTemplate(int $templateId, int $tenantId): void
    {
        $tpl = TaskChecklistTemplate::forTenant($tenantId)->find($templateId);
        if (! $tpl) {
            throw new BusinessException('Template not found.', 404);
        }
        $tpl->delete();
    }

    /** Save a task's current checklist as a reusable template. */
    public function saveChecklistAsTemplate(int $taskId, string $name, int $tenantId, int $userId): TaskChecklistTemplate
    {
        $items = $this->find($taskId, $tenantId)->checklistItems()->pluck('description')->all();
        if (! $items) {
            throw new BusinessException('This task has no checklist to save.', 422);
        }

        return $this->createTemplate(['name' => $name, 'items' => $items], $tenantId, $userId);
    }

    /** Append a template's items to a task's checklist (never replaces). */
    public function applyTemplate(int $taskId, int $templateId, int $tenantId): Collection
    {
        $task = $this->find($taskId, $tenantId);
        $tpl = TaskChecklistTemplate::forTenant($tenantId)->find($templateId);
        if (! $tpl) {
            throw new BusinessException('Template not found.', 404);
        }

        $order = (int) $task->checklistItems()->max('order');
        foreach ($this->cleanItems($tpl->items ?? []) as $desc) {
            $task->checklistItems()->create(['tenant_id' => $tenantId, 'description' => $desc, 'order' => ++$order]);
        }

        return $task->checklistItems()->get();
    }

    private function cleanItems(array $items): array
    {
        return collect($items)
            ->map(fn ($i) => trim((string) $i))
            ->filter()
            ->map(fn ($i) => Str::limit($i, 500, ''))
            ->values()
            ->all();
    }

    /* ── Copy / clone ───────────────────────────────────────────── */

    /**
     * Clone a task. Assignees/followers/checklist are opt-in (spec); comments and
     * timers are never copied — they are history, and history belongs to the
     * original. The copy always starts fresh: not billed, no date_finished.
     */
    public function copy(int $taskId, array $opts, int $tenantId, int $userId): Task
    {
        $src = $this->show($taskId, $tenantId);

        $copy = $this->tasks->create([
            'tenant_id'         => $tenantId,
            'name'              => $opts['name'] ?? $src->name.' (copy)',
            'description'       => $src->description,
            'priority'          => $src->priority,
            'status'            => $opts['status'] ?? 'not_started',
            'start_date'        => $opts['start_date'] ?? now()->toDateString(),
            'due_date'          => $opts['due_date'] ?? null,
            'rel_type'          => $src->rel_type,
            'rel_id'            => $src->rel_id,
            'milestone_id'      => $src->milestone_id,
            'billable'          => $src->billable,
            'billed'            => false,
            'hourly_rate'       => $src->hourly_rate,
            'is_public'         => $src->is_public,
            'visible_to_client' => $src->visible_to_client,
            'created_by'        => $userId,
        ]);

        if (! empty($opts['copy_checklist'])) {
            foreach ($src->checklistItems as $item) {
                $copy->checklistItems()->create([
                    'tenant_id'   => $tenantId,
                    'description' => $item->description,
                    'order'       => $item->order,
                ]);
            }
        }
        if (! empty($opts['copy_assignees'])) {
            $this->syncAssignees($copy->id, $src->assignees->pluck('user_id')->all(), $tenantId, $userId);
        }
        if (! empty($opts['copy_followers'])) {
            $this->syncFollowers($copy->id, $src->followers->pluck('user_id')->all(), $tenantId, $userId);
        }

        // Tags always copy — they describe what the task IS, not its history.
        $this->tags->sync('task', $copy->id, $this->tags->tagsFor('task', $src->id, $tenantId)->pluck('name')->all(), $tenantId);

        return $this->decorateRelation($copy->fresh('creator'), $tenantId);
    }

    /* ── Recurrence ─────────────────────────────────────────────── */

    /**
     * Spawn the next copy of every recurring template that is due, and fire
     * due-date + reminder notifications. Driven by the scheduler (tasks:run-recurring),
     * so it must be idempotent — running it twice in a day must not double-create.
     *
     * Returns a per-section tally for the command's output.
     */
    public function runScheduled(?Carbon $now = null): array
    {
        $now = $now ?? now();

        return [
            'recurring' => $this->spawnDueRecurring($now),
            'reminders' => $this->fireDueReminders($now),
            'deadlines' => $this->fireDueDeadlines($now),
        ];
    }

    private function spawnDueRecurring(Carbon $now): int
    {
        $made = 0;

        Task::query()
            ->where('recurring', true)
            ->where('repeat_every', '>', 0)
            ->whereNotNull('recurring_type')
            // cycles = 0 means run forever; otherwise stop once we've made that many.
            ->where(fn ($q) => $q->where('cycles', 0)->orWhereColumn('total_cycles', '<', 'cycles'))
            ->with('assignees:id,task_id,user_id')
            ->chunkById(100, function (Collection $templates) use ($now, &$made) {
                foreach ($templates as $tpl) {
                    $next = $this->nextOccurrence($tpl);
                    if (! $next || $next->gt($now)) {
                        continue;
                    }

                    DB::transaction(function () use ($tpl, $next, &$made) {
                        $copy = $this->copy($tpl->id, [
                            'name'           => $tpl->name,
                            'start_date'     => $next->toDateString(),
                            'due_date'       => $this->shiftDue($tpl, $next),
                            'copy_checklist' => true,
                            'copy_assignees' => true,
                            'copy_followers' => true,
                        ], $tpl->tenant_id, $tpl->created_by);

                        // The copy is a plain task — it must never recur itself.
                        $copy->forceFill([
                            'recurring'         => false,
                            'recurring_type'    => null,
                            'repeat_every'      => 0,
                            'cycles'            => 0,
                            'is_recurring_from' => $tpl->id,
                        ])->save();

                        $tpl->forceFill([
                            'total_cycles'        => $tpl->total_cycles + 1,
                            'last_recurring_date' => $next->toDateString(),
                        ])->save();

                        $made++;
                    });
                }
            });

        return $made;
    }

    /** When the next copy is due: last spawn (or start date) + one interval. */
    private function nextOccurrence(Task $tpl): ?Carbon
    {
        $from = $tpl->last_recurring_date
            ? Carbon::parse($tpl->last_recurring_date)
            : ($tpl->start_date ? Carbon::parse($tpl->start_date) : null);

        if (! $from) {
            return null;
        }

        // The first cycle is the template's own start date — don't skip it.
        if (! $tpl->last_recurring_date) {
            return $from->startOfDay();
        }

        $n = max(1, (int) $tpl->repeat_every);

        return match ($tpl->recurring_type) {
            'day'   => $from->copy()->addDays($n)->startOfDay(),
            'week'  => $from->copy()->addWeeks($n)->startOfDay(),
            'month' => $from->copy()->addMonthsNoOverflow($n)->startOfDay(),
            'year'  => $from->copy()->addYears($n)->startOfDay(),
            default => null,
        };
    }

    /** Keep the template's start→due gap on each copy. */
    private function shiftDue(Task $tpl, Carbon $start): ?string
    {
        if (! $tpl->due_date || ! $tpl->start_date) {
            return null;
        }
        $gap = Carbon::parse($tpl->start_date)->diffInDays(Carbon::parse($tpl->due_date), false);

        return $gap >= 0 ? $start->copy()->addDays($gap)->toDateString() : null;
    }

    private function fireDueReminders(Carbon $now): int
    {
        $sent = 0;

        TaskReminder::due()->with('task:id,name')->chunkById(200, function (Collection $rows) use (&$sent) {
            foreach ($rows as $r) {
                if (! $r->task) {
                    $r->delete();
                    continue;
                }
                $this->notifications->notify(
                    $r->user_id, $r->tenant_id, 'task.reminder',
                    "Reminder: {$r->task->name}",
                    $r->description, "/app/tasks/{$r->task_id}",
                );
                // Stamped regardless of delivery — notify() swallow-logs, and a
                // failed notification must not re-fire forever.
                $r->forceFill(['notified_at' => now()])->save();
                $sent++;
            }
        });

        return $sent;
    }

    /** One "due soon / overdue" nudge per task, to assignees. */
    private function fireDueDeadlines(Carbon $now): int
    {
        $sent = 0;
        $horizon = $now->copy()->addDay()->endOfDay();

        Task::query()
            ->where('deadline_notified', false)
            ->whereNotNull('due_date')
            ->where('status', '!=', 'complete')
            ->where('due_date', '<=', $horizon)
            ->with('assignees:id,task_id,user_id')
            ->chunkById(100, function (Collection $tasks) use ($now, &$sent) {
                foreach ($tasks as $task) {
                    $overdue = Carbon::parse($task->due_date)->endOfDay()->lt($now);
                    // A subtask carries its OWN deadline, so it gets its own
                    // notice — the parent's date says nothing about this one.
                    $watchers = $this->watcherIds($task, null);
                    foreach ($watchers as $uid) {
                        $this->notifications->notify(
                            $uid, $task->tenant_id,
                            $overdue ? 'task.overdue' : 'task.due_soon',
                            ($overdue ? 'Overdue: ' : 'Due soon: ').$task->name,
                            'Due '.Carbon::parse($task->due_date)->format('M j'),
                            "/app/tasks/{$task->id}",
                        );
                        $sent++;
                    }
                    $this->notifier->due($task, $watchers, $overdue);
                    // Stamped regardless of delivery — notify() and the mailer
                    // both swallow-log, and re-nagging daily because SMTP
                    // hiccupped once is worse than missing one notice.
                    $task->forceFill(['deadline_notified' => true])->save();
                }
            });

        return $sent;
    }

    /* ── Stats (KPI cards) ──────────────────────────────────────── */

    /**
     * Counts for the list KPI cards. One aggregate query per card rather than
     * pulling every task down and counting in PHP — this runs on every list view.
     */
    public function stats(int $tenantId, int $userId, bool $isAdmin = true): array
    {
        // A staff member's cards only count tasks they're allowed to see.
        $base = fn () => $isAdmin
            ? Task::forTenant($tenantId)
            : $this->scopeVisible(Task::forTenant($tenantId), $tenantId, $userId);
        $today = now()->toDateString();

        $mine = $this->tableExists('task_assignees')
            ? (clone $base())->whereHas('assignees', fn ($q) => $q->where('user_id', $userId))
                ->where('status', '!=', 'complete')->count()
            : 0;

        return [
            'active'    => (clone $base())->where('status', '!=', 'complete')->count(),
            'completed' => (clone $base())->where('status', 'complete')->count(),
            // Overdue excludes complete — a task finished late isn't still overdue.
            'overdue'   => (clone $base())->where('status', '!=', 'complete')
                ->whereNotNull('due_date')->whereDate('due_date', '<', $today)->count(),
            'today'     => (clone $base())->where('status', '!=', 'complete')
                ->whereDate('due_date', $today)->count(),
            'mine'      => $mine,
        ];
    }

    /* ── Bulk actions ───────────────────────────────────────────── */

    /**
     * Apply one action to many tasks. A real endpoint rather than the frontend
     * firing N parallel single-item requests (which is what Helpdesk's grid does).
     *
     * Notifications still fire per task — a bulk reassign that silently moved
     * work onto someone would be the same bug this module started with.
     */
    public function bulkAction(array $data, int $tenantId, int $userId, bool $isAdmin = true): int
    {
        $ids = array_values(array_unique(array_map('intval', $data['task_ids'])));
        // A non-admin can only bulk-act on tasks they can actually see —
        // silently drop the rest rather than touching another team's work.
        $q = Task::forTenant($tenantId)->whereIn('id', $ids);
        if (! $isAdmin) {
            $q = $this->scopeVisible($q, $tenantId, $userId);
        }
        $tasks = $q->get();
        $action = $data['action'];
        $value = $data['value'] ?? null;

        // Validate the value ONCE up front rather than per row, so a bad value
        // fails the whole request instead of half-applying.
        if ($action === 'status' && ! in_array($value, $this->statuses->keys('task', $tenantId), true)) {
            throw new BusinessException('Unknown status.', 422);
        }
        if ($action === 'priority' && ! in_array($value, ['low', 'medium', 'high', 'urgent'], true)) {
            throw new BusinessException('Unknown priority.', 422);
        }
        // 'assign' takes one id or several. It took a single id before, and the
        // bulk bar still sends one when only one is picked, so both shapes have
        // to mean the same thing — see userIdList().
        $assignIds = [];
        if ($action === 'assign') {
            $assignIds = $this->userIdList(is_array($value) ? $value : (int) $value);
            if (! $assignIds) {
                throw new BusinessException('Choose at least one person to assign.', 422);
            }

            $ok = User::where('tenant_id', $tenantId)->whereIn('id', $assignIds)
                ->whereNotIn('role', self::NON_ASSIGNABLE_ROLES)->count();
            if ($ok !== count($assignIds)) {
                throw new BusinessException('One or more selected people cannot be assigned in this workspace.', 422);
            }
        }

        $count = 0;
        foreach ($tasks as $task) {
            match ($action) {
                'delete'   => $task->delete(),
                'status'   => $this->changeStatus($task->id, $value, $tenantId, $userId),
                'priority' => $task->update(['priority' => $value]),
                // Adds to the existing set rather than replacing it — "assign these
                // 10 tasks to Priya" should not unassign everyone else.
                // Adds to whoever is already on each task rather than
                // replacing them — bulk assign is "also put these people on
                // it", not "these people and nobody else".
                'assign'   => $this->syncAssignees(
                    $task->id,
                    $task->assignees()->pluck('user_id')->merge($assignIds)->unique()->all(),
                    $tenantId, $userId,
                ),
                default    => null,
            };
            $count++;
        }

        return $count;
    }

    /* ── Billable report ────────────────────────────────────────── */

    public function billable(int $tenantId, array $filters = []): Collection
    {
        return Task::forTenant($tenantId)
            ->where('billable', true)->where('billed', false)
            ->when(! empty($filters['rel_id']), fn ($q) => $q->where('rel_id', $filters['rel_id']))
            ->when(! empty($filters['customer_id']), fn ($q) => $q->where('rel_type', 'customer')->where('rel_id', $filters['customer_id']))
            ->orderBy('name')->get();
    }

    /* ── Internals ──────────────────────────────────────────────── */

    public function find(int $id, int $tenantId): Task
    {
        $task = $this->tasks->findForTenant($id, $tenantId);
        if (! $task) {
            throw new BusinessException('Task not found.', 404);
        }

        return $task;
    }

    /** Attach resolved customer data for customer-linked tasks (no cross-module join). */
    private function decorateRelation(Task $task, int $tenantId): Task
    {
        if ($task->rel_id) {
            // Through the SAME resolver the list uses. These were two code paths
            // that answered the same question differently — the single-task view
            // resolved a customer through the directory service and everything
            // else through resolveRelLabel(), so the label on the board and the
            // label in the modal could disagree, and only one of them knew when
            // the target had been deleted.
            [$label, $url, $found] = $this->labelsFor($task->rel_type, [(int) $task->rel_id], $tenantId)[(int) $task->rel_id]
                ?? [null, null, false];

            $task->setAttribute('rel_label', $label);
            $task->setAttribute('rel_url', $url);
            $task->setAttribute('rel_missing', $label !== null && ! $found);

            if ($task->rel_type === 'customer') {
                $task->setAttribute('customer', $found
                    ? $this->customers->getCustomer((int) $task->rel_id, $tenantId)
                    : null);
            }
        }

        // Additional "Related To" links (many-per-task), each with a resolved label.
        $task->setAttribute('relations', $this->relationsFor($task, $tenantId));

        return $task;
    }

    /**
     * Batch-decorate a list: every task's link label in a handful of queries,
     * regardless of how many rows there are.
     *
     * This used to batch projects, tickets and vendors and then hand CUSTOMER
     * rows back to the single-row path — which fetched the client, its primary
     * contact, and the task's additional relations, one row at a time. On a
     * board with a dozen customer tasks that was forty extra queries, and it is
     * the bulk of why the list felt slow.
     *
     * Now every rel_type goes through labelsFor(), which is one query per TYPE,
     * and the additional relations are fetched for the whole page at once.
     */
    private function decorateMany(Collection $tasks, int $tenantId): Collection
    {
        // One label map per rel_type present on the page — at most eight queries
        // for any number of rows, and usually two or three.
        $labels = [];
        foreach ($tasks->pluck('rel_type')->filter()->unique() as $type) {
            $ids = $tasks->where('rel_type', $type)->pluck('rel_id')->filter()->all();
            $labels[$type] = $this->labelsFor($type, $ids, $tenantId);
        }

        $relations = $this->relationsForMany($tasks->pluck('id')->all(), $tenantId);

        return $tasks->map(function (Task $task) use ($labels, $relations) {
            $task->setAttribute('relations', $relations[$task->id] ?? []);

            if (! $task->rel_id) {
                return $task;
            }

            [$label, $url, $found] = $labels[$task->rel_type][(int) $task->rel_id] ?? [null, null, false];
            $task->setAttribute('rel_label', $label);
            $task->setAttribute('rel_url', $url);
            // Deleting a vendor, project or ticket does not touch the tasks that
            // point at it, so a dangling link is ordinary and has to be legible
            // rather than silently broken.
            $task->setAttribute('rel_missing', $label !== null && ! $found);

            // A customer link also carries the record itself — kept because
            // consumers outside this module read it, but built from the label
            // map rather than a fresh query per row.
            if ($task->rel_type === 'customer' && $label !== null) {
                $task->setAttribute('customer', [
                    'id' => (int) $task->rel_id, 'name' => $label, 'company' => $label,
                ]);
            }

            return $task;
        });
    }

    /**
     * Name + deep-link for a project/ticket link. Read directly (not via a
     * contract) because both are internal modules — same allowance Helpdesk's
     * TicketAssignmentService takes when it reads Task. Table guards keep this
     * safe if a module isn't installed.
     */
    private function resolveRelLabel(?string $relType, int $relId, int $tenantId): array
    {
        return match ($relType) {
            'project' => $this->tableExists('projects')
                ? [Project::forTenant($tenantId)->whereKey($relId)->value('name') ?? "Project #{$relId}", "/app/projects/{$relId}"]
                : [null, null],
            'ticket' => $this->tableExists('tickets')
                ? [Ticket::forTenant($tenantId)->whereKey($relId)->value('subject') ?? "Ticket #{$relId}", "/app/helpdesk/tickets/{$relId}"]
                : [null, null],
            // Customer + contract links come from the Sales/Customer modules.
            'customer' => $this->tableExists('clients')
                ? [\App\Models\Customer\Client::forTenant($tenantId)->whereKey($relId)->value('company') ?? "Customer #{$relId}", "/app/customers/{$relId}"]
                : [null, null],
            'contract' => $this->tableExists('sales_contracts')
                ? [\App\Models\Sales\SalesContract::forTenant($tenantId)->whereKey($relId)->value('subject') ?? "Contract #{$relId}", "/app/sales/contracts/{$relId}"]
                : [null, null],
            // Vendor links. TPV and Purchase are separate modules with separate
            // tables -- a task relates to one or the other, never a shared "vendor".
            'tpv_vendor' => $this->tableExists('vendors')
                ? [\App\Models\Vendor\Vendor::forTenant($tenantId)->whereKey($relId)->value('company_name') ?? "TPV Vendor #{$relId}", "/app/tpv/view/{$relId}"]
                : [null, null],
            'purchase_vendor' => $this->tableExists('purchase_vendors')
                ? [\App\Models\Purchase\PurchaseVendor::forTenant($tenantId)->whereKey($relId)->value('company_name') ?? "Purchase Vendor #{$relId}", "/app/purchase/vendors/{$relId}"]
                : [null, null],
            // Lead lives in the Sales module; Meeting is the shared Kickoff meeting.
            'lead' => $this->tableExists('leads')
                ? [\App\Models\Sales\Lead::forTenant($tenantId)->whereKey($relId)->value('name') ?? "Lead #{$relId}", "/app/sales/leads/{$relId}"]
                : [null, null],
            'meeting' => $this->tableExists('kickoff_meetings')
                ? [\App\Models\Shared\KickoffMeeting::forTenant($tenantId)->whereKey($relId)->value('title') ?? "Meeting #{$relId}", null]
                : [null, null],
            default => [null, null],
        };
    }

    /**
     * Labels + deep links for MANY records of one rel_type, in one query.
     *
     * resolveRelLabel() answers the same question for a single id, and reading a
     * list through it is the N+1 this replaces: a board of sixty tasks fired a
     * query per row for its link, and two more per row for a customer link,
     * because decorateMany() batched projects, tickets and vendors but handed
     * customers straight back to the single-row path.
     *
     * The table guard stays — a module that is not installed must not fatal the
     * task list — but it is asked once per TYPE rather than once per row.
     *
     * The third element says whether the record was actually FOUND. A link
     * whose target has been deleted still gets a label — "#41" beside a task is
     * meaningless, "Project #41" at least says what it was pointing at — but the
     * screen has to be able to tell that apart from a live link, or it offers an
     * "open" button that goes to a page with nothing on it.
     *
     * @param  int[]  $ids
     * @return array<int,array{0:?string,1:?string,2:bool}>  id => [label, url, found]
     */
    private function labelsFor(?string $relType, array $ids, int $tenantId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $relType || ! $ids) {
            return [];
        }

        // table, display column, and how to build the deep link.
        $spec = match ($relType) {
            'project'         => ['projects', Project::class, 'name', fn ($id) => "/app/projects/{$id}", 'Project'],
            'ticket'          => ['tickets', Ticket::class, 'subject', fn ($id) => "/app/helpdesk/tickets/{$id}", 'Ticket'],
            'customer'        => ['clients', \App\Models\Customer\Client::class, 'company', fn ($id) => "/app/customers/{$id}", 'Customer'],
            'contract'        => ['sales_contracts', \App\Models\Sales\SalesContract::class, 'subject', fn ($id) => "/app/sales/contracts/{$id}", 'Contract'],
            // /app/tpv/VIEW/{id}, not /vendors/{id}. The TPV module's vendor
            // LIST is at /app/tpv/vendors and the workspace is at /app/tpv/view
            // — Purchase puts both under /vendors, so the obvious guess is right
            // there and wrong here, and it 404'd on vendors that exist.
            'tpv_vendor'      => ['vendors', \App\Models\Vendor\Vendor::class, 'company_name', fn ($id) => "/app/tpv/view/{$id}", 'TPV Vendor'],
            'purchase_vendor' => ['purchase_vendors', \App\Models\Purchase\PurchaseVendor::class, 'company_name', fn ($id) => "/app/purchase/vendors/{$id}", 'Purchase Vendor'],
            'lead'            => ['leads', \App\Models\Sales\Lead::class, 'name', fn ($id) => "/app/sales/leads/{$id}", 'Lead'],
            // A meeting has no page of its own to link to.
            'meeting'         => ['kickoff_meetings', \App\Models\Shared\KickoffMeeting::class, 'title', fn () => null, 'Meeting'],
            default           => null,
        };

        if (! $spec || ! $this->tableExists($spec[0])) {
            return [];
        }

        [, $model, $column, $url, $noun] = $spec;

        $names = $model::forTenant($tenantId)->whereIn('id', $ids)->pluck($column, 'id');

        $out = [];
        foreach ($ids as $id) {
            $found = isset($names[$id]);
            $out[$id] = [
                $found ? $names[$id] : "{$noun} #{$id}",
                // No deep link to a record that is not there.
                $found ? $url($id) : null,
                $found,
            ];
        }

        return $out;
    }

    /**
     * Schema::hasTable(), asked once per table per request.
     *
     * The task module calls these guards sixteen times across a single list
     * request. On SQLite each is a cheap read of sqlite_master; on MySQL each is
     * a round trip to information_schema, which is where the tens of
     * milliseconds go. The schema cannot change mid-request, so the first answer
     * is the only one worth paying for.
     */
    private function tableExists(string $table): bool
    {
        return $this->tableCache[$table] ??= Schema::hasTable($table);
    }

    private function columnExists(string $table, string $column): bool
    {
        return $this->columnCache["$table.$column"] ??= Schema::hasColumn($table, $column);
    }

    /*
     * Per-instance, not static. A static cache would outlive the request and, in
     * the test suite, carry one test's answer into the next — where
     * RefreshDatabase has rebuilt the schema in between. The service is resolved
     * once or twice per request, which is where all the repetition is anyway.
     */

    /** @var array<string,bool> */
    private array $tableCache = [];

    /** @var array<string,bool> */
    private array $columnCache = [];

    /** rel_types a task may link to (primary link + the additional relations). */
    public const REL_TYPES = ['project', 'ticket', 'customer', 'contract', 'tpv_vendor', 'purchase_vendor', 'lead', 'meeting'];

    /**
     * Replace a task's ADDITIONAL relations with the given list. Each entry is
     * ['rel_type' => …, 'rel_id' => …]. Passing null leaves them untouched; passing
     * [] clears them. Unknown rel_types and the task's own primary link are skipped.
     */
    public function syncRelations(int $taskId, ?array $relations, int $tenantId): void
    {
        if ($relations === null) {
            return;
        }

        $clean = collect($relations)
            ->filter(fn ($r) => is_array($r) && ! empty($r['rel_id']) && in_array($r['rel_type'] ?? null, self::REL_TYPES, true))
            ->map(fn ($r) => ['rel_type' => $r['rel_type'], 'rel_id' => (int) $r['rel_id']])
            ->unique(fn ($r) => $r['rel_type'].':'.$r['rel_id'])
            ->values();

        \App\Models\Task\TaskRelation::where('task_id', $taskId)->delete();

        if ($clean->isEmpty()) {
            return;
        }

        $now = now();
        \App\Models\Task\TaskRelation::insert($clean->map(fn ($r) => [
            'tenant_id'  => $tenantId,
            'task_id'    => $taskId,
            'rel_type'   => $r['rel_type'],
            'rel_id'     => $r['rel_id'],
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    /** The additional relations of a task, each decorated with a label + url. */
    public function relationsFor(Task $task, int $tenantId): array
    {
        return $this->relationsForMany([$task->id], $tenantId)[$task->id] ?? [];
    }

    /**
     * The same, for a whole page of tasks — one query for the links, then one
     * per rel_type for their labels.
     *
     * The single-row version above now goes through this, so there is one
     * implementation of what a decorated relation looks like rather than two
     * that drifted: the old per-row path resolved a customer through the
     * directory service and everything else through resolveRelLabel(), and the
     * two disagreed about the fallback label.
     *
     * @param  int[]  $taskIds
     * @return array<int,array<int,array<string,mixed>>>  task_id => relations
     */
    public function relationsForMany(array $taskIds, int $tenantId): array
    {
        $taskIds = array_values(array_filter(array_map('intval', $taskIds)));
        if (! $taskIds) {
            return [];
        }

        $rows = \App\Models\Task\TaskRelation::whereIn('task_id', $taskIds)->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $labels = [];
        foreach ($rows->pluck('rel_type')->filter()->unique() as $type) {
            $labels[$type] = $this->labelsFor(
                $type, $rows->where('rel_type', $type)->pluck('rel_id')->all(), $tenantId
            );
        }

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r->rel_id;
            [$label, $url, $found] = $labels[$r->rel_type][$id] ?? [null, null, false];
            $out[(int) $r->task_id][] = [
                'rel_type' => $r->rel_type,
                'rel_id'   => $id,
                'label'    => $label ?? "{$r->rel_type} #{$id}",
                'url'      => $url,
                'missing'  => ! $found,
            ];
        }

        return $out;
    }
}
