<?php

namespace App\Services\Task;

use App\Exceptions\BusinessException;
use App\Models\Task\Task;
use App\Repositories\Task\TaskRepository;
use App\Services\Helpdesk\Contracts\CustomerServiceContract;
use App\Services\Helpdesk\Mocks\MockCustomerService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

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
        $task->load(['creator:id,name', 'milestone:id,name']);

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
