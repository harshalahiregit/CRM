<?php

namespace Tests\Feature\Sire;

use App\Models\Sire\AiSuggestion;
use App\Models\Sire\Report;
use App\Models\Sire\ReportCategory;
use App\Models\Sire\ReportSeverity;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Settings\SettingsService;
use App\Services\Sire\Ai\SireIssueIndexer;
use App\Support\Sire\Ai\AiCapability;
use App\Support\Sire\SireStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Classification and duplicate detection, end to end.
 *
 * The two tests that matter most are the ones asserting what must NOT happen:
 * nothing is marked a duplicate automatically, and issue creation is untouched by
 * any of this.
 */
class SireAiInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;
    private Tenant $b;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Tenant::factory()->create();
        $this->b = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->a->id, 'role' => 'admin']);

        $settings = app(SettingsService::class);
        $settings->set($this->a->id, 'sire.ai.enabled', true);
        $settings->set($this->a->id, 'sire.ai.capabilities', [
            AiCapability::CLASSIFICATION => true,
            AiCapability::DUPLICATE_DETECTION => true,
        ]);
    }

    // ------------------------------------------------- creation is untouched

    public function test_issue_creation_works_with_ai_disabled(): void
    {
        app(SettingsService::class)->set($this->a->id, 'sire.ai.enabled', false);

        Sanctum::actingAs($this->user);
        $this->postJson('/api/sire/reports', [
            'title' => 'Saving a lead fails with a 500',
            'description' => 'The spinner runs and then nothing happens at all.',
        ])->assertCreated();
    }

    public function test_issue_creation_never_calls_the_ai_layer(): void
    {
        /*
         * The strongest form of "AI being unavailable does not break issue
         * creation" is AI not being on that path at all. Creating an issue must
         * produce no suggestion rows and no index writes — insights are requested
         * afterwards, by the client, against an issue that already exists.
         */
        Sanctum::actingAs($this->user);

        $this->postJson('/api/sire/reports', [
            'title' => 'Lead save fails',
            'description' => 'Spinner runs then nothing happens.',
        ])->assertCreated();

        $this->assertDatabaseCount('sire_ai_suggestions', 0);
        $this->assertDatabaseCount('sire_issue_tokens', 0);
    }

    public function test_creation_survives_a_broken_ai_layer(): void
    {
        // Even if every AI class were unusable, creation must not notice.
        $this->mock(\App\Services\Sire\Ai\SireLocalInsights::class, function ($mock) {
            $mock->shouldReceive('classify')->andThrow(new \RuntimeException('AI exploded'));
            $mock->shouldReceive('duplicates')->andThrow(new \RuntimeException('AI exploded'));
        });

        Sanctum::actingAs($this->user);
        $this->postJson('/api/sire/reports', [
            'title' => 'Another issue', 'description' => 'Something went wrong here today.',
        ])->assertCreated();
    }

    // ---------------------------------------------------- duplicate detection

    public function test_a_near_identical_issue_is_offered_as_a_candidate(): void
    {
        $historical = $this->issue([
            'title' => 'Saving a lead fails with a 500',
            'description' => 'The spinner runs and then nothing happens.',
            'status' => SireStatus::CLOSED, 'resolution' => 'fixed',
            'fix_summary' => 'Added the missing null check in the policy.',
        ]);
        app(SireIssueIndexer::class)->index($historical);

        $fresh = $this->issue([
            'title' => 'Lead save fails 500 error',
            'description' => 'Spinner runs and nothing happens.',
        ]);

        Sanctum::actingAs($this->user);
        $data = $this->postJson("/api/sire/ai/reports/{$fresh->id}/insights")->assertOk()->json('data');

        $candidates = $data['duplicates']['payload']['candidates'];
        $this->assertNotEmpty($candidates);
        $this->assertSame($historical->report_number, $candidates[0]['candidate']['report_number']);

        // The previous resolution is the most useful thing on the panel.
        $this->assertSame('fixed', $candidates[0]['candidate']['resolution']);
        $this->assertNotEmpty($candidates[0]['signals']['shared_terms']);
    }

    public function test_nothing_is_ever_marked_a_duplicate_automatically(): void
    {
        $historical = $this->issue(['title' => 'Lead save fails', 'description' => 'Spinner runs forever.']);
        app(SireIssueIndexer::class)->index($historical);
        $fresh = $this->issue(['title' => 'Lead save fails', 'description' => 'Spinner runs forever.']);

        Sanctum::actingAs($this->user);
        $this->postJson("/api/sire/ai/reports/{$fresh->id}/insights")->assertOk();

        // A suggestion exists; the issue is untouched.
        $this->assertDatabaseHas('sire_ai_suggestions', [
            'subject_id' => $fresh->id, 'capability' => AiCapability::DUPLICATE_DETECTION,
        ]);
        $this->assertNull($fresh->fresh()->duplicate_of_id);
        $this->assertNotSame(SireStatus::DUPLICATE, $fresh->fresh()->status);
    }

    public function test_an_issue_is_never_its_own_duplicate(): void
    {
        $issue = $this->issue(['title' => 'Lead save fails', 'description' => 'Spinner runs forever.']);
        app(SireIssueIndexer::class)->index($issue);

        Sanctum::actingAs($this->user);
        $data = $this->postJson("/api/sire/ai/reports/{$issue->id}/insights")->assertOk()->json('data');

        $ids = collect($data['duplicates']['payload']['candidates'] ?? [])->pluck('candidate.id');
        $this->assertFalse($ids->contains($issue->id));
    }

    public function test_the_three_choices_are_recorded_distinctly(): void
    {
        $suggestion = AiSuggestion::factory()->create([
            'tenant_id' => $this->a->id, 'subject_type' => AiCapability::SUBJECT_REPORT,
            'capability' => AiCapability::DUPLICATE_DETECTION, 'status' => AiSuggestion::PENDING,
        ]);

        Sanctum::actingAs($this->user);
        $this->postJson("/api/sire/ai/suggestions/{$suggestion->id}/decide",
            ['decision' => AiSuggestion::DEFERRED, 'note' => 'Asked the module owner.'])->assertOk();

        // "Not sure" is not "no". Collapsing them would record uncertainty as
        // disagreement and poison the feedback signal.
        $this->assertSame(AiSuggestion::DEFERRED, $suggestion->fresh()->status);
        $this->assertNotSame(AiSuggestion::REJECTED, $suggestion->fresh()->status);
    }

    // -------------------------------------------------------- tenant security

    public function test_duplicate_search_never_reaches_another_tenant(): void
    {
        // An identical issue in another tenant. It must be invisible.
        $theirs = Report::factory()->create([
            'tenant_id' => $this->b->id,
            'title' => 'Saving a lead fails with a 500',
            'description' => 'The spinner runs and then nothing happens.',
            'module' => 'sales', 'section' => 'leads', 'screen' => 'lead-details',
        ]);
        app(SireIssueIndexer::class)->index($theirs);

        $mine = $this->issue([
            'title' => 'Saving a lead fails with a 500',
            'description' => 'The spinner runs and then nothing happens.',
        ]);

        Sanctum::actingAs($this->user);
        $data = $this->postJson("/api/sire/ai/reports/{$mine->id}/insights")->assertOk()->json('data');

        $numbers = collect($data['duplicates']['payload']['candidates'] ?? [])->pluck('candidate.report_number');
        $this->assertFalse($numbers->contains($theirs->report_number), 'cross-tenant issue leaked into candidates');
    }

    public function test_the_token_index_is_tenant_partitioned(): void
    {
        $mine = $this->issue(['title' => 'Unique phrase alpha', 'description' => 'beta gamma delta']);
        $theirs = Report::factory()->create([
            'tenant_id' => $this->b->id, 'title' => 'Unique phrase alpha', 'description' => 'beta gamma delta',
        ]);

        app(SireIssueIndexer::class)->index($mine);
        app(SireIssueIndexer::class)->index($theirs);

        $this->assertDatabaseHas('sire_issue_tokens', ['tenant_id' => $this->a->id, 'report_id' => $mine->id]);
        $this->assertDatabaseHas('sire_issue_tokens', ['tenant_id' => $this->b->id, 'report_id' => $theirs->id]);

        // Every row carries its tenant; nothing is shared between them.
        $this->assertSame(0, \App\Models\Sire\IssueToken::whereNull('tenant_id')->count());
    }

    public function test_suggestions_record_the_tenant_and_the_model(): void
    {
        $historical = $this->issue(['title' => 'Lead save fails', 'description' => 'Spinner runs forever.']);
        app(SireIssueIndexer::class)->index($historical);
        $fresh = $this->issue(['title' => 'Lead save fails', 'description' => 'Spinner runs forever.']);

        Sanctum::actingAs($this->user);
        $this->postJson("/api/sire/ai/reports/{$fresh->id}/insights")->assertOk();

        $suggestion = AiSuggestion::where('subject_id', $fresh->id)->firstOrFail();

        // AI logs preserve tenant context.
        $this->assertSame($this->a->id, (int) $suggestion->tenant_id);
        $this->assertSame('local', $suggestion->provider);
        $this->assertNotNull($suggestion->model);
        // Nothing was sent anywhere, and the record says so rather than leaving it
        // blank and ambiguous.
        $this->assertStringContainsString('No data left the tenant', $suggestion->redaction_report['note']);
    }

    // ------------------------------------------------------- classification

    public function test_classification_recommends_from_the_tenants_own_history(): void
    {
        $bug = ReportCategory::factory()->create(['tenant_id' => $this->a->id, 'code' => 'bug', 'name' => 'Bug']);
        $major = ReportSeverity::factory()->create(['tenant_id' => $this->a->id, 'code' => 'major', 'name' => 'Major']);

        foreach (range(1, 5) as $i) {
            $historical = $this->issue([
                'title' => "Lead save fails variant {$i}",
                'description' => 'The spinner runs and then nothing happens.',
                'category_id' => $bug->id, 'severity_id' => $major->id, 'priority' => 'p2',
            ]);
            app(SireIssueIndexer::class)->index($historical);
        }

        $fresh = $this->issue([
            'title' => 'Lead save fails again', 'description' => 'The spinner runs and then nothing happens.',
        ]);

        Sanctum::actingAs($this->user);
        $data = $this->postJson("/api/sire/ai/reports/{$fresh->id}/insights")->assertOk()->json('data');

        $payload = $data['classification']['payload'];
        $this->assertSame('Bug', $payload['category']['value']);
        $this->assertFalse($payload['category']['abstained']);
        // A reason a person can check against the neighbours they are shown.
        $this->assertMatchesRegularExpression('/\d+ of \d+/', $payload['category']['reason']);
    }

    public function test_classification_abstains_rather_than_guessing_from_nothing(): void
    {
        $fresh = $this->issue(['title' => 'Something entirely novel here', 'description' => 'Nothing like this before.']);

        Sanctum::actingAs($this->user);
        $data = $this->postJson("/api/sire/ai/reports/{$fresh->id}/insights")->assertOk()->json('data');

        // No neighbours, no recommendation, no suggestion row. Silence is the
        // correct output, and it is not an error.
        $this->assertNull($data['classification']);
    }

    public function test_accepting_a_classification_does_not_write_it_to_the_issue(): void
    {
        $report = $this->issue(['priority' => null]);
        $suggestion = AiSuggestion::factory()->create([
            'tenant_id' => $this->a->id, 'subject_type' => AiCapability::SUBJECT_REPORT,
            'subject_id' => $report->id, 'capability' => AiCapability::CLASSIFICATION,
            'payload' => ['priority' => ['value' => 'p1', 'confidence' => 0.9]],
        ]);

        Sanctum::actingAs($this->user);
        $response = $this->postJson("/api/sire/ai/suggestions/{$suggestion->id}/decide",
            ['decision' => AiSuggestion::ACCEPTED])->assertOk();

        // The value comes back for the form to pre-fill; the issue is untouched.
        $this->assertNotNull($response->json('data.apply'));
        $this->assertNull($report->fresh()->priority);
    }

    private function issue(array $attributes = []): Report
    {
        return Report::factory()->create(array_merge([
            'tenant_id' => $this->a->id,
            'module' => 'sales', 'section' => 'leads', 'screen' => 'lead-details',
        ], $attributes));
    }
}
