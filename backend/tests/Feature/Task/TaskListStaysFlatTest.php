<?php

namespace Tests\Feature\Task;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Project\Project;
use App\Models\Task\Task;
use App\Models\Task\TaskRelation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Task\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The task list must cost the same whether it returns five rows or fifty.
 *
 * It did not. decorateMany() batched projects, tickets and vendors and then
 * handed CUSTOMER rows straight back to the single-row path, which fetched the
 * client, its primary contact and that task's additional relations one row at a
 * time. A dozen customer tasks was forty extra queries, and the board felt it.
 *
 * An N+1 is invisible in every test that asserts on the RESULT — the answer was
 * always correct, just expensive — so it is asserted on the query count instead.
 * The bound is deliberately loose: this is a guard against per-row growth, not a
 * budget anybody should tune against.
 */
class TaskListStaysFlatTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $admin;

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
    }

    /** A client with a primary contact — the shape that used to cost two queries a row. */
    private function client(string $company): Client
    {
        $client = Client::create([
            'tenant_id' => self::TENANT, 'company' => $company,
            'email' => 'ap-'.Str::random(4).'@'.Str::slug($company).'.local',
        ]);
        ClientContact::create([
            'tenant_id' => self::TENANT, 'client_id' => $client->id,
            'first_name' => 'Primary', 'last_name' => 'Contact',
            'email' => 'pc-'.Str::random(4).'@'.Str::slug($company).'.local',
            'is_primary' => true, 'active' => true,
        ]);

        return $client;
    }

    /**
     * $n tasks, each linked to a customer and carrying an extra relation — the
     * worst case for the old code.
     */
    private function seedTasks(int $n): void
    {
        $client = $this->client('Northwind Traders');
        $project = Project::create([
            'tenant_id' => self::TENANT, 'name' => 'Fit-out',
            'start_date' => now()->toDateString(), 'created_by' => $this->admin->id,
        ]);

        for ($i = 0; $i < $n; $i++) {
            $task = Task::create([
                'tenant_id' => self::TENANT, 'name' => "Task $i",
                'status' => 'not_started', 'priority' => 'medium',
                'start_date' => now()->toDateString(),
                'created_by' => $this->admin->id,
                'rel_type' => 'customer', 'rel_id' => $client->id,
                'is_public' => true,
            ]);
            $task->forceFill(['root_id' => $task->id])->save();

            TaskRelation::create([
                'tenant_id' => self::TENANT, 'task_id' => $task->id,
                'rel_type' => 'project', 'rel_id' => $project->id,
            ]);
        }
    }

    /** Queries fired while running $fn. */
    private function countQueries(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    public function test_listing_forty_tasks_costs_no_more_than_listing_four(): void
    {
        $svc = fn () => app(TaskService::class)->list(self::TENANT, [], $this->admin->id, true);

        $this->seedTasks(4);
        $small = $this->countQueries($svc);

        $this->seedTasks(36);
        $large = $this->countQueries($svc);

        // Not "equal": the schema probes are memoised per INSTANCE and each call
        // resolves a fresh one, so both runs pay the same fixed cost — what must
        // not happen is that cost growing with the rows. A couple of queries of
        // slack absorbs an extra rel_type appearing on the bigger page.
        $this->assertLessThanOrEqual(
            $small + 2,
            $large,
            "listing 40 tasks fired $large queries where 4 fired $small — that is a per-row query"
        );
    }

    public function test_every_row_still_carries_its_label_and_relations(): void
    {
        // The cheap way to pass the test above is to stop resolving anything, so
        // the batched path is checked for the answers it used to give.
        $this->seedTasks(6);

        $rows = app(TaskService::class)->list(self::TENANT, [], $this->admin->id, true);

        $this->assertCount(6, $rows);
        foreach ($rows as $row) {
            $this->assertSame('Northwind Traders', $row->rel_label);
            $this->assertSame('Northwind Traders', $row->customer['company'] ?? null);
            $this->assertCount(1, $row->relations);
            $this->assertSame('Fit-out', $row->relations[0]['label']);
            $this->assertSame('project', $row->relations[0]['rel_type']);
        }
    }

    public function test_a_link_whose_target_is_gone_still_reads_as_something(): void
    {
        $task = Task::create([
            'tenant_id' => self::TENANT, 'name' => 'Orphaned link',
            'status' => 'not_started', 'priority' => 'medium',
            'start_date' => now()->toDateString(), 'created_by' => $this->admin->id,
            'rel_type' => 'project', 'rel_id' => 9999, 'is_public' => true,
        ]);
        $task->forceFill(['root_id' => $task->id])->save();

        $row = app(TaskService::class)->list(self::TENANT, [], $this->admin->id, true)->first();

        // "#9999" beside a task is a dangling link; "Project #9999" at least says
        // what it was pointing at.
        $this->assertSame('Project #9999', $row->rel_label);

        // And it is FLAGGED. Deleting a project, vendor or ticket does not touch
        // the tasks pointing at it, so this happens in ordinary use — it used to
        // render exactly like a live link, "open" button and all, and that button
        // went to a page with nothing on it. There is no url to a record that is
        // not there.
        $this->assertTrue($row->rel_missing);
        $this->assertNull($row->rel_url);
    }

    public function test_the_modal_and_the_board_agree_that_a_link_is_dead(): void
    {
        $task = Task::create([
            'tenant_id' => self::TENANT, 'name' => 'Orphaned link',
            'status' => 'not_started', 'priority' => 'medium',
            'start_date' => now()->toDateString(), 'created_by' => $this->admin->id,
            'rel_type' => 'tpv_vendor', 'rel_id' => 4242, 'is_public' => true,
        ]);
        $task->forceFill(['root_id' => $task->id])->save();

        $svc = app(TaskService::class);
        $listed = $svc->list(self::TENANT, [], $this->admin->id, true)->first();
        $shown = $svc->show($task->id, self::TENANT);

        // These were two code paths answering the same question differently, and
        // only one of them knew the target was gone.
        $this->assertSame($listed->rel_label, $shown->rel_label);
        $this->assertTrue($listed->rel_missing);
        $this->assertTrue($shown->rel_missing);
    }

    public function test_a_live_link_is_not_flagged_as_missing(): void
    {
        // The cheap way to pass the tests above is to flag everything.
        $this->seedTasks(1);

        $row = app(TaskService::class)->list(self::TENANT, [], $this->admin->id, true)->first();

        $this->assertFalse($row->rel_missing);
        $this->assertSame('Northwind Traders', $row->rel_label);
    }

    public function test_the_single_task_view_and_the_list_agree_about_relations(): void
    {
        $this->seedTasks(1);
        $svc = app(TaskService::class);

        $listed = $svc->list(self::TENANT, [], $this->admin->id, true)->first();
        $shown = $svc->show($listed->id, self::TENANT);

        // relationsFor() now goes through the batched path, so there is one
        // definition of a decorated relation rather than two that drifted — the
        // old per-row code resolved a customer through the directory service and
        // everything else through resolveRelLabel(), and the two disagreed about
        // the fallback label.
        $this->assertEquals($listed->relations, $shown->relations);
    }
}
