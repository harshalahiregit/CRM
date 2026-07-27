<?php

namespace Database\Seeders;

use App\Models\Helpdesk\Ticket;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Project\ProjectService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Realistic Projects + Tasks demo data (owner: Shivam).
 *
 * Same discipline as HelpdeskSeeder:
 *  - additive & reproducible: resets only THIS module's tables for the tenant,
 *    then reseeds, so metrics are stable across runs.
 *  - integration honesty:
 *      · rel_type='customer' tasks use the mock roster IDs (1–3) that
 *        MockCustomerService resolves — SCHEMA-READY, not a real cross-module
 *        integration test (Zafar's `customers` table isn't built yet), exactly
 *        like the Helpdesk seeder's customer_id note.
 *      · tickets.project_id links + rel_type='ticket' tasks exercise the REAL
 *        wired integration against tickets already seeded by HelpdeskSeeder.
 *
 * Runs AFTER HelpdeskSeeder (see DatabaseSeeder) so those tickets exist. Ticket
 * IDs shift every full seed, so we always resolve them dynamically — never hardcode.
 */
class ProjectTaskSeeder extends Seeder
{
    private int $tenantId;
    private int $adminId;
    private Carbon $now;

    public function run(): void
    {
        $tenant = Tenant::first();
        if (! $tenant) {
            $this->command->warn('ProjectTaskSeeder: no tenant found — run the main seeder first.');
            return;
        }
        $this->tenantId = $tenant->id;
        $this->now = now();

        $admin = User::where('tenant_id', $this->tenantId)->where('role', 'admin')->first();
        $this->adminId = $admin->id;

        // Staff pool for members/assignees (real users → true integration).
        $staff = User::where('tenant_id', $this->tenantId)
            ->whereIn('role', ['staff', 'admin'])->pluck('id')->all();

        // Advanced Status Manager defaults. The create_status_lookup_tables
        // migration seeds these for tenants that exist AT MIGRATION TIME, but a
        // fresh `migrate:fresh --seed` runs migrations first (zero tenants) and
        // creates the demo tenant afterwards — so it would otherwise start with
        // empty task/project status lists and a blank status dropdown on every
        // Task/Project detail page. Seed them here for the demo tenant.
        $this->seedStatuses();

        $this->reset();

        // (statuses seeded above, before reset — see seedStatuses())
        [$projectIds, $milestonesByProject] = $this->seedProjects($staff);
        $this->seedTasks($projectIds, $milestonesByProject, $staff);
        $this->seedIntegration($projectIds, $staff);

        $this->recomputeAndReport($projectIds);
    }

    /**
     * Seed the Advanced Status Manager defaults for the tenant (task + project),
     * mirroring create_status_lookup_tables. Idempotent: skips a list that
     * already has rows, so re-seeding never duplicates or clobbers custom edits.
     * is_system = true — these keys are the enum the module validates against.
     */
    private function seedStatuses(): void
    {
        $now = now();

        $sets = [
            'task_statuses' => [
                ['not_started', 'Not Started', '#64748b', 1, false],
                ['in_progress', 'In Progress', '#3b82f6', 2, false],
                ['testing', 'Testing', '#0284c7', 3, false],
                ['awaiting_feedback', 'Awaiting Feedback', '#84cc16', 4, false],
                ['complete', 'Complete', '#22c55e', 100, true],
            ],
            'project_statuses' => [
                ['not_started', 'Not Started', '#475569', 1, false],
                ['in_progress', 'In Progress', '#2563eb', 2, false],
                ['on_hold', 'On Hold', '#f97316', 3, false],
                ['cancelled', 'Cancelled', '#94a3b8', 4, true],
                ['finished', 'Finished', '#16a34a', 100, true],
            ],
        ];

        foreach ($sets as $table => $rows) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (DB::table($table)->where('tenant_id', $this->tenantId)->exists()) {
                continue;   // already seeded (by the migration or a prior run)
            }
            DB::table($table)->insert(array_map(fn ($r) => [
                'tenant_id' => $this->tenantId, 'key' => $r[0], 'name' => $r[1], 'color' => $r[2],
                'order' => $r[3], 'is_closed_status' => $r[4],
                'is_system' => true, 'is_default_filter' => false,
                'can_be_changed_to' => null, 'hidden_for' => null,
                'created_at' => $now, 'updated_at' => $now,
            ], $rows));
        }

        $this->command->info('  ↳ Advanced Status Manager: task + project defaults ensured.');
    }

    /** Reproducibility: wipe only this module's rows for the tenant, children first. */
    private function reset(): void
    {
        foreach (['task_timers', 'task_comments', 'task_checklist_items', 'task_followers', 'task_assignees'] as $t) {
            DB::table($t)->where('tenant_id', $this->tenantId)->delete();
        }
        DB::table('tasks')->where('tenant_id', $this->tenantId)->delete();

        foreach (['project_files', 'project_milestones', 'project_members'] as $t) {
            DB::table($t)->where('tenant_id', $this->tenantId)->delete();
        }
        DB::table('projects')->where('tenant_id', $this->tenantId)->delete();

        // Clear our integration link on Helpdesk tickets (project_id is OUR column).
        DB::table('tickets')->where('tenant_id', $this->tenantId)->update(['project_id' => null]);
    }

    /* ── Projects ───────────────────────────────────────────────── */

    private function seedProjects(array $staff): array
    {
        // 5 projects across all 5 statuses + mixed billing. startOffset/deadlineOffset
        // are days relative to now (negative = past). progress_from_tasks mostly true;
        // the cancelled one uses MANUAL progress to exercise that branch too.
        $specs = [
            ['name' => 'Website Redesign',        'status' => 'in_progress', 'billing' => 'fixed',         'cost' => 150000, 'rate' => null, 'start' => -45, 'deadline' => 30,  'fromTasks' => true,  'progress' => 0,  'members' => true,  'milestones' => 3],
            ['name' => 'Mobile App Launch',       'status' => 'not_started', 'billing' => 'project_hours', 'cost' => null,   'rate' => 2500, 'start' => -8,  'deadline' => 60,  'fromTasks' => true,  'progress' => 0,  'members' => true,  'milestones' => 0],
            ['name' => 'ERP Data Migration',      'status' => 'on_hold',     'billing' => 'task_hours',    'cost' => null,   'rate' => null, 'start' => -60, 'deadline' => -5,  'fromTasks' => true,  'progress' => 0,  'members' => false, 'milestones' => 2], // overdue deadline
            ['name' => 'Q3 Marketing Campaign',   'status' => 'finished',    'billing' => 'fixed',         'cost' => 80000,  'rate' => null, 'start' => -55, 'deadline' => -2,  'fromTasks' => true,  'progress' => 0,  'members' => true,  'milestones' => 2],
            ['name' => 'Internal Tooling Revamp', 'status' => 'cancelled',   'billing' => 'project_hours', 'cost' => null,   'rate' => 1800, 'start' => -30, 'deadline' => 15,  'fromTasks' => false, 'progress' => 35, 'members' => false, 'milestones' => 0], // manual progress
        ];

        $projectIds = [];
        $milestonesByProject = [];

        foreach ($specs as $i => $s) {
            $start = $this->now->copy()->subDays(abs($s['start']));
            $deadline = $s['deadline'] >= 0
                ? $this->now->copy()->addDays($s['deadline'])
                : $this->now->copy()->subDays(abs($s['deadline']));

            $id = DB::table('projects')->insertGetId([
                'tenant_id'           => $this->tenantId,
                'name'                => $s['name'],
                'description'         => '<p>Demo project: '.$s['name'].'.</p>',
                'status'              => $s['status'],
                'customer_id'         => $i < 3 ? [1, 2, 3][$i] : null, // mock roster (schema-ready)
                'billing_type'        => $s['billing'],
                'project_cost'        => $s['cost'],
                'rate_per_hour'       => $s['rate'],
                'start_date'          => $start->toDateString(),
                'deadline'            => $deadline->toDateString(),
                'progress'            => $s['progress'],
                'progress_from_tasks' => $s['fromTasks'] ? 1 : 0,
                'estimated_hours'     => [120, 300, 200, 80, 150][$i],
                'created_by'          => $this->adminId,
                'date_finished'       => $s['status'] === 'finished' ? $deadline->copy()->toDateTimeString() : null,
                'created_at'          => $start->toDateTimeString(),
                'updated_at'          => $this->now->toDateTimeString(),
            ]);
            $projectIds[] = $id;

            // Members (2 per project) on flagged projects.
            if ($s['members']) {
                $members = collect($staff)->shuffle()->take(2)->push($this->adminId)->unique()->take(2);
                foreach ($members as $uid) {
                    DB::table('project_members')->insert([
                        'tenant_id' => $this->tenantId, 'project_id' => $id, 'user_id' => $uid,
                        'created_at' => $start->toDateTimeString(), 'updated_at' => $start->toDateTimeString(),
                    ]);
                }
            }

            // Milestones (mixed due dates: some past, some future).
            $milestonesByProject[$id] = [];
            for ($m = 0; $m < $s['milestones']; $m++) {
                $due = $this->now->copy()->addDays([-10, 12, 40][$m] ?? 20);
                $msId = DB::table('project_milestones')->insertGetId([
                    'tenant_id'          => $this->tenantId,
                    'project_id'         => $id,
                    'name'               => ['Discovery', 'Build', 'Launch'][$m] ?? 'Phase '.($m + 1),
                    'description'        => null,
                    'due_date'           => $due->toDateString(),
                    'start_date'         => $start->toDateString(),
                    'color'              => ['#ec4899', '#3b82f6', '#10b981'][$m] ?? '#8b5cf6',
                    'order'              => $m,
                    'hide_from_customer' => 0,
                    'created_at'         => $start->toDateTimeString(),
                    'updated_at'         => $start->toDateTimeString(),
                ]);
                $milestonesByProject[$id][] = $msId;
            }
        }

        return [$projectIds, $milestonesByProject];
    }

    /* ── Tasks ──────────────────────────────────────────────────── */

    private function seedTasks(array $projectIds, array $milestonesByProject, array $staff): void
    {
        $priorities = ['low', 'medium', 'high', 'urgent'];
        $nonComplete = ['not_started', 'in_progress', 'awaiting_feedback', 'testing'];
        $pi = 0; // priority cursor

        // 15 project-linked tasks (60%): explicit [total, completeCount] per project
        // chosen to yield a believable, non-coincidental progress spread:
        //   P0 4t/1done=25%  P1 3t/0=0%  P2 3t/2=67%  P3 3t/3=100%  P4 2t/1=50%
        $plan = [[4, 1], [3, 0], [3, 2], [3, 3], [2, 1]];

        foreach ($projectIds as $idx => $projectId) {
            [$total, $done] = $plan[$idx];
            $milestones = $milestonesByProject[$projectId] ?? [];

            for ($t = 0; $t < $total; $t++) {
                $isComplete = $t < $done;
                $status = $isComplete ? 'complete' : $nonComplete[$t % count($nonComplete)];
                $priority = $priorities[$pi++ % 4];

                // A couple of active tasks are overdue (due_date in the past, not complete).
                $overdue = ! $isComplete && $t === $total - 1;
                $due = $overdue ? $this->now->copy()->subDays(rand(2, 8)) : $this->now->copy()->addDays(rand(3, 25));

                $this->makeTask([
                    'name'         => "[{$this->shortName($projectId, $projectIds)}] ".$this->taskTitle($t),
                    'priority'     => $priority,
                    'status'       => $status,
                    'rel_type'     => 'project',
                    'rel_id'       => $projectId,
                    'milestone_id' => ($milestones && $t < count($milestones)) ? $milestones[$t] : null,
                    'due_date'     => $due,
                    'date_finished'=> $isComplete ? $this->now->copy()->subDays(rand(1, 10)) : null,
                    // billable spread lands mostly here (see makeTask billable logic)
                ], $staff);
            }
        }

        // 6 standalone tasks (24%)
        for ($t = 0; $t < 6; $t++) {
            $status = ['not_started', 'in_progress', 'awaiting_feedback', 'testing', 'complete', 'in_progress'][$t];
            $this->makeTask([
                'name'         => 'Standalone: '.$this->taskTitle($t + 3),
                'priority'     => $priorities[$pi++ % 4],
                'status'       => $status,
                'rel_type'     => 'standalone',
                'due_date'     => $this->now->copy()->addDays(rand(-5, 20)),
                'date_finished'=> $status === 'complete' ? $this->now->copy()->subDays(rand(1, 6)) : null,
            ], $staff);
        }

        // 4 customer tasks (16%) — mock roster ids [1,2,3]
        for ($t = 0; $t < 4; $t++) {
            $status = ['in_progress', 'complete', 'testing', 'not_started'][$t];
            $this->makeTask([
                'name'         => 'Customer work: '.$this->taskTitle($t + 6),
                'priority'     => $priorities[$pi++ % 4],
                'status'       => $status,
                'rel_type'     => 'customer',
                'rel_id'       => [1, 2, 3, 1][$t],
                'due_date'     => $this->now->copy()->addDays(rand(-4, 18)),
                'date_finished'=> $status === 'complete' ? $this->now->copy()->subDays(rand(1, 6)) : null,
            ], $staff);
        }
    }

    /**
     * Insert a task + its sub-features (assignees, follower, checklist, comments,
     * closed timers). Returns the new task id. Billable/billed and timer coverage
     * are derived from status so the reports have real, non-trivial data.
     */
    private function makeTask(array $o, array $staff): int
    {
        static $seq = 0;
        $seq++;

        $created = $this->now->copy()->subDays(rand(1, 55));
        $start = $created->copy();

        // Billable: ~every 5th task is billable; of those every 2nd is already billed.
        $billable = $seq % 5 === 0;
        $billed = $billable && $seq % 10 === 0;
        $rate = $billable ? [1500, 2000, 2500, 3000][$seq % 4] : null;

        $status = $o['status'] ?? 'not_started';

        $taskId = DB::table('tasks')->insertGetId([
            'tenant_id'         => $this->tenantId,
            'name'              => $o['name'],
            'description'       => $o['description'] ?? null,
            'priority'          => $o['priority'] ?? 'medium',
            'status'            => $status,
            'start_date'        => $start->toDateString(),
            'due_date'          => isset($o['due_date']) ? $o['due_date']->toDateString() : null,
            'date_finished'     => isset($o['date_finished']) && $o['date_finished'] ? $o['date_finished']->toDateTimeString() : null,
            'rel_type'          => $o['rel_type'] ?? 'standalone',
            'rel_id'            => $o['rel_id'] ?? null,
            'milestone_id'      => $o['milestone_id'] ?? null,
            'billable'          => $billable ? 1 : 0,
            'billed'            => $billed ? 1 : 0,
            'hourly_rate'       => $rate,
            'is_public'         => 1,
            'visible_to_client' => 0,
            'kanban_order'      => $seq,
            'created_by'        => $this->adminId,
            'deleted_at'        => null,
            'created_at'        => $created->toDateTimeString(),
            'updated_at'        => $this->now->toDateTimeString(),
        ]);

        $active = in_array($status, ['not_started', 'in_progress', 'awaiting_feedback', 'testing'], true);

        // Assignees: 1–2 users. Bias ACTIVE tasks toward the admin so /helpdesk/my-tasks
        // (which only lists non-complete tasks assigned to the caller) has a healthy mix.
        $assignees = collect($staff)->shuffle()->take(rand(1, 2));
        if ($active && $seq % 2 === 0) {
            $assignees = $assignees->push($this->adminId);
        }
        foreach ($assignees->unique() as $uid) {
            DB::table('task_assignees')->insert([
                'tenant_id' => $this->tenantId, 'task_id' => $taskId, 'user_id' => $uid,
                'created_at' => $created->toDateTimeString(), 'updated_at' => $created->toDateTimeString(),
            ]);
        }

        // A follower on every 3rd task.
        if ($seq % 3 === 0) {
            $follower = collect($staff)->firstWhere(fn ($u) => ! $assignees->contains($u)) ?? $staff[0];
            DB::table('task_followers')->insert([
                'tenant_id' => $this->tenantId, 'task_id' => $taskId, 'user_id' => $follower,
                'created_at' => $created->toDateTimeString(), 'updated_at' => $created->toDateTimeString(),
            ]);
        }

        // Checklist on half the tasks: 3–5 items, roughly half finished.
        if ($seq % 2 === 0) {
            $n = rand(3, 5);
            for ($c = 0; $c < $n; $c++) {
                $fin = $c < intdiv($n, 2);
                DB::table('task_checklist_items')->insert([
                    'tenant_id'   => $this->tenantId,
                    'task_id'     => $taskId,
                    'description' => 'Checklist step '.($c + 1),
                    'finished'    => $fin ? 1 : 0,
                    'finished_by' => $fin ? $this->adminId : null,
                    'order'       => $c,
                    'assigned_to' => null,
                    'created_at'  => $created->toDateTimeString(),
                    'updated_at'  => $created->toDateTimeString(),
                ]);
            }
        }

        // Comments on a third of the tasks: 1–3 each.
        if ($seq % 3 === 1) {
            $n = rand(1, 3);
            for ($k = 0; $k < $n; $k++) {
                DB::table('task_comments')->insert([
                    'tenant_id'  => $this->tenantId,
                    'task_id'    => $taskId,
                    'user_id'    => $assignees->first() ?? $this->adminId,
                    'content'    => 'Demo comment '.($k + 1).' on this task.',
                    'created_at' => $created->copy()->addHours($k + 1)->toDateTimeString(),
                    'updated_at' => $created->copy()->addHours($k + 1)->toDateTimeString(),
                ]);
            }
        }

        // Closed timers on in_progress/complete tasks so total-time isn't zero.
        if (in_array($status, ['in_progress', 'complete'], true)) {
            $entries = rand(1, 2);
            for ($e = 0; $e < $entries; $e++) {
                $tStart = $created->copy()->addDays($e)->setTime(rand(9, 15), rand(0, 59));
                $tEnd = $tStart->copy()->addMinutes(rand(35, 190)); // realistic 0.5–3.2h
                DB::table('task_timers')->insert([
                    'tenant_id'   => $this->tenantId,
                    'task_id'     => $taskId,
                    'user_id'     => $assignees->first() ?? $this->adminId,
                    'start_time'  => $tStart->toDateTimeString(),
                    'end_time'    => $tEnd->toDateTimeString(),
                    'hourly_rate' => $rate ?? 0,
                    'note'        => 'Logged work session',
                    'created_at'  => $tStart->toDateTimeString(),
                    'updated_at'  => $tEnd->toDateTimeString(),
                ]);
            }
        }

        return $taskId;
    }

    /* ── Integration (real wired links) ─────────────────────────── */

    private function seedIntegration(array $projectIds, array $staff): void
    {
        // Link 3–4 existing HelpdeskSeeder tickets to 2 seeded projects (project_id).
        $ticketIds = Ticket::where('tenant_id', $this->tenantId)->orderBy('id')->limit(4)->pluck('id')->all();
        $targets = [$projectIds[0], $projectIds[0], $projectIds[2], $projectIds[2]]; // 2 per project
        foreach ($ticketIds as $i => $tid) {
            DB::table('tickets')->where('id', $tid)->update(['project_id' => $targets[$i] ?? $projectIds[0]]);
        }

        // Create 2 rel_type='ticket' tasks pointing at real ticket ids (the same
        // mechanism as create-task-from-ticket), so the ticket→task back-link has
        // more than one example.
        $ticketTaskSources = array_slice($ticketIds, 0, 2);
        foreach ($ticketTaskSources as $j => $tid) {
            $ticket = Ticket::find($tid);
            $this->makeTask([
                'name'     => 'Follow-up on ticket #'.$tid.': '.$ticket->subject,
                'priority' => $ticket->priority,
                'status'   => 'in_progress',
                'rel_type' => 'ticket',
                'rel_id'   => $tid,
                'due_date' => $this->now->copy()->addDays(5 + $j),
            ], $staff);
        }

        $this->command->info('  Integration: linked '.count($ticketIds).' tickets to projects, created '.count($ticketTaskSources).' ticket-linked tasks.');
    }

    /* ── Recompute + report ─────────────────────────────────────── */

    private function recomputeAndReport(array $projectIds): void
    {
        $service = app(ProjectService::class);
        $this->command->info('  Project progress (progress_from_tasks=true, recomputed from tasks):');
        foreach ($projectIds as $id) {
            $p = DB::table('projects')->find($id);
            if (! $p->progress_from_tasks) {
                $this->command->info("    #{$id} {$p->name}: MANUAL {$p->progress}% (progress_from_tasks off)");
                continue;
            }
            $r = $service->progress($id, $this->tenantId);
            $this->command->info("    #{$id} {$p->name}: {$r['progress']}% (source={$r['source']}, {$r['completed_tasks']}/{$r['total_tasks']} tasks)");
        }

        $tasks = DB::table('tasks')->where('tenant_id', $this->tenantId)->count();
        $this->command->info("ProjectTask demo data seeded for tenant #{$this->tenantId}: ".count($projectIds)." projects, {$tasks} tasks.");
        $this->command->warn('  Note: rel_type=customer tasks + project.customer_id use mock roster IDs (1–3) via CustomerServiceContract — schema-ready, not real cross-module integration.');
    }

    /* ── Helpers ────────────────────────────────────────────────── */

    private function taskTitle(int $n): string
    {
        $titles = [
            'Wireframe the homepage', 'Set up CI pipeline', 'Write API contract', 'Design database schema',
            'Build auth flow', 'Integrate payment gateway', 'QA regression pass', 'Prepare launch checklist',
            'Migrate legacy records', 'Draft release notes', 'Optimize slow queries', 'Accessibility audit',
        ];
        return $titles[$n % count($titles)];
    }

    private function shortName(int $projectId, array $projectIds): string
    {
        $names = ['WEB', 'APP', 'ERP', 'MKT', 'TOOL'];
        $idx = array_search($projectId, $projectIds, true);
        return $names[$idx] ?? 'PRJ';
    }
}
