<?php

namespace App\Services\Project;

use App\Exceptions\BusinessException;
use App\Models\Project\Project;
use App\Models\Project\ProjectFile;
use App\Models\Project\ProjectMilestone;
use App\Models\User;
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
        $project->load(['creator:id,name', 'members.user:id,name,email', 'milestones']);

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

    /* ── Members ────────────────────────────────────────────────── */

    /** Replace the project's member set (sync). Users must be in the tenant. */
    public function syncMembers(int $projectId, array $userIds, int $tenantId): Collection
    {
        $project = $this->find($projectId, $tenantId);
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        $valid = User::where('tenant_id', $tenantId)->whereIn('id', $userIds)->pluck('id')->all();
        if (count($valid) !== count($userIds)) {
            throw new BusinessException('One or more users are not in this workspace.', 422);
        }

        DB::transaction(function () use ($project, $valid, $tenantId) {
            $project->members()->whereNotIn('user_id', $valid ?: [0])->delete();
            $existing = $project->members()->pluck('user_id')->all();
            foreach (array_diff($valid, $existing) as $uid) {
                $project->members()->create(['tenant_id' => $tenantId, 'user_id' => $uid]);
            }
        });

        return $project->members()->with('user:id,name,email')->get();
    }

    /* ── Milestones ─────────────────────────────────────────────── */

    public function listMilestones(int $projectId, int $tenantId): Collection
    {
        return $this->find($projectId, $tenantId)->milestones()->get();
    }

    public function createMilestone(int $projectId, array $data, int $tenantId): ProjectMilestone
    {
        $project = $this->find($projectId, $tenantId);

        return $project->milestones()->create([...$data, 'tenant_id' => $tenantId]);
    }

    public function updateMilestone(int $milestoneId, array $data, int $tenantId): ProjectMilestone
    {
        $milestone = ProjectMilestone::forTenant($tenantId)->find($milestoneId);
        if (! $milestone) {
            throw new BusinessException('Milestone not found.', 404);
        }
        $milestone->update($data);

        return $milestone->fresh();
    }

    public function deleteMilestone(int $milestoneId, int $tenantId): void
    {
        $milestone = ProjectMilestone::forTenant($tenantId)->find($milestoneId);
        if (! $milestone) {
            throw new BusinessException('Milestone not found.', 404);
        }
        $milestone->delete();
    }

    /* ── Files ──────────────────────────────────────────────────── */

    public function listFiles(int $projectId, int $tenantId): Collection
    {
        $this->find($projectId, $tenantId);

        return ProjectFile::forTenant($tenantId)->where('project_id', $projectId)
            ->with('uploader:id,name')->latest()->get();
    }

    /** Persist an already-stored file's metadata (controller handles the upload). */
    public function storeFile(int $projectId, array $data, int $tenantId, int $userId): ProjectFile
    {
        $this->find($projectId, $tenantId);

        return ProjectFile::create([
            'tenant_id'           => $tenantId,
            'project_id'          => $projectId,
            'file_name'           => $data['file_name'],
            'original_name'       => $data['original_name'],
            'file_path'           => $data['file_path'],
            'visible_to_customer' => $data['visible_to_customer'] ?? false,
            'uploaded_by'         => $userId,
        ]);
    }

    public function findFile(int $fileId, int $projectId, int $tenantId): ProjectFile
    {
        $file = ProjectFile::forTenant($tenantId)->where('project_id', $projectId)->find($fileId);
        if (! $file) {
            throw new BusinessException('File not found.', 404);
        }

        return $file;
    }

    public function deleteFile(int $fileId, int $projectId, int $tenantId): void
    {
        $this->findFile($fileId, $projectId, $tenantId)->delete();
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
