<?php

namespace Tests\Feature\Task;

use App\Models\Task\Task;
use App\Models\Task\TaskChecklistItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Task\TaskTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nesting, the percentage that rolls up through it, and telling the two kinds of
 * work apart.
 *
 * The tree and the roll-up were written but never covered, which for a number
 * that appears on four screens is the same as not knowing whether it is right.
 * What is pinned here:
 *
 *  • nesting actually reaches five levels and refuses to run away;
 *  • ticking something five levels down moves the percentage at the top —
 *    that is the whole point of the feature and nothing else was checking it;
 *  • a parent is not counted as work ON TOP of its own children, which is the
 *    classic way a roll-up quietly reads 50% when everything is done;
 *  • checklist items and subtasks are reported SEPARATELY as well as together,
 *    because "60%" does not tell a person scanning fifty rows whether four
 *    lines are ticked or two subtasks are finished.
 */
class TaskProgressRollUpTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $admin;

    private TaskTreeService $tree;

    /** The status key TaskTreeService treats as "finished". */
    private const DONE = 'complete';

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->admin = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Super Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->tree = app(TaskTreeService::class);
    }

    private function task(string $name, ?Task $parent = null, string $status = 'not_started'): Task
    {
        $task = Task::create([
            'tenant_id' => self::TENANT, 'name' => $name,
            'status' => $status, 'priority' => 'medium',
            'start_date' => now()->toDateString(),        // NOT NULL
            'created_by' => $this->admin->id,
            'parent_id' => $parent?->id,
            'root_id'   => $parent ? ($parent->root_id ?: $parent->id) : null,
            'depth'     => $parent ? (int) $parent->depth + 1 : 0,
        ]);

        // A top-level task is the root of its own one-node tree, so it points at
        // itself. TaskService does this in a second write because the id does
        // not exist until the insert lands; the fixture has to match, or the
        // root is the one node in the tree with no root.
        if (! $parent) {
            $task->forceFill(['root_id' => $task->id])->save();
        }

        return $task;
    }

    private function item(Task $task, string $text, bool $finished = false): TaskChecklistItem
    {
        return TaskChecklistItem::create([
            'tenant_id' => self::TENANT, 'task_id' => $task->id,
            'description' => $text, 'finished' => $finished,
        ]);
    }

    private function percent(Task $task): int
    {
        return $this->tree->progressForTask($task->fresh(), self::TENANT)['percent'];
    }

    /* ── Depth ──────────────────────────────────────────────────── */

    public function test_a_chain_five_levels_deep_is_allowed(): void
    {
        $node = $this->task('L1');
        $chain = [$node];
        for ($i = 2; $i <= 5; $i++) {
            $node = $this->task("L$i", $node);
            $chain[] = $node;
        }

        $this->assertSame([0, 1, 2, 3, 4], array_map(fn (Task $t) => (int) $t->depth, $chain));
        // Every node in the chain carries the SAME root, which is what makes a
        // whole tree one query instead of one query per level.
        $this->assertSame(
            [$chain[0]->id],
            array_values(array_unique(array_map(fn (Task $t) => (int) $t->fresh()->root_id, $chain)))
        );

        // And the tree really does come back nested that far.
        $t = $this->tree->tree($chain[0]->id, self::TENANT);
        $this->assertSame('L5', $t[0]['children'][0]['children'][0]['children'][0]['name']);
    }

    public function test_nesting_stops_before_it_runs_away(): void
    {
        $node = $this->task('root');
        for ($i = 0; $i < TaskTreeService::MAX_DEPTH; $i++) {
            $node = $this->task("level $i", $node);
        }

        // Unbounded nesting is how a bad import produces a chain no UI can draw
        // and no walk can finish, so the cap is a real refusal, not advice.
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/tasks/{$node->id}/subtasks", ['name' => 'one too far'])
            ->assertStatus(422);
    }

    public function test_a_task_cannot_be_moved_under_its_own_subtask(): void
    {
        $parent = $this->task('Website');
        $child = $this->task('Design', $parent);

        Sanctum::actingAs($this->admin);

        // A ring has no root, and every walk over it runs forever.
        $this->patchJson("/api/tasks/{$parent->id}/move", ['parent_id' => $child->id])
            ->assertStatus(422);
        $this->patchJson("/api/tasks/{$parent->id}/move", ['parent_id' => $parent->id])
            ->assertStatus(422);
    }

    /* ── The roll-up ────────────────────────────────────────────── */

    public function test_finishing_the_deepest_subtask_moves_the_percentage_at_the_top(): void
    {
        // Task 1 > Task 2 > Task 3, exactly the chain in the brief.
        $one = $this->task('Task 1');
        $two = $this->task('Task 2', $one);
        $three = $this->task('Task 3', $two);
        $alsoTwo = $this->task('Task 2b', $one);

        $this->assertSame(0, $this->percent($one));

        // Closing Task 3 finishes the only leaf under Task 2 — so Task 2 reads
        // 100%, and Task 1, which has two branches, reads half.
        $three->update(['status' => self::DONE]);

        $this->assertSame(100, $this->percent($two), 'the immediate parent');
        $this->assertSame(50, $this->percent($one), 'and it climbs another level');

        $alsoTwo->update(['status' => self::DONE]);
        $this->assertSame(100, $this->percent($one));
    }

    public function test_a_parent_is_not_counted_on_top_of_its_own_children(): void
    {
        $parent = $this->task('Fit out the unit');
        $a = $this->task('Wiring', $parent);
        $b = $this->task('Plumbing', $parent);

        $a->update(['status' => self::DONE]);
        $b->update(['status' => self::DONE]);

        // The classic roll-up bug: counting the branch node as a unit of work
        // beside the work inside it, so everything done still reads 2/3.
        $this->assertSame(100, $this->percent($parent));
        $this->assertSame(2, $this->tree->progressForTask($parent->fresh(), self::TENANT)['total']);
    }

    public function test_checklist_items_and_subtasks_count_toward_the_same_percentage(): void
    {
        $task = $this->task('Mobilise');
        $this->item($task, 'Book the crane', true);
        $this->item($task, 'Brief the crew');
        $sub = $this->task('Erect the hoarding', $task);

        // Two ticked out of three pieces of work: one checklist item and one
        // open subtask remaining.
        $this->assertSame(33, $this->percent($task));

        $sub->update(['status' => self::DONE]);
        $this->assertSame(67, $this->percent($task));
    }

    public function test_a_task_with_no_work_in_it_is_zero_rather_than_complete(): void
    {
        // An empty total must not divide into 100. "Nothing to do yet" and
        // "everything done" look identical on a bar and mean opposite things.
        $p = $this->tree->progressForTask($this->task('Nothing here yet'), self::TENANT);

        $this->assertSame(0, $p['percent']);
        $this->assertSame(0, $p['total']);
    }

    /* ── Telling the two apart ──────────────────────────────────── */

    public function test_the_two_kinds_of_work_are_reported_separately(): void
    {
        $task = $this->task('Install the gantry');
        $this->item($task, 'Permit', true);
        $this->item($task, 'Method statement', true);
        $this->item($task, 'Lift plan');

        $a = $this->task('Pour the footings', $task);
        $this->task('Set the columns', $task);
        $this->task('Bolt the rails', $task);

        $a->update(['status' => self::DONE]);

        $b = $this->tree->progressForTask($task->fresh(), self::TENANT)['breakdown'];

        // "2/3 items, 1/3 subtasks" — readable at a glance, which one number
        // over the lot never is.
        $this->assertSame(['done' => 2, 'total' => 3], $b['checklist']);
        $this->assertSame(['done' => 1, 'total' => 3], $b['subtasks']);
    }

    public function test_the_subtask_count_is_direct_children_not_the_whole_branch(): void
    {
        $task = $this->task('Task 1');
        $two = $this->task('Task 2', $task);
        $this->task('Task 3', $two);
        $this->task('Task 4', $two);

        $b = $this->tree->progressForTask($task->fresh(), self::TENANT)['breakdown'];

        // "1 of 1 subtasks" is a sentence about THIS task. The three-deep number
        // is what percent already is; saying both in one count says neither.
        $this->assertSame(['done' => 0, 'total' => 1], $b['subtasks']);
    }

    public function test_a_branch_reads_as_done_only_when_everything_in_it_is(): void
    {
        $task = $this->task('Task 1');
        $branch = $this->task('Task 2', $task);
        $leafA = $this->task('Task 3', $branch);
        $leafB = $this->task('Task 4', $branch);

        $leafA->update(['status' => self::DONE]);
        $this->assertSame(
            ['done' => 0, 'total' => 1],
            $this->tree->progressForTask($task->fresh(), self::TENANT)['breakdown']['subtasks'],
            'half a branch is not a finished subtask'
        );

        $leafB->update(['status' => self::DONE]);
        $this->assertSame(
            ['done' => 1, 'total' => 1],
            $this->tree->progressForTask($task->fresh(), self::TENANT)['breakdown']['subtasks'],
        );
    }

    public function test_every_node_in_the_tree_carries_its_own_two_counts(): void
    {
        $task = $this->task('Task 1');
        $two = $this->task('Task 2', $task);
        $this->task('Task 3', $two);
        $this->item($two, 'A check on the middle node', true);

        Sanctum::actingAs($this->admin);
        // The endpoint answers {progress, children} — the bar for the task
        // itself, and the tree under it.
        $body = $this->getJson("/api/tasks/{$task->id}/tree")->assertOk()->json('data');

        // The counts belong to the ROW, at every level — a tab fifty deep in a
        // list still has to say what it is made of without being opened.
        $node = $body['children'][0];
        $this->assertSame('Task 2', $node['name']);
        $this->assertSame(['done' => 1, 'total' => 1], $node['breakdown']['checklist']);
        $this->assertSame(['done' => 0, 'total' => 1], $node['breakdown']['subtasks']);

        $leaf = $node['children'][0];
        $this->assertSame(['done' => 0, 'total' => 0], $leaf['breakdown']['checklist']);
        $this->assertSame(['done' => 0, 'total' => 0], $leaf['breakdown']['subtasks']);
    }
}
