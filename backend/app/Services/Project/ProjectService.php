<?php

namespace App\Services\Project;

use App\Exceptions\BusinessException;
use App\Models\Project\Project;
use App\Repositories\Project\ProjectRepository;
use App\Services\Helpdesk\Contracts\CustomerServiceContract;
use App\Services\Helpdesk\Mocks\MockCustomerService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProjectService
{
    private CustomerServiceContract $customers;

    /**
     * Reuses Helpdesk's CustomerServiceContract (rule: same mock, don't diverge).
     * Falls back to the mock until Zafar binds a real implementation.
     */
    public function __construct(
        private ProjectRepository $projects,
        ?CustomerServiceContract $customers = null,
    ) {
        $this->customers = $customers ?? new MockCustomerService();
    }

    public function list(int $tenantId, array $filters = []): Collection
    {
        return $this->projects->filtered($tenantId, $filters)
            ->map(fn (Project $p) => $this->decorateWithCustomer($p, $tenantId));
    }

    public function show(int $id, int $tenantId): Project
    {
        $project = $this->find($id, $tenantId);
        $project->load('creator:id,name');   // members/milestones loaded once Step 2 adds them

        return $this->decorateWithCustomer($project, $tenantId);
    }

    public function create(array $data, int $tenantId, int $userId): Project
    {
        if (! empty($data['customer_id']) && ! $this->customers->exists((int) $data['customer_id'], $tenantId)) {
            throw new BusinessException('The selected customer does not exist.', 422);
        }

        $project = $this->projects->create([
            ...$data,
            'tenant_id'  => $tenantId,
            'created_by' => $userId,
        ]);

        return $this->decorateWithCustomer($project->fresh('creator'), $tenantId);
    }

    public function update(int $id, array $data, int $tenantId): Project
    {
        $project = $this->find($id, $tenantId);

        if (array_key_exists('customer_id', $data) && ! empty($data['customer_id'])
            && ! $this->customers->exists((int) $data['customer_id'], $tenantId)) {
            throw new BusinessException('The selected customer does not exist.', 422);
        }

        $project->fill($data);
        $project->save();

        return $this->decorateWithCustomer($project->fresh('creator'), $tenantId);
    }

    /** Change status; stamp date_finished on the transition into "finished". */
    public function changeStatus(int $id, string $status, int $tenantId): Project
    {
        $project = $this->find($id, $tenantId);
        $project->status = $status;
        $project->date_finished = $status === 'finished' ? now() : null;
        $project->save();

        return $project->fresh('creator');
    }

    public function delete(int $id, int $tenantId): void
    {
        $this->find($id, $tenantId)->delete();
    }

    /**
     * Progress. When progress_from_tasks is on AND the tasks table exists,
     * recompute from linked tasks (rel_type=project) and persist it; otherwise
     * return the manual value. Guarded so it works before the Task module ships.
     */
    public function progress(int $id, int $tenantId): array
    {
        $project = $this->find($id, $tenantId);

        if ($project->progress_from_tasks && Schema::hasTable('tasks')) {
            $tasks = DB::table('tasks')
                ->where('tenant_id', $tenantId)
                ->where('rel_type', 'project')
                ->where('rel_id', $project->id)
                ->whereNull('deleted_at');

            $total = (clone $tasks)->count();
            $done  = (clone $tasks)->where('status', 'complete')->count();
            $pct   = $total > 0 ? (int) round($done / $total * 100) : 0;

            $project->update(['progress' => $pct]);

            return ['progress' => $pct, 'source' => 'tasks', 'total_tasks' => $total, 'completed_tasks' => $done];
        }

        return ['progress' => $project->progress, 'source' => 'manual'];
    }

    /* ── Internals ──────────────────────────────────────────────── */

    private function find(int $id, int $tenantId): Project
    {
        $project = $this->projects->findForTenant($id, $tenantId);
        if (! $project) {
            throw new BusinessException('Project not found.', 404);
        }

        return $project;
    }

    private function decorateWithCustomer(Project $project, int $tenantId): Project
    {
        $project->setAttribute(
            'customer',
            $project->customer_id ? $this->customers->getCustomer((int) $project->customer_id, $tenantId) : null,
        );

        return $project;
    }
}
