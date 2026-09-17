<?php

namespace Tests\Feature\Sire;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SIRE — closing an issue in one click.
 *
 * Most defects are small. Walking a typo through assign -> develop -> QA ->
 * release -> validate to reach `closed` is five clicks of ceremony for a one-line
 * fix, and the real cost is that people stop closing things at all: the backlog
 * fills with work that is actually done.
 *
 * `close_directly` is the shortcut. What these tests pin down is the boundary
 * around it -- a shortcut that quietly removed a rule would be worse than the
 * ceremony it replaces.
 */
class SireDirectCloseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'ACME', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $this->tenantId = $tenant->id;

        $this->admin = User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'admin@acme.test',
            'password'  => bcrypt('password'),
            'role'      => 'admin',
        ]);

        $this->artisan('sire:seed-defaults', ['--tenant' => $tenant->id]);
        Sanctum::actingAs($this->admin);
    }

    private function report(): int
    {
        return (int) $this->postJson('/api/sire/reports', [
            'title'       => 'Typo on the invoice footer',
            'description' => 'The footer says "Invocie" instead of "Invoice" on every generated PDF.',
            'origin'      => 'internal',
            'submit'      => true,
        ])->assertStatus(201)->json('data.report.id');
    }

    private function transition(int $id, string $action, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/sire/reports/{$id}/transitions", ['action' => $action] + $payload);
    }

    /** @return array<int, string> */
    private function offered(int $id): array
    {
        return array_column(
            (array) $this->getJson("/api/sire/reports/{$id}")->json('data.available_transitions'),
            'action',
        );
    }

    public function test_a_fresh_issue_offers_close_and_closing_it_ends_the_issue(): void
    {
        $id = $this->report();

        // The whole point: it is there on a brand-new issue, before triage,
        // before an assignee, before anything.
        $this->assertContains('close_directly', $this->offered($id));

        $this->transition($id, 'close_directly', [
            'resolution_note' => 'Fixed in the template; one-word change, nothing to test.',
        ])->assertStatus(200);

        $row = DB::table('sire_reports')->where('id', $id)->first();

        $this->assertSame('closed', $row->status);
        $this->assertNotNull($row->closed_at, 'a closed issue must stop its clock');
        $this->assertStringContainsString('Fixed in the template', (string) $row->resolution_note);
    }

    public function test_it_will_not_close_without_a_reason(): void
    {
        $id = $this->report();

        // An issue that leaves the backlog without a word is one nobody can
        // audit later, and "why is this closed?" is the question a register
        // exists to answer.
        $this->transition($id, 'close_directly')->assertStatus(409);

        $this->assertSame('new', (string) DB::table('sire_reports')->where('id', $id)->value('status'));
    }

    public function test_it_is_offered_from_the_middle_of_the_pipeline_too(): void
    {
        $id = $this->report();

        $this->transition($id, 'triage', [
            'severity_id' => DB::table('sire_severities')->where('tenant_id', $this->tenantId)->value('id'),
            'priority'    => 'p3',
        ])->assertStatus(200);

        $this->transition($id, 'assign', ['assignee_id' => $this->admin->id])->assertStatus(200);
        $this->transition($id, 'start_development')->assertStatus(200);

        $this->assertSame('in_development', (string) DB::table('sire_reports')->where('id', $id)->value('status'));
        $this->assertContains('close_directly', $this->offered($id));

        $this->transition($id, 'close_directly', [
            'resolution_note' => 'Turned out to be a stale cache on the reporter machine.',
        ])->assertStatus(200);

        $this->assertSame('closed', (string) DB::table('sire_reports')->where('id', $id)->value('status'));
    }

    /**
     * THE IMPORTANT ONE.
     *
     * `close` from production_validated demands a confirmed root cause when the
     * issue is serious. If the direct close were offered there as well, that
     * requirement would be one button away from optional -- so it deliberately
     * is not, and this is the test that notices if somebody adds the state back
     * to the `from` list.
     */
    public function test_the_direct_close_cannot_be_used_to_skip_the_root_cause_rule(): void
    {
        $from = \Sire\Support\SireWorkflow::transition('close_directly')['from'];

        $this->assertNotContains(
            \Sire\Support\SireStatus::PRODUCTION_VALIDATED,
            $from,
            'production_validated keeps the guarded close, which is where the RCA rule lives',
        );

        // And the guarded close still owns that state.
        $this->assertContains(
            \Sire\Support\SireStatus::PRODUCTION_VALIDATED,
            \Sire\Support\SireWorkflow::transition('close')['from'],
        );
    }

    public function test_a_closed_issue_stops_offering_it(): void
    {
        $id = $this->report();

        $this->transition($id, 'close_directly', ['resolution_note' => 'Done.'])->assertStatus(200);

        // Terminal means terminal: reopen is the only way back.
        $this->assertNotContains('close_directly', $this->offered($id));
        $this->transition($id, 'close_directly', ['resolution_note' => 'Again.'])->assertStatus(409);
    }
}
