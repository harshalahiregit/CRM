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
    /** Portal-only roles — never staff, so never assignees/followers (matches Helpdesk). */
    private const EXTERNAL_ROLES = ['client', 'vendor', 'third_party_vendor'];

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
        ?CustomerServiceContract $customers = null,
    ) {
        $this->customers = $customers ?? new MockCustomerService();
    }

    public function list(int $tenantId, array $filters = []): Collection
    {
        $tasks = $this->decorateMany($this->tasks->filtered($tenantId, $filters), $tenantId);

        // Batched — one tag query for the whole page, not one per row.
        $tagMap = $this->tags->tagsForMany('task', $tasks->pluck('id')->all(), $tenantId);

        return $tasks->each(fn (Task $t) => $t->setAttribute('tags', $tagMap[$t->id] ?? []));
    }

    public function show(int $id, int $tenantId): Task
    {
        $task = $this->find($id, $tenantId);
        $task->load([
            'creator:id,name', 'milestone:id,name',
            'assignees.user:id,name,email', 'followers.user:id,name',
            'checklistItems', 'comments.user:id,name', 'timers.user:id,name',
        ]);
        $task->setAttribute('tags', $this->tags->tagsFor('task', $task->id, $tenantId));

        return $this->decorateRelation($task, $tenantId);
    }

    /**
     * Create a task. Auto-status rule (spec): when no status is supplied,
     * status = in_progress if today >= start_date, else not_started.
     */
    public function create(array $data, int $tenantId, int $userId): Task
    {
        $relType = $data['rel_type'] ?? 'standalone';

        // Customer link resolves through the contract (same mock as Helpdesk).
        if ($relType === 'customer' && ! empty($data['rel_id'])
            && ! $this->customers->exists((int) $data['rel_id'], $tenantId)) {
            throw new BusinessException('The selected customer does not exist.', 422);
        }

        if (empty($data['status'])) {
            $start = Carbon::parse($data['start_date'])->startOfDay();
            $data['status'] = now()->startOfDay()->gte($start) ? 'in_progress' : 'not_started';
        }

        // People and tags are child rows, not columns — pull them out before the insert.
        $assignees = $data['assignee_ids'] ?? [];
        $followers = $data['follower_ids'] ?? [];
        $tags = $data['tags'] ?? null;
        unset($data['assignee_ids'], $data['follower_ids'], $data['tags']);

        $task = $this->tasks->create([...$data, 'tenant_id' => $tenantId, 'created_by' => $userId]);

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

        $task = $this->decorateRelation($task->fresh('creator'), $tenantId);
        $task->setAttribute('tags', $this->tags->tagsFor('task', $task->id, $tenantId));

        return $task;
    }

    public function update(int $id, array $data, int $tenantId): Task
    {
        $task = $this->find($id, $tenantId);

        $tags = $data['tags'] ?? null;
        unset($data['tags']);

        $task->fill($data);

        // Moving the deadline re-arms the "due soon" nudge — otherwise a task
        // pushed out by a month would never warn again.
        if ($task->isDirty('due_date')) {
            $task->deadline_notified = false;
        }

        $task->save();

        if ($tags !== null) {
            $this->tags->sync('task', $task->id, $tags, $tenantId);
        }

        $fresh = $this->decorateRelation($task->fresh('creator'), $tenantId);
        $fresh->setAttribute('tags', $this->tags->tagsFor('task', $task->id, $tenantId));

        return $fresh;
    }

    /** Change status; stamp date_finished on the transition into "complete". */
    public function changeStatus(int $id, string $status, int $tenantId, ?int $actorId = null): Task
    {
        $task = $this->find($id, $tenantId);
        $from = $task->status;
        $task->status = $status;
        $task->date_finished = $status === 'complete' ? now() : null;
        $task->save();

        if ($from !== $status) {
            $label = self::STATUS_LABELS[$status] ?? $status;
            foreach ($this->watcherIds($task, $actorId) as $uid) {
                $this->notifications->notify(
                    $uid, $tenantId, 'task.status_changed',
                    "Task {$label}: {$task->name}",
                    (self::STATUS_LABELS[$from] ?? $from)." → {$label}",
                    "/app/tasks/{$task->id}",
                    $actorId,
                );
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

    public function delete(int $id, int $tenantId): void
    {
        $this->find($id, $tenantId)->delete();
    }

    /**
     * Persist a kanban column's order after a drag. Rewrites kanban_order to the
     * given sequence and moves any card that changed column into $status.
     * Ids not in this tenant are ignored rather than throwing — a stale board
     * shouldn't 500 the drop.
     */
    public function reorder(array $orderedIds, string $status, int $tenantId, ?int $actorId = null): int
    {
        $ids = array_values(array_unique(array_map('intval', $orderedIds)));
        if (! $ids) {
            return 0;
        }

        $tasks = Task::forTenant($tenantId)->whereIn('id', $ids)->get()->keyBy('id');
        $moved = [];

        DB::transaction(function () use ($ids, $status, $tasks, &$moved) {
            foreach ($ids as $i => $id) {
                $task = $tasks->get($id);
                if (! $task) {
                    continue;
                }
                if ($task->status !== $status) {
                    $moved[] = $task->id;
                    $task->status = $status;
                    $task->date_finished = $status === 'complete' ? now() : null;
                }
                $task->kanban_order = $i;
                $task->save();
            }
        });

        // A drag across columns is a status change — notify like one.
        foreach ($moved as $id) {
            $task = $tasks->get($id);
            $label = self::STATUS_LABELS[$status] ?? $status;
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
            ->whereNotIn('role', self::EXTERNAL_ROLES)
            ->pluck('id')->all();
        if (count($valid) !== count($userIds)) {
            throw new BusinessException('One or more users are not staff in this workspace.', 422);
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
    }

    /* ── Checklist ──────────────────────────────────────────────── */

    public function listChecklist(int $taskId, int $tenantId): Collection
    {
        return $this->find($taskId, $tenantId)->checklistItems()->get();
    }

    public function addChecklistItem(int $taskId, string $description, int $tenantId): TaskChecklistItem
    {
        $task = $this->find($taskId, $tenantId);
        $order = ((int) $task->checklistItems()->max('order')) + 1;

        return $task->checklistItems()->create(['tenant_id' => $tenantId, 'description' => $description, 'order' => $order])->fresh();
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
        return $this->find($taskId, $tenantId)->comments()->with('user:id,name')->get();
    }

    public function addComment(int $taskId, string $content, int $tenantId, int $userId): TaskComment
    {
        $task = $this->find($taskId, $tenantId);
        $comment = $task->comments()->create([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'content' => $content,
        ]);

        $author = User::find($userId)?->name ?? 'Someone';
        $excerpt = Str::limit(trim(strip_tags($content)), 120);

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

        foreach (array_diff($this->watcherIds($task, $userId), $mentioned) as $uid) {
            $this->notifications->notify(
                $uid, $tenantId, 'task.commented',
                "{$author} commented on: {$task->name}",
                $excerpt, "/app/tasks/{$task->id}", $userId,
            );
        }

        return $comment->load('user:id,name');
    }

    /**
     * Resolve "@Name Surname" mentions to staff ids. Names are matched longest-first
     * so "@Anna Marie" doesn't get claimed by a user called "Anna".
     */
    private function mentionedUserIds(string $content, int $tenantId, int $actorId): array
    {
        if (! str_contains($content, '@')) {
            return [];
        }

        $text = strip_tags($content);
        $staff = User::where('tenant_id', $tenantId)
            ->whereNotIn('role', self::EXTERNAL_ROLES)
            ->where('id', '!=', $actorId)
            ->get(['id', 'name'])
            ->sortByDesc(fn ($u) => mb_strlen((string) $u->name));

        $hits = [];
        foreach ($staff as $u) {
            if ($u->name && stripos($text, '@'.$u->name) !== false) {
                $hits[] = (int) $u->id;
            }
        }

        return array_values(array_unique($hits));
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

    public function totalTime(int $taskId, int $tenantId): array
    {
        $task = $this->find($taskId, $tenantId);
        $seconds = $task->timers()->get()->sum(fn (TaskTimer $t) => $t->durationSeconds());

        return ['task_id' => $task->id, 'total_seconds' => $seconds, 'total_hours' => round($seconds / 3600, 2)];
    }

    /* ── Attachments ────────────────────────────────────────────── */

    public function listFiles(int $taskId, int $tenantId): Collection
    {
        return $this->find($taskId, $tenantId)->files()->with('uploader:id,name')->get();
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
                    foreach ($this->watcherIds($task, null) as $uid) {
                        $this->notifications->notify(
                            $uid, $task->tenant_id,
                            $overdue ? 'task.overdue' : 'task.due_soon',
                            ($overdue ? 'Overdue: ' : 'Due soon: ').$task->name,
                            'Due '.Carbon::parse($task->due_date)->format('M j'),
                            "/app/tasks/{$task->id}",
                        );
                        $sent++;
                    }
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
    public function stats(int $tenantId, int $userId): array
    {
        $base = fn () => Task::forTenant($tenantId);
        $today = now()->toDateString();

        $mine = Schema::hasTable('task_assignees')
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
    public function bulkAction(array $data, int $tenantId, int $userId): int
    {
        $ids = array_values(array_unique(array_map('intval', $data['task_ids'])));
        $tasks = Task::forTenant($tenantId)->whereIn('id', $ids)->get();
        $action = $data['action'];
        $value = $data['value'] ?? null;

        // Validate the value ONCE up front rather than per row, so a bad value
        // fails the whole request instead of half-applying.
        if ($action === 'status' && ! array_key_exists($value, self::STATUS_LABELS)) {
            throw new BusinessException('Unknown status.', 422);
        }
        if ($action === 'priority' && ! in_array($value, ['low', 'medium', 'high', 'urgent'], true)) {
            throw new BusinessException('Unknown priority.', 422);
        }
        if ($action === 'assign') {
            $ok = User::where('tenant_id', $tenantId)->whereKey((int) $value)
                ->whereNotIn('role', self::EXTERNAL_ROLES)->exists();
            if (! $ok) {
                throw new BusinessException('That person is not staff in this workspace.', 422);
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
                'assign'   => $this->syncAssignees(
                    $task->id,
                    $task->assignees()->pluck('user_id')->push((int) $value)->unique()->all(),
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
        if ($task->rel_type === 'customer' && $task->rel_id) {
            $customer = $this->customers->getCustomer((int) $task->rel_id, $tenantId);
            $task->setAttribute('customer', $customer);
            $task->setAttribute('rel_label', $customer['name'] ?? $customer['company'] ?? "Customer #{$task->rel_id}");
            $task->setAttribute('rel_url', null);
        } elseif ($task->rel_id) {
            [$label, $url] = $this->resolveRelLabel($task->rel_type, (int) $task->rel_id, $tenantId);
            $task->setAttribute('rel_label', $label);
            $task->setAttribute('rel_url', $url);
        }

        return $task;
    }

    /**
     * Batch-decorate a list. One query per rel_type instead of one per row —
     * decorating in a map() is an N+1 the moment a real customer service is bound.
     */
    private function decorateMany(Collection $tasks, int $tenantId): Collection
    {
        $ids = fn (string $type) => $tasks->where('rel_type', $type)->pluck('rel_id')->filter()->unique()->all();

        $projects = ($p = $ids('project'))
            ? Project::forTenant($tenantId)->whereIn('id', $p)->pluck('name', 'id')
            : collect();
        $tickets = ($t = $ids('ticket'))
            ? Ticket::forTenant($tenantId)->whereIn('id', $t)->pluck('subject', 'id')
            : collect();

        return $tasks->map(function (Task $task) use ($tenantId, $projects, $tickets) {
            if (! $task->rel_id) {
                return $task;
            }
            $id = (int) $task->rel_id;

            [$label, $url] = match ($task->rel_type) {
                'project' => [$projects[$id] ?? "Project #{$id}", "/app/projects/{$id}"],
                'ticket'  => [$tickets[$id] ?? "Ticket #{$id}", "/app/helpdesk/tickets/{$id}"],
                default   => [null, null],
            };

            if ($task->rel_type === 'customer') {
                return $this->decorateRelation($task, $tenantId);
            }

            $task->setAttribute('rel_label', $label);
            $task->setAttribute('rel_url', $url);

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
            'project' => Schema::hasTable('projects')
                ? [Project::forTenant($tenantId)->whereKey($relId)->value('name') ?? "Project #{$relId}", "/app/projects/{$relId}"]
                : [null, null],
            'ticket' => Schema::hasTable('tickets')
                ? [Ticket::forTenant($tenantId)->whereKey($relId)->value('subject') ?? "Ticket #{$relId}", "/app/helpdesk/tickets/{$relId}"]
                : [null, null],
            default => [null, null],
        };
    }
}
