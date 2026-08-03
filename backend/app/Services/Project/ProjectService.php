<?php

namespace App\Services\Project;

use App\Exceptions\BusinessException;
use App\Models\Project\Project;
use App\Models\Project\ProjectFile;
use App\Models\Project\ProjectMilestone;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Repositories\Project\ProjectRepository;
use App\Services\Helpdesk\Contracts\CustomerServiceContract;
use App\Services\Helpdesk\Mocks\MockCustomerService;
use App\Services\NotificationService;
use App\Services\StatusService;
use App\Services\TagService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        private StatusService $statuses,
        ?CustomerServiceContract $customers = null,
    ) {
        $this->customers = $customers ?? new MockCustomerService();
    }

    private function statusLabels(int $tenantId): array
    {
        return $this->statuses->labels('project', $tenantId) ?: self::STATUS_LABELS;
    }

    /** The status key that closes a project ('finished' unless reconfigured). */
    private function closedKey(int $tenantId): string
    {
        return $this->statuses->closedKey('project', $tenantId) ?? 'finished';
    }

    /* ── Project Settings catalog (config/projects.php) ─────────────
     * The Visible-Tabs and customer-permission bags are validated and
     * defaulted against one canonical list so junk keys never get stored and
     * the frontend renders the same options the backend accepts.
     */

    /** @return array<int,string> every allowed tab key */
    public static function tabKeys(): array
    {
        return array_column(config('projects.tabs', []), 'key');
    }

    /** @return array<int,string> every allowed customer-permission key */
    public static function permissionKeys(): array
    {
        return array_column(config('projects.customer_permissions', []), 'key');
    }

    /** A new project's default tab set: every tab that has a working screen, on. */
    public static function defaultVisibleTabs(): array
    {
        $out = [];
        foreach (config('projects.tabs', []) as $t) {
            if (! empty($t['implemented'])) {
                $out[$t['key']] = true;
            }
        }

        return $out;
    }

    /** Drop any key not in the catalog and cast the rest to real booleans. */
    private function sanitizeSettings(array $data): array
    {
        if (array_key_exists('visible_tabs', $data) && is_array($data['visible_tabs'])) {
            $data['visible_tabs'] = collect($data['visible_tabs'])
                ->only(self::tabKeys())->map(fn ($v) => (bool) $v)->all();
        }
        foreach (['customer_permissions', 'vendor_permissions', 'tpv_permissions'] as $bag) {
            if (array_key_exists($bag, $data) && is_array($data[$bag])) {
                $data[$bag] = collect($data[$bag])
                    ->only(self::permissionKeys())->map(fn ($v) => (bool) $v)->all();
            }
        }

        return $data;
    }

    /* ── Access control ─────────────────────────────────────────────
     * Admins see every project in the tenant. A non-admin staff member
     * only sees a project they created or are a member of. Editing/deleting
     * a project (and its members/status) is admin-or-creator only.
     */

    private function canSeeProject(Project $project, int $userId): bool
    {
        return (int) $project->created_by === $userId
            || $project->members()->where('user_id', $userId)->exists();
    }

    /** View access: admin, creator, or member. Throws 403 otherwise. */
    public function assertProjectVisible(int $projectId, int $tenantId, ?int $userId, bool $isAdmin): Project
    {
        $project = $this->find($projectId, $tenantId);

        if (! $isAdmin && $userId !== null && ! $this->canSeeProject($project, $userId)) {
            throw new BusinessException('You do not have access to this project.', 403);
        }

        return $project;
    }

    /** Manage access (edit / delete / status / members): admin or the creator only. */
    public function assertProjectManage(int $projectId, int $tenantId, ?int $userId, bool $isAdmin): Project
    {
        $project = $this->find($projectId, $tenantId);

        if (! $isAdmin && $userId !== null && (int) $project->created_by !== $userId) {
            throw new BusinessException('Only the project’s creator or an admin can change this project.', 403);
        }

        return $project;
    }

    /** Manage a milestone (by its id) → resolve its project, then manage-check that. */
    public function assertMilestoneManage(int $milestoneId, int $tenantId, ?int $userId, bool $isAdmin): void
    {
        $milestone = ProjectMilestone::where('tenant_id', $tenantId)->findOrFail($milestoneId);
        $this->assertProjectManage((int) $milestone->project_id, $tenantId, $userId, $isAdmin);
    }

    public function list(int $tenantId, array $filters = [], ?int $userId = null, bool $isAdmin = true): Collection
    {
        $visibility = (! $isAdmin && $userId !== null) ? ['user_id' => $userId] : null;
        $projects = $this->projects->filtered($tenantId, $filters, $visibility);

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

    /**
     * Lightweight ACTIVE-project list for cross-module dropdowns (HR Recruitment
     * reuses this — no duplicate project store, no new table). Closed projects
     * (finished/cancelled) are excluded so they never appear in a NEW selection;
     * existing records keep their saved project through project_id regardless.
     *
     * The projects table has no code/location columns, so project_code is derived
     * from the id and location is null — shaped here (never persisted) so consumers
     * get a stable contract without a schema change. project_manager is the creator;
     * client_name resolves through the customer module (single query per client).
     */
    public function options(int $tenantId): array
    {
        $projects = Project::where('tenant_id', $tenantId)
            ->whereNotIn('status', ['finished', 'cancelled'])
            ->with('creator:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'customer_id', 'created_by']);

        // Resolve each distinct client once — customer lives in another module.
        $clientNames = [];
        foreach ($projects->pluck('customer_id')->filter()->unique() as $cid) {
            $clientNames[(int) $cid] = $this->customers->getCustomer((int) $cid, $tenantId)['name'] ?? null;
        }

        return $projects->map(fn (Project $p) => [
            'id'              => $p->id,
            'name'            => $p->name,
            'project_code'    => 'PRJ-'.str_pad((string) $p->id, 4, '0', STR_PAD_LEFT),
            'client_name'     => $p->customer_id ? ($clientNames[(int) $p->customer_id] ?? null) : null,
            'project_manager' => $p->creator?->name,
            'location'        => null,   // no column on projects (mapped, not stored)
            'status'          => $p->status,
            'is_active'       => true,   // filtered to active above
        ])->values()->all();
    }

    public function show(int $id, int $tenantId, ?int $userId = null): Project
    {
        $project = $this->find($id, $tenantId);
        $project->load(['creator:id,name', 'members.user:id,name,email', 'milestones']);
        $this->attachMilestoneProgress($project->milestones, $project->id, $tenantId);
        $project->setAttribute('tags', $this->tags->tagsFor('project', $project->id, $tenantId));
        $project->setAttribute('is_pinned', $userId ? in_array($userId, array_map('intval', $project->pinned_by ?? []), true) : false);

        return $this->decorateWithCustomer($project, $tenantId);
    }

    public function create(array $data, int $tenantId, int $userId): Project
    {
        $data = $this->normalizeLink($data, $tenantId);

        // Members and tags aren't columns — pull them out before the insert.
        $members = $data['member_ids'] ?? [];
        $tags = $data['tags'] ?? null;
        unset($data['member_ids'], $data['tags']);

        // Keep only catalogued settings keys; a brand-new project with no explicit
        // tab choice gets every working tab switched on by default.
        $data = $this->sanitizeSettings($data);
        if (empty($data['visible_tabs'])) {
            $data['visible_tabs'] = self::defaultVisibleTabs();
        }

        $project = $this->projects->create([
            ...$data,
            'tenant_id'  => $tenantId,
            'created_by' => $userId,
        ]);

        $this->logActivity($project, 'project_created', 'Project created', $userId, true);

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

        $data = $this->normalizeLink($data, $tenantId);

        $members = $data['member_ids'] ?? null;
        $tags = $data['tags'] ?? null;
        unset($data['member_ids'], $data['tags']);

        $data = $this->sanitizeSettings($data);
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

        $this->statuses->assertTransition('project', $from, $status, $tenantId);

        $closed = $this->closedKey($tenantId);
        $project->status = $status;

        // Only stamp on the way IN to the closing status, and only clear on the
        // way OUT of it. Assigning null for every other status wiped the
        // completion date of an already-finished project on any unrelated change.
        if ($status === $closed) {
            $project->date_finished = $project->date_finished ?? now();
        } elseif ($from === $closed) {
            $project->date_finished = null;
        }

        $project->save();

        if ($from !== $status) {
            $labels = $this->statusLabels($tenantId);
            $label = $labels[$status] ?? $status;
            $this->logActivity($project, 'status_changed', "Status changed to {$label}", $actorId, true);
            foreach ($this->watcherIds($project, $actorId) as $uid) {
                $this->notifications->notify(
                    $uid, $tenantId, 'project.status_changed',
                    "Project {$label}: {$project->name}",
                    ($labels[$from] ?? $from)." → {$label}",
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

            // Only TOP-LEVEL tasks are counted as project rows; the work nested
            // under them is counted by the tree roll-up below. Counting both
            // would score the same job twice.
            if (Schema::hasColumn('tasks', 'parent_id')) {
                $tasks->whereNull('parent_id');
            }

            $rootIds = (clone $tasks)->pluck('id')->map(fn ($i) => (int) $i)->all();
            $total = count($rootIds);
            $done = (clone $tasks)->where('status', $this->statuses->closedKey('task', $tenantId) ?? 'complete')->count();

            // The project bar and the bar inside a task modal must never disagree
            // about the same work, so both come from TaskTreeService. Where a
            // task has subtasks, its share of the project is how much of ITS tree
            // is finished — not a binary open/closed.
            $pct = $total > 0 ? (int) round($done / $total * 100) : 0;
            if (Schema::hasColumn('tasks', 'root_id') && $rootIds) {
                $rolled = app(\App\Services\Task\TaskTreeService::class)->progressForMany($rootIds, $tenantId);
                if ($rolled['total'] > 0) {
                    $pct = $rolled['percent'];
                    $total = $rolled['total'];
                    $done = $rolled['done'];
                }
            }

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
            $name = User::whereKey($uid)->value('name') ?? "user #{$uid}";
            $this->logActivity($project, 'member_added', "{$name} added to the project", $actorId, true);
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
        $milestones = $this->find($projectId, $tenantId)->milestones()->get();
        $this->attachMilestoneProgress($milestones, $projectId, $tenantId);

        return $milestones;
    }

    /**
     * Give each milestone its own completion — done / total of the project tasks
     * pinned to it (status = the tenant's "closed" task key). One grouped query for
     * the whole set, so the Milestones tab and Gantt can show a real per-milestone
     * bar instead of only the project-wide task percentage.
     */
    private function attachMilestoneProgress(Collection $milestones, int $projectId, int $tenantId): void
    {
        if ($milestones->isEmpty() || ! Schema::hasTable('tasks') || ! Schema::hasColumn('tasks', 'milestone_id')) {
            $milestones->each(fn ($m) => $m->setAttribute('progress', ['total' => 0, 'done' => 0, 'percent' => 0]));

            return;
        }

        $closed = $this->statuses->closedKey('task', $tenantId) ?? 'complete';
        $rows = DB::table('tasks')
            ->where('tenant_id', $tenantId)
            ->where('rel_type', 'project')->where('rel_id', $projectId)
            ->whereNull('deleted_at')->whereNotNull('milestone_id')
            ->selectRaw('milestone_id, COUNT(*) AS total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS done', [$closed])
            ->groupBy('milestone_id')
            ->get()->keyBy('milestone_id');

        $milestones->each(function ($m) use ($rows) {
            $r = $rows->get($m->id);
            $total = $r ? (int) $r->total : 0;
            $done = $r ? (int) $r->done : 0;
            $m->setAttribute('progress', [
                'total'   => $total,
                'done'    => $done,
                'percent' => $total > 0 ? (int) round($done / $total * 100) : 0,
            ]);
        });
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

    /** The user role behind each vendor link type. */
    private const LINK_ROLE = ['vendor' => 'vendor', 'tpv' => 'third_party_vendor'];

    /**
     * Vendor / third-party-vendor roster for the "who is this project for?"
     * picker. Vendors & TPVs are portal-login Users (roles vendor /
     * third_party_vendor); `type` = 'vendor' | 'tpv' picks the role. Their
     * vendor-master company name (if any) is shown alongside the login name.
     */
    public function listVendors(int $tenantId, string $type): array
    {
        $role = self::LINK_ROLE[$type] ?? 'vendor';

        $users = User::where('tenant_id', $tenantId)
            ->where('role', $role)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        // Enrich with the vendor master company name where the login is linked.
        $companies = Vendor::forTenant($tenantId)
            ->whereIn('user_id', $users->pluck('id'))
            ->pluck('company_name', 'user_id');

        return $users->map(fn (User $u) => [
            'id'      => $u->id,
            'name'    => $u->name,
            'email'   => $u->email,
            'company' => $companies[$u->id] ?? null,
        ])->values()->all();
    }

    /**
     * Normalise the customer / vendor link on a payload: a project is for exactly
     * one party. Validates the chosen id, sets link_type, and nulls the other
     * columns so stale links can't linger.
     */
    private function normalizeLink(array $data, int $tenantId): array
    {
        // Nothing about the link was submitted — leave the row as-is.
        if (! array_key_exists('customer_id', $data)
            && ! array_key_exists('vendor_user_id', $data)
            && ! array_key_exists('link_type', $data)) {
            return $data;
        }

        $type = $data['link_type'] ?? null;
        $customerId = (int) ($data['customer_id'] ?? 0);
        $vendorUserId = (int) ($data['vendor_user_id'] ?? 0);

        // Infer the type when the client sent only an id.
        if (! $type) {
            $type = $vendorUserId ? 'vendor' : ($customerId ? 'customer' : null);
        }

        if ($type === 'customer' && $customerId) {
            if (! $this->customers->exists($customerId, $tenantId)) {
                throw new BusinessException('The selected customer does not exist.', 422);
            }
            $data['customer_id'] = $customerId;
            $data['vendor_user_id'] = null;
            $data['link_type'] = 'customer';
        } elseif (in_array($type, ['vendor', 'tpv'], true) && $vendorUserId) {
            $role = self::LINK_ROLE[$type];
            $exists = User::where('tenant_id', $tenantId)->where('role', $role)->whereKey($vendorUserId)->exists();
            if (! $exists) {
                throw new BusinessException('The selected '.($type === 'tpv' ? 'third-party vendor' : 'vendor').' does not exist.', 422);
            }
            $data['vendor_user_id'] = $vendorUserId;
            $data['customer_id'] = null;
            $data['link_type'] = $type;
        } else {
            // Explicitly cleared — no party linked.
            $data['customer_id'] = null;
            $data['vendor_user_id'] = null;
            $data['link_type'] = null;
        }

        return $data;
    }

    /* ── Notes ──────────────────────────────────────────────────── */

    public function listNotes(int $projectId, int $tenantId): Collection
    {
        return $this->find($projectId, $tenantId)->notes()
            ->with('author:id,name', 'assignee:id,name', 'attachments')->get();
    }

    public function addNote(int $projectId, array $data, int $tenantId, int $userId): \App\Models\Project\ProjectNote
    {
        $project = $this->find($projectId, $tenantId);
        $note = $project->notes()->create([
            'tenant_id' => $tenantId, 'title' => $data['title'],
            // Notes are now rich-text ("notepad"), so sanitize the HTML before storing.
            'content' => isset($data['content']) && $data['content'] !== null ? \App\Support\HtmlSanitizer::clean($data['content']) : null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'remind_at' => $data['remind_at'] ?? null,
            'created_by' => $userId,
        ]);
        $this->logActivity($project, 'note_added', "Note added: {$data['title']}", $userId);

        return $note->load('author:id,name', 'assignee:id,name', 'attachments');
    }

    public function updateNote(int $noteId, int $projectId, array $data, int $tenantId): \App\Models\Project\ProjectNote
    {
        $note = \App\Models\Project\ProjectNote::forTenant($tenantId)->where('project_id', $projectId)->find($noteId);
        if (! $note) {
            throw new BusinessException('Note not found.', 404);
        }
        if (array_key_exists('title', $data)) {
            $note->title = $data['title'];
        }
        if (array_key_exists('content', $data)) {
            $note->content = $data['content'] !== null ? \App\Support\HtmlSanitizer::clean($data['content']) : null;
        }
        if (array_key_exists('assigned_to', $data)) {
            $note->assigned_to = $data['assigned_to'];
        }
        if (array_key_exists('remind_at', $data)) {
            $note->remind_at = $data['remind_at'];
            $note->reminded = false; // a new/changed reminder should fire again
        }
        $note->save();

        return $note->load('author:id,name', 'assignee:id,name', 'attachments');
    }

    public function deleteNote(int $noteId, int $projectId, int $tenantId): void
    {
        $note = \App\Models\Project\ProjectNote::forTenant($tenantId)->where('project_id', $projectId)->find($noteId);
        if (! $note) {
            throw new BusinessException('Note not found.', 404);
        }
        $note->delete();
    }

    public function addNoteAttachment(int $noteId, int $projectId, $file, int $tenantId, int $userId): \App\Models\Project\ProjectNoteAttachment
    {
        $note = \App\Models\Project\ProjectNote::forTenant($tenantId)->where('project_id', $projectId)->find($noteId);
        if (! $note) {
            throw new BusinessException('Note not found.', 404);
        }
        $path = $file->store("project-notes/{$projectId}", 'local');

        return $note->attachments()->create([
            'tenant_id'     => $tenantId,
            'original_name' => $file->getClientOriginalName(),
            'path'          => $path,
            'size'          => $file->getSize(),
            'mime'          => $file->getClientMimeType(),
            'uploaded_by'   => $userId,
        ]);
    }

    public function deleteNoteAttachment(int $attachmentId, int $projectId, int $tenantId): void
    {
        $att = \App\Models\Project\ProjectNoteAttachment::forTenant($tenantId)
            ->whereHas('note', fn ($q) => $q->where('project_id', $projectId))
            ->find($attachmentId);
        if (! $att) {
            throw new BusinessException('Attachment not found.', 404);
        }
        \Illuminate\Support\Facades\Storage::disk('local')->delete($att->path);
        $att->delete();
    }

    public function noteAttachment(int $attachmentId, int $projectId, int $tenantId): \App\Models\Project\ProjectNoteAttachment
    {
        $att = \App\Models\Project\ProjectNoteAttachment::forTenant($tenantId)
            ->whereHas('note', fn ($q) => $q->where('project_id', $projectId))
            ->find($attachmentId);
        if (! $att) {
            throw new BusinessException('Attachment not found.', 404);
        }

        return $att;
    }

    /* ── Expenses tab ──────────────────────────────────────────── */

    public function listExpenses(int $projectId, int $tenantId): array
    {
        $rows = \App\Models\Project\ProjectExpense::forTenant($tenantId)
            ->where('project_id', $projectId)
            ->with('creator:id,name')
            ->orderByDesc('expense_date')->orderByDesc('id')
            ->get();

        return [
            'rows'  => $rows,
            'total' => round((float) $rows->sum('amount'), 2),
        ];
    }

    public function addExpense(int $projectId, array $data, int $tenantId, int $userId): \App\Models\Project\ProjectExpense
    {
        $this->find($projectId, $tenantId); // authorise/scope

        return \App\Models\Project\ProjectExpense::create([
            'tenant_id'    => $tenantId,
            'project_id'   => $projectId,
            'title'        => $data['title'],
            'category'     => $data['category'] ?? null,
            'amount'       => $data['amount'] ?? 0,
            'expense_date' => $data['expense_date'],
            'note'         => $data['note'] ?? null,
            'billable'     => $data['billable'] ?? false,
            'created_by'   => $userId,
        ])->load('creator:id,name');
    }

    public function updateExpense(int $expenseId, int $projectId, array $data, int $tenantId): \App\Models\Project\ProjectExpense
    {
        $exp = \App\Models\Project\ProjectExpense::forTenant($tenantId)->where('project_id', $projectId)->find($expenseId);
        if (! $exp) {
            throw new BusinessException('Expense not found.', 404);
        }
        $exp->fill(array_intersect_key($data, array_flip(['title', 'category', 'amount', 'expense_date', 'note', 'billable'])));
        $exp->save();

        return $exp->load('creator:id,name');
    }

    public function deleteExpense(int $expenseId, int $projectId, int $tenantId): void
    {
        $exp = \App\Models\Project\ProjectExpense::forTenant($tenantId)->where('project_id', $projectId)->find($expenseId);
        if (! $exp) {
            throw new BusinessException('Expense not found.', 404);
        }
        $exp->delete();
    }

    /* ── Activity ───────────────────────────────────────────────── */

    public function listActivity(int $projectId, int $tenantId): Collection
    {
        return $this->find($projectId, $tenantId)->activities()->with('actor:id,name')->limit(100)->get();
    }

    /** Append one audit row. Swallow-logged so it can never break the write it records. */
    private function logActivity(Project $project, string $type, string $description, ?int $actorId, bool $visibleToCustomer = false): void
    {
        try {
            $project->activities()->create([
                'tenant_id'           => $project->tenant_id,
                'type'                => $type,
                'description'         => Str::limit($description, 500, ''),
                'actor_id'            => $actorId,
                'visible_to_customer' => $visibleToCustomer,
                'created_at'          => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Project activity log failed ({$type}): {$e->getMessage()}");
        }
    }

    /* ── Timesheets ─────────────────────────────────────────────── */

    /**
     * Time logged across this project's tasks. Reads task_timers directly (an
     * internal module, like progress() reads tasks) rather than duplicating the
     * data. Guarded so it works before the Task module ships.
     */
    public function timesheets(int $projectId, int $tenantId): array
    {
        $this->find($projectId, $tenantId);

        if (! Schema::hasTable('task_timers') || ! Schema::hasTable('tasks')) {
            return ['rows' => [], 'total_seconds' => 0];
        }

        $rows = DB::table('task_timers')
            ->join('tasks', 'tasks.id', '=', 'task_timers.task_id')
            ->leftJoin('users', 'users.id', '=', 'task_timers.user_id')
            ->where('tasks.tenant_id', $tenantId)
            ->where('tasks.rel_type', 'project')
            ->where('tasks.rel_id', $projectId)
            ->whereNull('tasks.deleted_at')
            ->whereNotNull('task_timers.end_time')
            ->orderByDesc('task_timers.start_time')
            ->get([
                'task_timers.id', 'task_timers.start_time', 'task_timers.end_time',
                'task_timers.note', 'task_timers.hourly_rate',
                'tasks.name as task_name', 'users.name as member_name',
            ]);

        $total = 0;
        $out = $rows->map(function ($r) use (&$total) {
            $seconds = max(0, strtotime($r->end_time) - strtotime($r->start_time));
            $total += $seconds;

            return [
                'id' => $r->id, 'task_name' => $r->task_name, 'member_name' => $r->member_name,
                'start_time' => $r->start_time, 'end_time' => $r->end_time, 'note' => $r->note,
                'seconds' => $seconds, 'hours' => round($seconds / 3600, 2),
                'cost' => round(($seconds / 3600) * (float) $r->hourly_rate, 2),
            ];
        })->all();

        return ['rows' => $out, 'total_seconds' => $total, 'total_hours' => round($total / 3600, 2)];
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

        // A vendor / TPV-linked project carries the vendor party instead. Shaped
        // like the customer payload ({ id, name }) so the UI reads either the same.
        // The link is the portal-login user; enrich with the vendor company name.
        $vendor = null;
        if ($project->vendor_user_id) {
            $u = User::where('tenant_id', $tenantId)->find($project->vendor_user_id, ['id', 'name', 'email']);
            if ($u) {
                $company = Vendor::forTenant($tenantId)->where('user_id', $u->id)->value('company_name');
                $vendor = ['id' => $u->id, 'name' => $company ?: $u->name, 'contact' => $u->name, 'email' => $u->email];
            }
        }
        $project->setAttribute('vendor', $vendor);

        return $project;
    }
}
