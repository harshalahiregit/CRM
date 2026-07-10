<?php

namespace App\Services\Task;

use App\Exceptions\BusinessException;
use App\Models\Task\Task;
use App\Models\Task\TaskChecklistItem;
use App\Models\Task\TaskComment;
use App\Models\Task\TaskTimer;
use App\Models\User;
use App\Repositories\Task\TaskRepository;
use App\Services\Helpdesk\Contracts\CustomerServiceContract;
use App\Services\Helpdesk\Mocks\MockCustomerService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TaskService
{
    private CustomerServiceContract $customers;

    public function __construct(
        private TaskRepository $tasks,
        ?CustomerServiceContract $customers = null,
    ) {
        $this->customers = $customers ?? new MockCustomerService();
    }

    public function list(int $tenantId, array $filters = []): Collection
    {
        return $this->tasks->filtered($tenantId, $filters)
            ->map(fn (Task $t) => $this->decorateRelation($t, $tenantId));
    }

    public function show(int $id, int $tenantId): Task
    {
        $task = $this->find($id, $tenantId);
        $task->load([
            'creator:id,name', 'milestone:id,name',
            'assignees.user:id,name,email', 'followers.user:id,name',
            'checklistItems', 'comments.user:id,name', 'timers.user:id,name',
        ]);

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

        $task = $this->tasks->create([...$data, 'tenant_id' => $tenantId, 'created_by' => $userId]);

        return $this->decorateRelation($task->fresh('creator'), $tenantId);
    }

    public function update(int $id, array $data, int $tenantId): Task
    {
        $task = $this->find($id, $tenantId);
        $task->fill($data);
        $task->save();

        return $this->decorateRelation($task->fresh('creator'), $tenantId);
    }

    /** Change status; stamp date_finished on the transition into "complete". */
    public function changeStatus(int $id, string $status, int $tenantId): Task
    {
        $task = $this->find($id, $tenantId);
        $task->status = $status;
        $task->date_finished = $status === 'complete' ? now() : null;
        $task->save();

        return $task->fresh('creator');
    }

    public function delete(int $id, int $tenantId): void
    {
        $this->find($id, $tenantId)->delete();
    }

    /* ── Assignees & followers ──────────────────────────────────── */

    public function syncAssignees(int $taskId, array $userIds, int $tenantId): Collection
    {
        return $this->syncPivot($taskId, 'assignees', $userIds, $tenantId);
    }

    public function syncFollowers(int $taskId, array $userIds, int $tenantId): Collection
    {
        return $this->syncPivot($taskId, 'followers', $userIds, $tenantId);
    }

    private function syncPivot(int $taskId, string $relation, array $userIds, int $tenantId): Collection
    {
        $task = $this->find($taskId, $tenantId);
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        $valid = User::where('tenant_id', $tenantId)->whereIn('id', $userIds)->pluck('id')->all();
        if (count($valid) !== count($userIds)) {
            throw new BusinessException('One or more users are not in this workspace.', 422);
        }

        DB::transaction(function () use ($task, $relation, $valid, $tenantId) {
            $task->{$relation}()->whereNotIn('user_id', $valid ?: [0])->delete();
            $existing = $task->{$relation}()->pluck('user_id')->all();
            foreach (array_diff($valid, $existing) as $uid) {
                $task->{$relation}()->create(['tenant_id' => $tenantId, 'user_id' => $uid]);
            }
        });

        return $task->{$relation}()->with('user:id,name,email')->get();
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

        return $task->comments()->create(['tenant_id' => $tenantId, 'user_id' => $userId, 'content' => $content])->load('user:id,name');
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
            $task->setAttribute('customer', $this->customers->getCustomer((int) $task->rel_id, $tenantId));
        }

        return $task;
    }
}
