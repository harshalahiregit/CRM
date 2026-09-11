<?php

namespace Tests\Feature\Sire;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Sire\AI\SireLocalInsights;
use Sire\Contracts\SireSettingsProvider;
use Sire\Models\Report;
use Sire\Models\ReportSeverity;
use Sire\Support\Ai\AiCapability;
use Sire\Support\SireStatus;
use Tests\TestCase;

/**
 * AI.md claims all thirteen capabilities are implemented and all run locally.
 *
 * Two of them -- severity and priority recommendation -- were catalogue entries
 * with a context schema and no engine, so the claim was wrong for 2/13 and the
 * only way to notice was to read the code. And /ai/capabilities reported
 * `implemented: []` with a note saying Phase 3 was foundation only, which had
 * been false since SireLocalInsights shipped.
 */
class SireAiCapabilityCoverageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'admin']);

        $settings = app(SireSettingsProvider::class);
        $settings->set($this->tenant->id, 'sire.ai.enabled', true);
        $settings->set($this->tenant->id, 'sire.ai.capabilities', array_fill_keys(AiCapability::ALL, true));
    }

    public function test_every_declared_capability_has_a_local_engine(): void
    {
        $this->assertSame(
            AiCapability::ALL,
            AiCapability::IMPLEMENTED_LOCALLY,
            'a capability in the catalogue with no engine is a panel that never appears',
        );
    }

    public function test_the_capabilities_endpoint_reports_what_is_really_implemented(): void
    {
        Sanctum::actingAs($this->user);
        $data = $this->getJson('/api/sire/ai/capabilities')->assertOk()->json('data');

        $this->assertSame(AiCapability::IMPLEMENTED_LOCALLY, $data['implemented']);
        $this->assertStringNotContainsString('foundation only', $data['note']);
    }

    public function test_severity_and_priority_are_recommended_from_the_tenants_own_history(): void
    {
        $severity = ReportSeverity::factory()->create([
            'tenant_id' => $this->tenant->id, 'code' => 's1', 'name' => 'Critical', 'level' => 4,
        ]);

        // Enough agreeing neighbours for the vote to clear its abstention floor.
        foreach (range(1, 6) as $i) {
            $neighbour = Report::factory()->create([
                'tenant_id'   => $this->tenant->id,
                'title'       => 'Saving a lead fails with a 500 error',
                'description' => 'The spinner runs and then nothing happens at all.',
                'severity_id' => $severity->id,
                'priority'    => 'p1',
                'status'      => SireStatus::CLOSED,
            ]);
            app(\Sire\AI\SireIssueIndexer::class)->index($neighbour);
        }

        $fresh = Report::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'title'       => 'Saving a lead fails with a 500',
            'description' => 'Spinner runs and then nothing happens.',
            'severity_id' => null,
            'priority'    => null,
        ]);

        $local = app(SireLocalInsights::class);
        $actor = new \Sire\Dto\SireUserIdentity(
            id: $this->user->id, tenantId: $this->tenant->id,
            displayName: $this->user->name, role: 'admin',
        );

        $sev = $local->recommendSeverity($fresh, $actor);
        $pri = $local->recommendPriority($fresh, $actor);

        $this->assertNotNull($sev, 'severity recommendation produced nothing');
        $this->assertNotNull($pri, 'priority recommendation produced nothing');
        $this->assertSame(AiCapability::SEVERITY_RECOMMENDATION, $sev->capability);
        $this->assertSame(AiCapability::PRIORITY_RECOMMENDATION, $pri->capability);

        // Suggested, never applied: the issue is untouched until a person acts.
        $this->assertNull($fresh->fresh()->severity_id);
        $this->assertNull($fresh->fresh()->priority);
    }

    public function test_each_has_its_own_flag_and_is_off_when_that_flag_is_off(): void
    {
        // Enabling AI for the module must not switch on a capability nobody asked
        // for -- severity drives the SLA clock.
        app(SireSettingsProvider::class)->set($this->tenant->id, 'sire.ai.capabilities', [
            AiCapability::CLASSIFICATION => true,
        ]);

        $report = Report::factory()->create(['tenant_id' => $this->tenant->id]);
        $actor = new \Sire\Dto\SireUserIdentity(
            id: $this->user->id, tenantId: $this->tenant->id,
            displayName: $this->user->name, role: 'admin',
        );

        $this->assertNull(app(SireLocalInsights::class)->recommendSeverity($report, $actor));
        $this->assertNull(app(SireLocalInsights::class)->recommendPriority($report, $actor));
    }
}
