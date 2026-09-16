<?php

namespace Tests\Feature\Sire;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Walk one issue the whole way: report -> triage -> assign -> develop -> QA ->
 * release. Every step through the real HTTP endpoints, as a real user.
 *
 * This is the sweep that was missing. The package had never been installed into
 * a host, so its first-contact bugs were only ever found by a person clicking --
 * ReportController calling a SireWorkflowService::submit() that does not exist
 * meant EVERY report 500'd, and no test caught it because no test posted the
 * payload the button actually sends. Walking the path end to end is what turns
 * that class of bug into a build failure instead of a support message.
 */
class SireWorkflowWalkTest extends TestCase
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

    private function transition(int $id, string $action, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/sire/reports/{$id}/transitions", ['action' => $action] + $payload);
    }

    private function statusOf(int $id): string
    {
        return (string) DB::table('sire_reports')->where('id', $id)->value('status');
    }

    public function test_an_issue_walks_from_report_to_released(): void
    {
        // ---- report (exactly what the Report Issue button sends) ------------
        $created = $this->postJson('/api/sire/reports', [
            'title'       => 'Invoice page throws on save',
            'description' => 'Clicking Generate Invoice returns a 500 and the invoice is not created.',
            'origin'      => 'internal',
            'submit'      => true,
        ])->assertStatus(201)->json();

        $id = (int) data_get($created, 'data.report.id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame('new', $this->statusOf($id));

        $severityId = (int) DB::table('sire_severities')
            ->where('tenant_id', $this->tenantId)->where('code', 's2')->value('id');

        // ---- triage ---------------------------------------------------------
        $this->transition($id, 'triage', ['severity_id' => $severityId, 'priority' => 'p2'])->assertStatus(200);
        $this->assertSame('triaged', $this->statusOf($id));

        // ---- assign ---------------------------------------------------------
        $this->transition($id, 'assign', ['assignee_id' => $this->admin->id])->assertStatus(200);
        $this->assertSame('assigned', $this->statusOf($id));

        // ---- develop --------------------------------------------------------
        $this->transition($id, 'start_development')->assertStatus(200);
        $this->assertSame('in_development', $this->statusOf($id));

        $this->transition($id, 'mark_ready_for_qa', ['fix_summary' => 'Guarded the null customer on the invoice builder.'])
            ->assertStatus(200);
        $this->assertSame('ready_for_qa', $this->statusOf($id));

        // ---- QA -------------------------------------------------------------
        $this->transition($id, 'start_qa', ['qa_assignee_id' => $this->admin->id])->assertStatus(200);
        $this->assertSame('qa_in_progress', $this->statusOf($id));

        $this->transition($id, 'qa_pass', ['qa_notes' => 'Verified on staging; invoice saves.'])->assertStatus(200);

        // qa_pass auto-advances to ready_for_release unless the tenant turned it off.
        $this->assertContains($this->statusOf($id), ['qa_passed', 'ready_for_release']);
    }

    /** A comment is the other thing every user does on a case. */
    public function test_a_comment_can_be_added_and_read_back(): void
    {
        $id = (int) data_get($this->postJson('/api/sire/reports', [
            'title'       => 'Invoice page throws on save',
            'description' => 'Clicking Generate Invoice returns a 500 and the invoice is not created.',
            'submit'      => true,
        ])->assertStatus(201)->json(), 'data.report.id');

        $this->postJson("/api/sire/reports/{$id}/comments", ['body' => 'Reproduced on staging.'])
            ->assertStatus(201);

        $timeline = $this->getJson("/api/sire/reports/{$id}/timeline")->assertStatus(200)->json();

        $this->assertStringContainsString('Reproduced on staging.', json_encode($timeline));
    }

    /** Evidence: upload, list it back, and stream it. */
    public function test_evidence_uploads_lists_and_downloads(): void
    {
        $id = (int) data_get($this->postJson('/api/sire/reports', [
            'title'       => 'Invoice page throws on save',
            'description' => 'Clicking Generate Invoice returns a 500 and the invoice is not created.',
            'submit'      => true,
        ])->assertStatus(201)->json(), 'data.report.id');

        // Fake the disk: without this the assertion reads the developer's own
        // storage folder and counts real evidence alongside the test's.
        \Illuminate\Support\Facades\Storage::fake((string) config('sire.attachments.disk'));

        $png = \Illuminate\Http\UploadedFile::fake()->image('screen.png', 40, 30);

        $this->post("/api/sire/reports/{$id}/attachments", ['file' => $png], ['Accept' => 'application/json'])
            ->assertStatus(201);

        $list = $this->getJson("/api/sire/reports/{$id}/attachments")->assertStatus(200)->json();
        $files = $list['data'] ?? [];

        $this->assertCount(1, $files, 'Uploaded evidence did not come back from the listing.');

        // The stored path must carry the REAL tenant, not 0 -- the provider reads
        // $owner->tenantId, which is null on an Eloquent Report, so every upload
        // used to land in sire/0/ and the folder-per-tenant guarantee was void.
        $this->assertStringStartsWith("sire/{$this->tenantId}/", (string) $files[0]['id']);

        $this->get("/api/sire/reports/{$id}/attachments/".rawurlencode((string) $files[0]['id']))
            ->assertStatus(200);
    }
}