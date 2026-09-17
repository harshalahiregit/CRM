<?php

namespace Tests\Feature\Sire;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Sire\Models\Report;
use Sire\Models\ReportSeverity;
use Sire\Support\SireStatus;
use Tests\TestCase;

/**
 * Taking the backlog out in one piece, and sending the fixes back in one call.
 *
 * The cost of a large backlog is the round trip, not the fixing: forty issues is
 * eighty page loads. The brief removes the trip out; the bulk transition removes
 * the trip back. Neither removes a rule — every entry still runs its own guards.
 */
class SireExportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $lead;
    private ReportSeverity $high;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->lead = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role' => 'admin', 'name' => 'Asha Lead',
        ]);
        $this->high = ReportSeverity::factory()->create([
            'tenant_id' => $this->tenant->id, 'code' => 's2', 'name' => 'High', 'level' => 3,
        ]);

        Sanctum::actingAs($this->lead);
    }

    private function issue(array $attributes = []): Report
    {
        return Report::factory()->create(array_merge([
            'tenant_id'   => $this->tenant->id,
            'severity_id' => $this->high->id,
            'status'      => SireStatus::NEW,
        ], $attributes));
    }

    private function brief(array $params = []): string
    {
        return $this->get('/api/sire/export?'.http_build_query($params))
            ->assertOk()
            ->assertHeader('content-type', 'text/markdown; charset=utf-8')
            ->getContent();
    }

    // ------------------------------------------------------------- the brief

    public function test_it_says_on_its_face_who_it_is_for(): void
    {
        $this->issue(['module' => 'sales', 'screen' => 'lead-details']);

        $md = $this->brief();

        // Somebody who pastes this into a customer thread should have been told
        // before they did, not after.
        $this->assertStringContainsString('**For developers.**', $md);
        $this->assertStringContainsString('not a customer-facing', $md);
    }

    public function test_issues_are_grouped_by_the_screen_they_broke_on(): void
    {
        // Three issues, two screens. The point of the whole document: one fix
        // usually closes several, so they have to arrive together.
        $this->issue(['module' => 'sales', 'section' => 'leads', 'screen' => 'lead-details', 'title' => 'Save fails']);
        $this->issue(['module' => 'sales', 'section' => 'leads', 'screen' => 'lead-details', 'title' => 'Owner resets']);
        $this->issue(['module' => 'inventory', 'section' => 'vouchers', 'screen' => 'voucher-list', 'title' => 'Total wrong']);

        $md = $this->brief();

        $this->assertStringContainsString('## Sales → Leads → Lead details', $md);
        $this->assertStringContainsString('## Inventory → Vouchers → Voucher list', $md);
        $this->assertStringContainsString('2 issues on this screen', $md);
        $this->assertStringContainsString('1 issue.', $md);

        // Both Sales issues sit inside the Sales section, not scattered by date.
        // Modules come out alphabetically, so Inventory leads -- the claim being
        // tested is adjacency, not which module is first.
        // "
## " and not "## ": an issue heading is "### SIR-...", which
        // contains "## " and would cut the section off at its first issue.
        $sales = substr($md, strpos($md, '## Sales'));
        $end   = strpos($sales, "
## ", 1);
        $sales = $end === false ? $sales : substr($sales, 0, $end);

        $this->assertStringContainsString('Save fails', $sales);
        $this->assertStringContainsString('Owner resets', $sales);
        $this->assertStringNotContainsString('Total wrong', $sales);
    }

    public function test_the_failed_api_call_is_carried_through(): void
    {
        $report = $this->issue(['module' => 'sales', 'screen' => 'lead-details']);

        DB::table('sire_report_contexts')->insert([
            'tenant_id'       => $this->tenant->id,
            'report_id'       => $report->id,
            'browser'         => 'Chrome 152',
            'os'              => 'Windows',
            'failed_requests' => json_encode([[
                'method' => 'PUT', 'path' => '/api/sales/leads/10452', 'status' => 500,
            ]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $md = $this->brief();

        // The single most useful line in the document: the one fact a reporter
        // could never have written down themselves.
        $this->assertStringContainsString('PUT /api/sales/leads/10452', $md);
        $this->assertStringContainsString('500', $md);
        $this->assertStringContainsString('Chrome 152', $md);
    }

    public function test_only_the_chosen_modules_come_out(): void
    {
        $this->issue(['module' => 'sales', 'screen' => 'lead-details', 'title' => 'Sales thing']);
        $this->issue(['module' => 'inventory', 'screen' => 'voucher-list', 'title' => 'Inventory thing']);
        $this->issue(['module' => 'hr', 'screen' => 'employee-list', 'title' => 'HR thing']);

        $md = $this->brief(['module' => ['sales', 'inventory']]);

        $this->assertStringContainsString('Sales thing', $md);
        $this->assertStringContainsString('Inventory thing', $md);
        $this->assertStringNotContainsString('HR thing', $md);
    }

    public function test_closed_issues_are_not_work_to_do(): void
    {
        $this->issue(['module' => 'sales', 'screen' => 'lead-details', 'title' => 'Still open']);
        $this->issue([
            'module' => 'sales', 'screen' => 'lead-details',
            'title' => 'Long since fixed', 'status' => SireStatus::CLOSED,
        ]);

        $md = $this->brief(['scope' => 'open']);

        $this->assertStringContainsString('Still open', $md);
        $this->assertStringNotContainsString('Long since fixed', $md);
    }

    public function test_an_empty_backlog_says_so_rather_than_returning_nothing(): void
    {
        $md = $this->brief(['module' => ['nothing-here']]);

        $this->assertStringContainsString('Nothing matches those filters', $md);
    }

    public function test_it_never_crosses_a_tenant(): void
    {
        $other = Tenant::factory()->create();
        Report::factory()->create([
            'tenant_id' => $other->id, 'module' => 'sales',
            'screen' => 'lead-details', 'title' => 'Someone elses defect',
        ]);
        $this->issue(['module' => 'sales', 'screen' => 'lead-details', 'title' => 'Mine']);

        $md = $this->brief();

        $this->assertStringContainsString('Mine', $md);
        $this->assertStringNotContainsString('Someone elses defect', $md);
    }

    public function test_a_customer_login_cannot_take_the_backlog_out(): void
    {
        $client = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role' => 'client',
        ]);

        Sanctum::actingAs($client);
        $this->get('/api/sire/export')->assertForbidden();
    }

    // -------------------------------------------------------- the way back

    public function test_many_issues_move_in_one_call(): void
    {
        $ids = collect(range(1, 3))->map(function () {
            $report = $this->issue(['module' => 'sales', 'screen' => 'lead-details']);

            $this->postJson("/api/sire/reports/{$report->id}/transitions", [
                'action' => 'triage', 'severity_id' => $this->high->id, 'priority' => 'p2',
            ])->assertOk();
            $this->postJson("/api/sire/reports/{$report->id}/transitions", [
                'action' => 'assign', 'assignee_id' => $this->lead->id,
            ])->assertOk();
            $this->postJson("/api/sire/reports/{$report->id}/transitions", [
                'action' => 'start_development',
            ])->assertOk();

            return $report->id;
        });

        $response = $this->postJson('/api/sire/reports/transitions', [
            'transitions' => $ids->map(fn ($id) => [
                'report_id'   => $id,
                'action'      => 'mark_ready_for_qa',
                'fix_summary' => 'Added the missing null check in LeadPolicy.',
            ])->all(),
        ])->assertOk();

        $this->assertSame(3, $response->json('data.applied'));
        $this->assertSame(0, $response->json('data.failed'));

        foreach ($ids as $id) {
            $this->assertSame(SireStatus::READY_FOR_QA, Report::findOrFail($id)->status);
        }
    }

    public function test_one_bad_entry_does_not_roll_back_the_good_ones(): void
    {
        $good = $this->issue(['module' => 'sales', 'screen' => 'lead-details']);
        $this->postJson("/api/sire/reports/{$good->id}/transitions", [
            'action' => 'triage', 'severity_id' => $this->high->id, 'priority' => 'p2',
        ])->assertOk();

        // The second is still NEW, so it cannot be assigned a developer yet.
        $stuck = $this->issue(['module' => 'sales', 'screen' => 'lead-details']);

        $response = $this->postJson('/api/sire/reports/transitions', [
            'transitions' => [
                ['report_id' => $good->id,  'action' => 'assign', 'assignee_id' => $this->lead->id],
                ['report_id' => $stuck->id, 'action' => 'mark_ready_for_qa', 'fix_summary' => 'x'],
            ],
        ])->assertOk();

        // One worked, one said why. Nineteen good fixes must not be lost to one
        // issue somebody else already moved.
        $this->assertSame(1, $response->json('data.applied'));
        $this->assertSame(1, $response->json('data.failed'));
        $this->assertNotNull($response->json('data.results.1.error'));
    }

    public function test_the_guards_still_apply_in_bulk(): void
    {
        $report = $this->issue(['module' => 'sales', 'screen' => 'lead-details']);
        foreach ([
            ['action' => 'triage', 'severity_id' => $this->high->id, 'priority' => 'p2'],
            ['action' => 'assign', 'assignee_id' => $this->lead->id],
            ['action' => 'start_development'],
        ] as $step) {
            $this->postJson("/api/sire/reports/{$report->id}/transitions", $step)->assertOk();
        }

        // mark_ready_for_qa REQUIRES a fix summary, in bulk exactly as it does
        // one at a time. This is a way to avoid page loads, not a way around the
        // workflow.
        $response = $this->postJson('/api/sire/reports/transitions', [
            'transitions' => [['report_id' => $report->id, 'action' => 'mark_ready_for_qa']],
        ])->assertOk();

        $this->assertSame(0, $response->json('data.applied'));
        $this->assertStringContainsString('fix summary', $response->json('data.results.0.error'));
        $this->assertSame(SireStatus::IN_DEVELOPMENT, $report->fresh()->status);
    }

    public function test_another_tenants_issue_is_not_found_in_bulk_either(): void
    {
        $theirs = Report::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'status'    => SireStatus::NEW,
        ]);

        $response = $this->postJson('/api/sire/reports/transitions', [
            'transitions' => [['report_id' => $theirs->id, 'action' => 'triage']],
        ])->assertOk();

        $this->assertSame(1, $response->json('data.failed'));
        $this->assertStringContainsString('Not found', $response->json('data.results.0.error'));
        $this->assertSame(SireStatus::NEW, $theirs->fresh()->status);
    }

    public function test_the_batch_is_capped(): void
    {
        $this->postJson('/api/sire/reports/transitions', [
            'transitions' => collect(range(1, 101))
                ->map(fn ($i) => ['report_id' => $i, 'action' => 'triage'])->all(),
        ])->assertStatus(422);
    }
}
