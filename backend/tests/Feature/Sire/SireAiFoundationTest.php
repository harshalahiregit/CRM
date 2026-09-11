<?php

namespace Tests\Feature\Sire;

use Sire\Models\AiSuggestion;
use Sire\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use Sire\Contracts\SireSettingsProvider;
use Sire\Support\Ai\AiCapability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 3 — the AI foundation.
 *
 * These tests assert what the foundation GUARANTEES, not what AI does. Nothing
 * here exercises an analysis, because none is implemented; everything here
 * exercises the promises the architecture makes.
 */
class SireAiFoundationTest extends TestCase
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
    }

    // ------------------------------------------------------ optional by default

    public function test_ai_is_off_by_default_and_reports_itself_as_off(): void
    {
        Sanctum::actingAs($this->user);
        $status = $this->getJson('/api/sire/ai/status')->assertOk()->json('data');

        $this->assertFalse($status['enabled'], 'opting a tenant in must be a decision');
        $this->assertSame('null', $status['provider']['provider']);
        $this->assertFalse($status['provider_configured']);
    }

    public function test_requesting_a_suggestion_degrades_instead_of_failing(): void
    {
        $report = Report::factory()->create(['tenant_id' => $this->a->id]);

        Sanctum::actingAs($this->user);
        $result = $this->postJson("/api/sire/ai/reports/{$report->id}/suggest",
            ['capability' => AiCapability::SEVERITY_RECOMMENDATION])->assertOk()->json('data');

        // A 200 carrying `unavailable`, not a 500. SIRE must work when AI does not.
        $this->assertSame('unavailable', $result['status']);
        $this->assertNull($result['payload']);
        $this->assertDatabaseCount('sire_ai_suggestions', 0);
    }

    public function test_enabling_ai_without_enabling_a_capability_still_declines(): void
    {
        app(SireSettingsProvider::class)->set($this->a->id, 'sire.ai.enabled', true);

        Sanctum::actingAs($this->user);
        $report = Report::factory()->create(['tenant_id' => $this->a->id]);

        // A capability shipped later must not switch itself on for every tenant
        // that had enabled AI for something else.
        $result = $this->postJson("/api/sire/ai/reports/{$report->id}/suggest",
            ['capability' => AiCapability::CLASSIFICATION])->assertOk()->json('data');

        $this->assertSame('unavailable', $result['status']);
    }

    public function test_the_whole_issue_workflow_functions_with_ai_absent(): void
    {
        // The load-bearing claim of the phase: SIRE does not need AI to work.
        $report = Report::factory()->create(['tenant_id' => $this->a->id]);

        Sanctum::actingAs($this->user);
        $this->getJson("/api/sire/reports/{$report->id}")->assertOk();
        $this->getJson("/api/sire/reports/{$report->id}/timeline")->assertOk();
        $this->getJson('/api/sire/dashboard')->assertOk();
        $this->getJson('/api/sire/release-board')->assertOk();
    }

    // ------------------------------------------------------------- guarantees

    public function test_a_suggestion_never_alters_the_subject(): void
    {
        $report = Report::factory()->create([
            'tenant_id' => $this->a->id, 'severity_id' => null, 'priority' => null,
        ]);

        $suggestion = AiSuggestion::factory()->create([
            'tenant_id'    => $this->a->id,
            'subject_type' => AiCapability::SUBJECT_REPORT,
            'subject_id'   => $report->id,
            'capability'   => AiCapability::PRIORITY_RECOMMENDATION,
            'payload'      => ['priority' => 'p1'],
            'status'       => AiSuggestion::PENDING,
        ]);

        Sanctum::actingAs($this->user);
        $this->postJson("/api/sire/ai/suggestions/{$suggestion->id}/decide",
            ['decision' => AiSuggestion::ACCEPTED])->assertOk();

        // Accepting records a decision. It does not write to the issue — the human
        // applies it through the ordinary endpoint, as themselves.
        $this->assertNull($report->fresh()->priority);
        $this->assertSame(AiSuggestion::ACCEPTED, $suggestion->fresh()->status);
        $this->assertSame((int) $this->user->id, (int) $suggestion->fresh()->decided_by);
    }

    public function test_modify_records_what_the_human_actually_chose(): void
    {
        $report = Report::factory()->create(['tenant_id' => $this->a->id]);
        $suggestion = AiSuggestion::factory()->create([
            'tenant_id' => $this->a->id, 'subject_type' => AiCapability::SUBJECT_REPORT,
            'subject_id' => $report->id, 'payload' => ['priority' => 'p1'],
        ]);

        Sanctum::actingAs($this->user);
        $this->postJson("/api/sire/ai/suggestions/{$suggestion->id}/decide", [
            'decision' => AiSuggestion::MODIFIED, 'final_value' => ['priority' => 'p3'],
        ])->assertOk();

        $fresh = $suggestion->fresh();
        // "Nearly right" is a different signal from right and from wrong.
        $this->assertSame(AiSuggestion::MODIFIED, $fresh->status);
        $this->assertSame(['priority' => 'p1'], $fresh->payload);
        $this->assertSame(['priority' => 'p3'], $fresh->final_value);
    }

    public function test_a_decision_is_audited_as_an_ai_event_not_a_workflow_event(): void
    {
        $report = Report::factory()->create(['tenant_id' => $this->a->id]);
        $suggestion = AiSuggestion::factory()->create([
            'tenant_id' => $this->a->id, 'subject_type' => AiCapability::SUBJECT_REPORT,
            'subject_id' => $report->id,
        ]);

        Sanctum::actingAs($this->user);
        $this->postJson("/api/sire/ai/suggestions/{$suggestion->id}/decide",
            ['decision' => AiSuggestion::REJECTED])->assertOk();

        // The trail is wherever the bound SireAuditProvider puts it. This install
        // runs SIRE's own provider, so that is sire_audit_events; bind a host
        // provider and the same assertion moves with it.
        $log = \Sire\Models\AuditEvent::query()
            ->where('subject_id', $suggestion->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        // Requirement 4: a reader must be able to tell a machine's proposal from a
        // person's decision without knowing the schema.
        $this->assertSame('ai', data_get($log->metadata, 'source'));
        $this->assertSame('ai_decision', data_get($log->metadata, 'action'));
    }

    public function test_a_decided_suggestion_cannot_be_decided_again(): void
    {
        $suggestion = AiSuggestion::factory()->create([
            'tenant_id' => $this->a->id, 'status' => AiSuggestion::REJECTED,
        ]);

        Sanctum::actingAs($this->user);
        $this->postJson("/api/sire/ai/suggestions/{$suggestion->id}/decide",
            ['decision' => AiSuggestion::ACCEPTED])->assertStatus(409);
    }

    // -------------------------------------------------------------- isolation

    public function test_suggestions_are_tenant_isolated(): void
    {
        $theirReport = Report::factory()->create(['tenant_id' => $this->b->id]);
        $theirSuggestion = AiSuggestion::factory()->create([
            'tenant_id' => $this->b->id, 'subject_type' => AiCapability::SUBJECT_REPORT,
            'subject_id' => $theirReport->id,
        ]);

        Sanctum::actingAs($this->user);

        $this->getJson("/api/sire/ai/reports/{$theirReport->id}/suggestions")->assertNotFound();
        $this->postJson("/api/sire/ai/reports/{$theirReport->id}/suggest",
            ['capability' => AiCapability::CLASSIFICATION])->assertNotFound();
        $this->postJson("/api/sire/ai/suggestions/{$theirSuggestion->id}/decide",
            ['decision' => AiSuggestion::ACCEPTED])->assertNotFound();

        $this->assertSame(AiSuggestion::PENDING, $theirSuggestion->fresh()->status);
    }

    public function test_ai_endpoints_require_authentication_and_refuse_portal_roles(): void
    {
        $this->getJson('/api/sire/ai/status')->assertUnauthorized();

        foreach (['client', 'third_party_vendor', 'company'] as $role) {
            Sanctum::actingAs(User::factory()->create(['tenant_id' => $this->a->id, 'role' => $role]));
            $this->getJson('/api/sire/ai/status')->assertForbidden();
        }
    }

    public function test_the_capability_catalogue_states_what_is_really_implemented(): void
    {
        /*
         * This test used to assert `implemented === []`, which was correct when
         * Phase 3 was foundation only and wrong from the moment SireLocalInsights
         * shipped. Both the endpoint and the assertion said nothing worked while
         * thirteen engines sat behind them.
         *
         * The claim worth guarding is not a number but a relationship: the
         * endpoint must report what the code implements, so it cannot go stale
         * again the way the empty array did.
         */
        Sanctum::actingAs($this->user);
        $data = $this->getJson('/api/sire/ai/capabilities')->assertOk()->json('data');

        $this->assertCount(13, $data['capabilities'], 'all thirteen declared');
        $this->assertSame(
            \Sire\Support\Ai\AiCapability::IMPLEMENTED_LOCALLY,
            $data['implemented'],
            'the endpoint must report what the code implements',
        );
    }
}
