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
use App\Services\NotificationService;
use App\Services\TagService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ProjectService
{
    /** Portal-only roles — never project members (matches Tasks/Helpdesk). */
    private const EXTERNAL_ROLES = ['client', 'vendor', 'third_party_vendor'];

    /** Human labels for the stored status keys — used in notification copy. */
    private const STATUS_LABELS = [
        'not_started' => 'Not Started',
        'in_progress' => 'In Progress',
        'on_hold'     => 'On Hold',
        'cancelled'   => 'Cancelled',
        'finished'    => 'Finished',
    ];

    private CustomerServiceContract $customers;

    /**
     * Reuses Helpdesk's CustomerServiceContract (rule: same mock, don't diverge).
     * Falls back to the mock until Zafar binds a real implementation.
     */
    public function __construct(
        private ProjectRepository $projects,
        private NotificationService $notifications,
        private TagService $tags,
        ?CustomerServiceContract $customers = null,
    ) {
        $this->customers = $customers ?? new MockCustomerService();
    }

    public function list(int $tenantId, array $filters = [], ?int $userId = null): Collection
    {
        $projects = $this->projects->filtered($tenantId, $filters);

        // Batch the tag lookup — one query for the whole page, not one per row.
        $tagMap = $this->tags->tagsForMany('project', $projects->pluck('id')->all(), $tenantId);

        $decorated = $projects->map(function (Project $p) use ($tenantId, $tagMap, $userId) {
            $this->decorateWithCustomer($p, $tenantId);
            $p->setAttribute('tags', $tagMap[$p->id] ?? []);
            $p->setAttribute('is_pinned', $userId ? in_array($userId, array_map('intval', $p->pinned_by ?? []), true) : false);

            return $p;
        });

        // Pinned first — pinning is only useful if it actually floats the row up.
        return $userId
            ? $decorated->sortByDesc(fn (Project $p) => $p->is_pinned ? 1 : 0)->values()
            : $decorated;
    }

    public function show(int $id, int $tenantId, ?int $userId = null): Project
    {
        $project = $this->find($id, $tenantId);
        $project->load(['creator:id,name', 'members.user:id,name,email', 'milestones']);
        $project->setAttribute('tags', $this->tags->tagsFor('project', $project->id, $tenantId));
        $project->setAttribute('is_pinned', $userId ? in_array($userId, array_map('intval', $project->pinned_by ?? []), true) : false);

        return $this->decorateWithCustomer($project, $tenantId);
    }

    public function create(array $data, int $tenantId, int $userId): Project
    {
        if (! empty($data['customer_id']) && ! $this->customers->exists((int) $data['customer_id'], $tenantId)) {
            throw new BusinessException('The selected customer does not exist.', 422);
        }

        // Members and tags aren't columns — pull them out before the insert.
        $members = $data['member_ids'] ?? [];
        $tags = $data['tags'] ?? null;
        unset($data['member_ids'], $data['tags']);

        $project = $this->projects->create([
            ...$data,
            'tenant_id'  => $tenantId,
            'created_by' => $userId,
        ]);

        if ($members) {
            $this->syncMembers($project->id, $members, $tenantId, $userId);
        }
        if ($tags !== null) {
            $this->tags->sync('project', $project->id, $tags, $tenantId);
        }

        return $this->show($project->id, $tenantId, $userId);
    }

    public function update(int $id, array $data, int $tenantId, ?int $userId = null): Project
    {
        $project = $this->find($id, $tenantId);

        if (array_key_exists('customer_id', $data) && ! empty($data['customer_id'])
            && ! $this->customers->exists((int) $data['customer_id'], $tenantId)) {
            throw new BusinessException('The selected customer does not exist.', 422);
        }

        $members = $data['member_ids'] ?? null;
        $tags = $data['tags'] ?? null;
        unset($data['member_ids'], $data['tags']);

        $project->fill($data);

        // Moving the deadline re-arms the "due soon" nudge.
        if ($project->isDirty('deadline')) {
            $project->deadline_notified = false;
        }

        $project->save();

        if ($members !== null) {
            $this->syncMembers($project->id, $members, $tenantId, $userId);
        }
        if ($tags !== null) {
            $this->tags->sync('project', $project->id, $tags, $tenantId);
        }

        return $this->show($project->id, $tenantId, $userId);
    }

    /** Change status; stamp date_finished on the transition into "finished". */
    public function changeStatus(int $id, string $status, int $tenantId, ?int $actorId = null): Project
    {
        $project = $this->find($id, $tenantId);
        $from = $project->status;
        $project->status = $status;

        // Only stamp on the way IN to finished, and only clear on the way OUT of
        // it. Assigning null for every other status wiped the completion date of
        // an already-finished project on any unrelated status change.
        if ($status === 'finished') {
            $project->date_finished = $project->date_finished ?? now();
        } elseif ($from === 'finished') {
            $project->date_finished = null;
        }

        $project->save();

        if ($from !== $status) {
            $label = self::STATUS_LABELS[$status] ?? $status;
            foreach ($this->watcherIds($project, $actorId) as $uid) {
                $this->notifications->notify(
                    $uid, $tenantId, 'project.status_changed',
                    "Project {$label}: {$project->name}",
                    (self::STATUS_LABELS[$from] ?? $from)." → {$label}",
                    "/app/projects/{$project->id}", $actorId,
                );
            }
        }

        return $this->decorateWithCustomer($project->fresh('creator'), $tenantId);
    }

    /** Everyone watching a project: members + creator, minus the actor. */
    private function watcherIds(Project $project, ?int $actorId): array
    {
        return $project->members()->pluck('user_id')
            ->push($project->created_by)
            ->map(fn ($i) => (int) $i)
            ->unique()
            ->reject(fn ($i) => $actorId !== null && $i === $actorId)
            ->values()->all();
    }

    public function delete(int $id, int $tenantId): void
    {
        $this->find($id, $tenantId)->delete();
    }

    /**
     * Clone a project. Members and milestones are opt-in; tasks, files and
     * logged time never copy — they're history, and history stays with the
     * original. The copy always starts fresh: not_started, 0% progress.
     */
    public function copy(int $id, array $opts, int $tenantId, int $userId): Project
    {
        $src = $this->find($id, $tenantId);

        $copy = $this->projects->create([
            'tenant_id'            => $tenantId,
            'name'                 => $opts['name'] ?? $src->name.' (copy)',
            'description'          => $src->description,
            'status'               => 'not_started',
            'customer_id'          => $src->customer_id,
            'billing_type'         => $src->billing_type,
            'project_cost'         => $src->project_cost,
            'rate_per_hour'        => $src->rate_per_hour,
            'start_date'           => $opts['start_date'] ?? now()->toDateString(),
            'deadline'             => $opts['deadline'] ?? null,
            'progress'             => 0,
            'progress_from_tasks'  => $src->progress_from_tasks,
            'estimated_hours'      => $src->estimated_hours,
            'visible_tabs'         => $src->visible_tabs,
            'customer_permissions' => $src->customer_permissions,
            'created_by'           => $userId,
        ]);

        if (! empty($opts['copy_members'])) {
            $this->syncMembers($copy->id, $src->members()->pluck('user_id')->all(), $tenantId, $userId);
        }

        if (! empty($opts['copy_milestones'])) {
            foreach ($src->milestones as $m) {
                $copy->milestones()->create([
                    'tenant_id' => $tenantId, 'name' => $m->name, 'description' => $m->description,
                    'due_date' => $m->due_date, 'start_date' => $m->start_date,
                    'color' => $m->color, 'order' => $m->order,
                    'hide_from_customer' => $m->hide_from_customer,
                ]);
            }
        }

        // Tags always copy — they describe what the project IS, not its history.
        $this->tags->sync('project', $copy->id, $this->tags->tagsFor('project', $src->id, $tenantId)->pluck('name')->all(), $tenantId);

        // show() rather than the bare model: the caller needs tags/is_pinned/customer
        // on the response, or the UI renders the new copy as untagged.
        return $this->show($copy->id, $tenantId, $userId);
    }

    /**
     * Pin/unpin for the current user only. Pinning is personal — a shared boolean
     * would let one person reorder everyone else's project list.
     */
    public function togglePin(int $id, int $tenantId, int $userId): Project
    {
        $project = $this->find($id, $tenantId);
        $pinned = collect($project->pinned_by ?? [])->map(fn ($i) => (int) $i);

        $project->pinned_by = $pinned->contains($userId)
            ? $pinned->reject(fn ($i) => $i === $userId)->values()->all()
            : $pinned->push($userId)->unique()->values()->all();

        $project->save();

        return $project->fresh('creator');
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

    /**
     * One "deadline soon / overdue" nudge per project, to members + creator.
     * Idempotent via deadline_notified, so the scheduler can run every 15 min.
     */
    public function fireDueDeadlines(?\Illuminate\Support\Carbon $now = null): int
    {
        $now = $now ?? now();
        $sent = 0;
        $horizon = $now->copy()->addDay()->endOfDay();

        Project::query()
            ->where('deadline_notified', false)
            ->whereNotNull('deadline')
            ->whereNotIn('status', ['finished', 'cancelled'])
            ->where('deadline', '<=', $horizon)
            ->chunkById(100, function (Collection $projects) use ($now, &$sent) {
                foreach ($projects as $project) {
                    $overdue = \Illuminate\Support\Carbon::parse($project->deadline)->endOfDay()->lt($now);
                    foreach ($this->watcherIds($project, null) as $uid) {
                        $this->notifications->notify(
                            $uid, $project->tenant_id,
                            $overdue ? 'project.overdue' : 'project.due_soon',
                            ($overdue ? 'Project overdue: ' : 'Project due soon: ').$project->name,
                            'Deadline '.\Illuminate\Support\Carbon::parse($project->deadline)->format('M j'),
                            "/app/projects/{$project->id}",
                        );
                        $sent++;
                    }
                    $project->forceFill(['deadline_notified' => true])->save();
                }
            });

        return $sent;
    }

    /* ── Members ────────────────────────────────────────────────── */

    /** Replace the project's member set (sync). Users must be staff in the tenant. */
    public function syncMembers(int $projectId, array $userIds, int $tenantId, ?int $actorId = null): Collection
    {
        $project = $this->find($projectId, $tenantId);
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        $valid = User::where('tenant_id', $tenantId)->whereIn('id', $userIds)
            ->whereNotIn('role', self::EXTERNAL_ROLES)
            ->pluck('id')->all();
        if (count($valid) !== count($userIds)) {
            throw new BusinessException('One or more users are not staff in this workspace.', 422);
        }

        // Captured inside the transaction, notified after it commits so a failed
        // notification can never roll back the membership change.
        $added = [];

        DB::transaction(function () use ($project, $valid, $tenantId, &$added) {
            $project->members()->whereNotIn('user_id', $valid ?: [0])->delete();
            $existing = $project->members()->pluck('user_id')->all();
            $added = array_values(array_diff($valid, $existing));
            foreach ($added as $uid) {
                $project->members()->create(['tenant_id' => $tenantId, 'user_id' => $uid]);
            }
        });

        foreach ($added as $uid) {
            $this->notifications->notify(
                $uid, $tenantId, 'project.member_added',
                "Added to project: {$project->name}",
                $project->deadline ? 'Deadline '.$project->deadline->format('M j') : null,
                "/app/projects/{$project->id}", $actorId,
            );
        }

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

    /** Removes the row and the blob; a missing disk file is not an error. */
    public function deleteFile(int $fileId, int $projectId, int $tenantId): void
    {
        $file = $this->findFile($fileId, $projectId, $tenantId);
        $path = $file->file_path;
        $file->delete();

        // Deleting only the row orphaned every blob under projects/{tenant}/{id}.
        try {
            Storage::disk('local')->delete($path);
        } catch (\Throwable $e) {
            Log::warning("Project file delete failed ({$path}): {$e->getMessage()}");
        }
    }

    /** Customer roster for pickers — via the contract, never a direct join. */
    public function listCustomers(int $tenantId): array
    {
        return array_values(array_filter($this->customers->listCustomers($tenantId)));
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
