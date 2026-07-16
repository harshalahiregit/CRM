<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\Task;
use App\Models\Sales\TaskChecklistItem;
use App\Models\Sales\TaskComment;
use App\Models\Sales\TaskStatus;
use App\Repositories\Sales\TaskRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaskService
{
    public function __construct(private TaskRepository $taskRepository)
    {
    }

    /**
     * Ensure a tenant has its default task statuses. Seeded lazily so tasks
     * work out-of-the-box for every tenant without a per-tenant seeder run.
     */
    public function ensureDefaultStatuses(int $tenantId): void
    {
        if (TaskStatus::forTenant($tenantId)->exists()) {
            return;
        }
        foreach (TaskStatus::defaults() as $row) {
            TaskStatus::create([...$row, 'tenant_id' => $tenantId]);
        }
    }

    public function list(int $tenantId, array $filters)
    {
        $this->ensureDefaultStatuses($tenantId);
        // Filter is a short key ('lead'); rows store the FQCN — translate so
        // the where-clause matches.
        $this->resolveTaskableType($filters);
        return $this->taskRepository->filtered($tenantId, $filters);
    }

    /**
     * Translate a short subject key ('lead','invoice',…) to the FQCN actually
     * stored in taskable_type, so morphTo() resolves. Mirrors ReminderService.
     */
    private function resolveTaskableType(array &$data): void
    {
        if (! empty($data['taskable_type'])) {
            $data['taskable_type'] = SalesActivityService::SUBJECT_MAP[$data['taskable_type']]
                ?? throw new BusinessException("Unknown task subject type: {$data['taskable_type']}", 422);
        }
    }

    public function kanban(int $tenantId)
    {
        $this->ensureDefaultStatuses($tenantId);
        return $this->taskRepository->kanbanColumns($tenantId);
    }

    public function statuses(int $tenantId)
    {
        $this->ensureDefaultStatuses($tenantId);
        return TaskStatus::forTenant($tenantId)->ordered()->get();
    }

    public function create(array $data, int $tenantId, int $userId): Task
    {
        $this->ensureDefaultStatuses($tenantId);

        return DB::transaction(function () use ($data, $tenantId, $userId) {
            $assignees = $data['assignees'] ?? [];
            unset($data['assignees']);
            $this->resolveTaskableType($data);

            $task = Task::create([
                ...$data,
                'tenant_id'  => $tenantId,
                'created_by' => $userId,
            ]);

            if (!empty($assignees)) {
                $this->syncAssignees($task, $assignees);
            }

            $task->logActivity('created', "Task \"{$task->name}\" created", null, null, $userId);

            Log::channel('sales')->info('Task created', ['task_id' => $task->id, 'tenant_id' => $tenantId]);

            return $this->loadFull($task);
        });
    }

    public function show(Task $task, int $tenantId): Task
    {
        $this->assertTenant($task, $tenantId);
        return $this->loadFull($task);
    }

    public function update(Task $task, array $data, int $tenantId): Task
    {
        $this->assertTenant($task, $tenantId);

        return DB::transaction(function () use ($task, $data, $tenantId) {
            if (array_key_exists('assignees', $data)) {
                $this->syncAssignees($task, $data['assignees'] ?? []);
                unset($data['assignees']);
            }
            $this->resolveTaskableType($data);

            $task->update($data);

            Log::channel('sales')->info('Task updated', ['task_id' => $task->id, 'tenant_id' => $tenantId]);

            return $this->loadFull($task->fresh());
        });
    }

    public function delete(Task $task, int $tenantId): void
    {
        $this->assertTenant($task, $tenantId);
        $task->delete();
        Log::channel('sales')->info('Task deleted', ['task_id' => $task->id, 'tenant_id' => $tenantId]);
    }

    public function updateStatus(Task $task, int $statusId, int $tenantId, int $userId): Task
    {
        $this->assertTenant($task, $tenantId);

        $status = TaskStatus::forTenant($tenantId)->findOrFail($statusId);
        $task->update([
            'status_id'     => $status->id,
            'date_finished' => $status->is_completed_status ? now()->toDateString() : null,
            'progress'      => $status->is_completed_status ? 100 : $task->progress,
        ]);

        $task->logActivity('status_changed', "Task moved to \"{$status->name}\"", null, $status->name, $userId);

        return $this->loadFull($task->fresh());
    }

    public function reorder(array $order, int $tenantId): void
    {
        // $order = [['id' => 1, 'status_id' => 2, 'kanban_order' => 0], ...]
        DB::transaction(function () use ($order, $tenantId) {
            // Only this tenant's status ids are assignable — guard against a
            // payload that tries to move a card onto a foreign tenant's status.
            $validStatusIds = TaskStatus::forTenant($tenantId)->pluck('id')->all();
            foreach ($order as $row) {
                $update = ['kanban_order' => $row['kanban_order'] ?? 0];
                if (!empty($row['status_id']) && in_array($row['status_id'], $validStatusIds)) {
                    $update['status_id'] = $row['status_id'];
                }
                Task::forTenant($tenantId)->where('id', $row['id'])->update($update);
            }
        });
    }

    public function assign(Task $task, array $staffIds, int $tenantId): Task
    {
        $this->assertTenant($task, $tenantId);
        $this->syncAssignees($task, $staffIds);
        return $this->loadFull($task->fresh());
    }

    public function updateProgress(Task $task, int $progress, int $tenantId): Task
    {
        $this->assertTenant($task, $tenantId);
        $task->update(['progress' => max(0, min(100, $progress))]);
        return $this->loadFull($task->fresh());
    }

    /* ── Checklist ──────────────────────────────────────────────── */
    public function addChecklistItem(Task $task, string $description, int $tenantId): TaskChecklistItem
    {
        $this->assertTenant($task, $tenantId);
        return TaskChecklistItem::create([
            'tenant_id'   => $tenantId,
            'task_id'     => $task->id,
            'description' => $description,
            'sort_order'  => (int) $task->checklistItems()->max('sort_order') + 1,
        ]);
    }

    public function toggleChecklistItem(TaskChecklistItem $item, int $tenantId, int $userId): TaskChecklistItem
    {
        $this->assertChildTenant($item->task_id, $tenantId);
        $finishing = ! $item->is_finished;
        $item->update([
            'is_finished' => $finishing,
            'finished_by' => $finishing ? $userId : null,
            'finished_at' => $finishing ? now() : null,
        ]);
        return $item->fresh();
    }

    public function deleteChecklistItem(TaskChecklistItem $item, int $tenantId): void
    {
        $this->assertChildTenant($item->task_id, $tenantId);
        $item->delete();
    }

    /* ── Comments ───────────────────────────────────────────────── */
    public function addComment(Task $task, string $content, int $tenantId, int $userId): TaskComment
    {
        $this->assertTenant($task, $tenantId);
        return TaskComment::create([
            'tenant_id'  => $tenantId,
            'task_id'    => $task->id,
            'content'    => $content,
            'created_by' => $userId,
        ])->load('author:id,name');
    }

    public function deleteComment(TaskComment $comment, int $tenantId): void
    {
        $this->assertChildTenant($comment->task_id, $tenantId);
        $comment->delete();
    }

    /* ── Helpers ────────────────────────────────────────────────── */
    private function syncAssignees(Task $task, array $staffIds): void
    {
        $now = now();
        // task_assignees.tenant_id is NOT NULL and the BelongsToTenant boot hook
        // does not fire on pivot inserts, so set tenant_id explicitly here.
        $pivot = collect($staffIds)
            ->mapWithKeys(fn ($id) => [$id => ['assigned_at' => $now, 'tenant_id' => $task->tenant_id]])
            ->all();
        $task->assignees()->sync($pivot);
    }

    private function loadFull(Task $task): Task
    {
        return $task->load([
            'status', 'assignees:id,name', 'creator:id,name',
            'checklistItems', 'comments.author:id,name',
        ])->loadCount(['checklistItems', 'comments']);
    }

    private function assertTenant(Task $task, int $tenantId): void
    {
        if ($task->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }

    // Child rows (checklist/comment) are addressed by their own id — verify
    // tenant ownership via the parent task, never trust the child id alone.
    private function assertChildTenant(int $taskId, int $tenantId): void
    {
        $ok = Task::forTenant($tenantId)->whereKey($taskId)->exists();
        if (! $ok) {
            throw new UnauthorizedTenantException();
        }
    }
}
