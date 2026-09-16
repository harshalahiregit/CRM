<?php

namespace Tests\Feature\Sire;

use App\Models\Sire\Report;
use App\Models\Sire\ReportCategory;
use App\Models\Sire\ReportSeverity;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Sire\SirePriority;
use App\Support\Sire\SireStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SIRE dashboard — tenant isolation and filter correctness.
 *
 * TWO tenants, always. Most tests in this codebase set up a single tenant with
 * `private const TENANT = 1`, which cannot detect a leak by construction: if
 * ->forTenant() were removed entirely, a one-tenant test still passes.
 *
 * Assertions are on IDS, never on counts. A count assertion passes when the right
 * number of wrong rows comes back.
 */
class SireDashboardIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create(['name' => 'Alpha']);
        $this->tenantB = Tenant::factory()->create(['name' => 'Beta']);

        $this->userA = User::factory()->create(['tenant_id' => $this->tenantA->id, 'role' => 'admin']);
        $this->userB = User::factory()->create(['tenant_id' => $this->tenantB->id, 'role' => 'admin']);
    }

    // ---------------------------------------------------------------- isolation

    public function test_dashboard_tiles_count_only_the_callers_tenant(): void
    {
        $this->makeIssue($this->tenantA, ['status' => SireStatus::IN_DEVELOPMENT]);
        $this->makeIssue($this->tenantA, ['status' => SireStatus::QA_FAILED]);

        // Ten issues in the other tenant. None may be counted.
        for ($i = 0; $i < 10; $i++) {
            $this->makeIssue($this->tenantB, ['status' => SireStatus::IN_DEVELOPMENT]);
        }

        Sanctum::actingAs($this->userA);
        $tiles = $this->getJson('/api/sire/dashboard')->assertOk()->json('data.tiles');

        $this->assertSame(2, $tiles['open'], 'tenant A has exactly two open issues');
        $this->assertSame(1, $tiles['qa_failed']);
    }

    public function test_register_never_returns_another_tenants_rows(): void
    {
        $mine = $this->makeIssue($this->tenantA, ['status' => SireStatus::IN_DEVELOPMENT]);
        $theirs = $this->makeIssue($this->tenantB, ['status' => SireStatus::IN_DEVELOPMENT]);

        Sanctum::actingAs($this->userA);
        $ids = collect($this->getJson('/api/sire/dashboard/register?scope=open')->assertOk()->json('data.data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id), 'cross-tenant row leaked into the register');
    }

    public function test_filter_options_are_tenant_scoped(): void
    {
        ReportSeverity::factory()->create(['tenant_id' => $this->tenantA->id, 'code' => 'critical', 'name' => 'A-Critical']);
        $theirs = ReportSeverity::factory()->create(['tenant_id' => $this->tenantB->id, 'code' => 'critical', 'name' => 'B-Critical']);

        Sanctum::actingAs($this->userA);
        $options = $this->getJson('/api/sire/dashboard/options')->assertOk()->json('data');

        $this->assertNotContains($theirs->id, collect($options['severities'])->pluck('id')->all());
        $this->assertCount(1, $options['tenants'], 'a user sees exactly one tenant');
        $this->assertSame($this->tenantA->id, $options['tenants'][0]['id']);
    }

    public function test_passing_another_tenants_id_as_a_filter_is_refused_not_ignored(): void
    {
        Sanctum::actingAs($this->userA);

        // Fails CLOSED. If cross-tenant access is ever built, this filter must
        // start working deliberately rather than have been silently ignored.
        $this->getJson("/api/sire/dashboard?tenant_id={$this->tenantB->id}")->assertNotFound();
        $this->getJson("/api/sire/dashboard/register?tenant_id={$this->tenantB->id}")->assertNotFound();

        $this->getJson("/api/sire/dashboard?tenant_id={$this->tenantA->id}")->assertOk();
    }

    public function test_another_tenants_severity_or_assignee_is_rejected_by_validation(): void
    {
        $theirSeverity = ReportSeverity::factory()->create(['tenant_id' => $this->tenantB->id]);

        Sanctum::actingAs($this->userA);
        $this->getJson("/api/sire/dashboard/register?severity_id={$theirSeverity->id}")
            ->assertStatus(422);
        $this->getJson("/api/sire/dashboard/register?assignee_id={$this->userB->id}")
            ->assertStatus(422);
    }

    public function test_portal_roles_cannot_reach_the_dashboard_at_all(): void
    {
        foreach (['client', 'third_party_vendor', 'company'] as $role) {
            $outsider = User::factory()->create(['tenant_id' => $this->tenantA->id, 'role' => $role]);
            Sanctum::actingAs($outsider);
            $this->getJson('/api/sire/dashboard')->assertForbidden();
        }
    }

    public function test_the_dashboard_requires_authentication(): void
    {
        // Regression for the chained-->middleware() trap: a second chained call
        // replaces the first and silently drops auth:sanctum.
        $this->getJson('/api/sire/dashboard')->assertUnauthorized();
        $this->getJson('/api/sire/dashboard/register')->assertUnauthorized();
        $this->getJson('/api/sire/dashboard/options')->assertUnauthorized();
    }

    // ------------------------------------------------------------------ filters

    public function test_each_filter_narrows_to_the_expected_ids(): void
    {
        $sevHigh = ReportSeverity::factory()->create(['tenant_id' => $this->tenantA->id, 'code' => 'critical']);
        $sevLow = ReportSeverity::factory()->create(['tenant_id' => $this->tenantA->id, 'code' => 'minor']);
        $bug = ReportCategory::factory()->create(['tenant_id' => $this->tenantA->id, 'code' => 'bug']);
        $task = ReportCategory::factory()->create(['tenant_id' => $this->tenantA->id, 'code' => 'task']);

        $target = $this->makeIssue($this->tenantA, [
            'status' => SireStatus::IN_DEVELOPMENT, 'priority' => SirePriority::P1,
            'severity_id' => $sevHigh->id, 'category_id' => $bug->id,
            'module' => 'sales', 'assignee_id' => $this->userA->id,
        ]);
        $other = $this->makeIssue($this->tenantA, [
            'status' => SireStatus::TRIAGED, 'priority' => SirePriority::P4,
            'severity_id' => $sevLow->id, 'category_id' => $task->id,
            'module' => 'hr', 'assignee_id' => null,
        ]);

        $cases = [
            'module=sales'                     => [$target->id],
            'type=bug'                         => [$target->id],
            "severity_id={$sevHigh->id}"       => [$target->id],
            'priority=p1'                      => [$target->id],
            'status=in_development'            => [$target->id],
            "assignee_id={$this->userA->id}"   => [$target->id],
            'priority=p1,p4'                   => [$target->id, $other->id],
            'status=in_development,triaged'    => [$target->id, $other->id],
        ];

        Sanctum::actingAs($this->userA);

        foreach ($cases as $query => $expected) {
            $ids = collect($this->getJson("/api/sire/dashboard/register?scope=all&{$query}")
                ->assertOk()->json('data.data'))->pluck('id')->sort()->values()->all();

            $this->assertSame(collect($expected)->sort()->values()->all(), $ids, "filter: {$query}");
        }
    }

    public function test_an_entirely_invalid_multi_value_filter_returns_nothing_rather_than_everything(): void
    {
        $this->makeIssue($this->tenantA, ['status' => SireStatus::IN_DEVELOPMENT]);

        Sanctum::actingAs($this->userA);
        $rows = $this->getJson('/api/sire/dashboard/register?scope=all&priority=nonsense')
            ->assertOk()->json('data.data');

        // whereIn([]) matches nothing in SQL, but an unfiltered fallback would
        // match everything. Narrowing is the safe failure.
        $this->assertSame([], $rows);
    }

    public function test_unknown_query_parameters_are_ignored_not_forwarded(): void
    {
        $issue = $this->makeIssue($this->tenantA, ['status' => SireStatus::IN_DEVELOPMENT]);

        Sanctum::actingAs($this->userA);
        $ids = collect($this->getJson('/api/sire/dashboard/register?scope=open&order_by=password&tenant=99&secret=1')
            ->assertOk()->json('data.data'))->pluck('id')->all();

        $this->assertSame([$issue->id], $ids);
    }

    public function test_date_range_filters_on_creation(): void
    {
        $old = $this->makeIssue($this->tenantA, ['status' => SireStatus::TRIAGED, 'created_at' => now()->subDays(30)]);
        $recent = $this->makeIssue($this->tenantA, ['status' => SireStatus::TRIAGED, 'created_at' => now()->subDay()]);

        Sanctum::actingAs($this->userA);
        $from = now()->subDays(7)->toDateString();

        $ids = collect($this->getJson("/api/sire/dashboard/register?scope=all&date_from={$from}")
            ->assertOk()->json('data.data'))->pluck('id')->all();

        $this->assertContains($recent->id, $ids);
        $this->assertNotContains($old->id, $ids);
    }

    public function test_a_tile_count_matches_the_rows_behind_it(): void
    {
        // The failure this prevents: a tile saying 12 and its drill-down showing 9.
        foreach ([SireStatus::READY_FOR_QA, SireStatus::READY_FOR_QA, SireStatus::QA_FAILED] as $status) {
            $this->makeIssue($this->tenantA, ['status' => $status]);
        }

        Sanctum::actingAs($this->userA);

        $tiles = $this->getJson('/api/sire/dashboard')->assertOk()->json('data.tiles');
        $rows = $this->getJson('/api/sire/dashboard/register?scope=awaiting_qa')->assertOk()->json('data.total');

        $this->assertSame($tiles['awaiting_qa'], $rows, 'tile and drill-down must be the same query');
    }

    private function makeIssue(Tenant $tenant, array $attributes = []): Report
    {
        return Report::factory()->create(array_merge([
            'tenant_id'      => $tenant->id,
            'sla_started_at' => now(),
        ], $attributes));
    }
}
