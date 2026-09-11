<?php

namespace Tests\Feature\Sire;

use Sire\Models\Release;
use Sire\Models\ReleaseOverride;
use Sire\Models\Report;
use Sire\Models\ReportSeverity;
use App\Models\Tenant;
use App\Models\User;
use Sire\Support\SireReleaseStatus;
use Sire\Support\SireStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 3 — release governance.
 *
 * The tests that matter most here are the ones asserting a gate CANNOT be
 * bypassed by accident: an ungated path to "released" would make the whole
 * feature advisory without anyone noticing.
 */
class SireReleaseGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;
    private Tenant $b;
    private User $lead;
    private User $otherTenantLead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Tenant::factory()->create();
        $this->b = Tenant::factory()->create();
        $this->lead = User::factory()->create(['tenant_id' => $this->a->id, 'role' => 'admin']);
        $this->otherTenantLead = User::factory()->create(['tenant_id' => $this->b->id, 'role' => 'admin']);
    }

    // ------------------------------------------------------------------ gates

    public function test_a_release_with_an_open_critical_issue_is_blocked(): void
    {
        $release = $this->release();
        $critical = ReportSeverity::factory()->create(['tenant_id' => $this->a->id, 'code' => 'critical']);

        Report::factory()->create([
            'tenant_id' => $this->a->id, 'fixed_version_id' => $release->id,
            'severity_id' => $critical->id, 'status' => SireStatus::IN_DEVELOPMENT,
        ]);

        Sanctum::actingAs($this->lead);
        $gates = $this->getJson("/api/sire/releases/{$release->id}/governance")->assertOk()->json('data.gates');

        $this->assertSame('blocked', $gates['status']);
        $this->assertSame('fail', collect($gates['gates'])->firstWhere('key', 'no_open_critical')['status']);
        $this->assertSame(SireReleaseStatus::BLOCKED, $release->fresh()->status);
    }

    public function test_a_blocked_release_cannot_be_approved_or_shipped(): void
    {
        $release = $this->release();
        Report::factory()->create([
            'tenant_id' => $this->a->id, 'fixed_version_id' => $release->id,
            'status' => SireStatus::QA_FAILED,
        ]);

        Sanctum::actingAs($this->lead);
        $this->postJson("/api/sire/releases/{$release->id}/gates/evaluate")->assertOk();

        $this->postJson("/api/sire/releases/{$release->id}/transitions", ['action' => 'approve'])->assertStatus(409);
        $this->postJson("/api/sire/releases/{$release->id}/transitions", ['action' => 'release'])->assertStatus(409);

        $this->assertSame(SireReleaseStatus::BLOCKED, $release->fresh()->status);
    }

    public function test_there_is_no_ungated_path_to_released(): void
    {
        // The Phase 2 endpoints were removed precisely so that shipping cannot
        // route around the gates. If they come back, this fails.
        $release = $this->release();

        Sanctum::actingAs($this->lead);
        // 404 or 405: this host mounts a GET catch-all for its SPA, so an
        // unrouted POST is refused as a wrong method rather than a missing path.
        // Either way there is no handler, which is the whole claim.
        foreach (["/api/sire/releases/{$release->id}/release",
                  "/api/sire/releases/{$release->id}/roll-back"] as $uri) {
            $this->assertContains(
                $this->postJson($uri, ['reason' => 'x'])->getStatusCode(),
                [404, 405],
                "{$uri} is routable",
            );
        }
    }

    public function test_status_cannot_be_set_directly_from_a_payload(): void
    {
        $release = $this->release();

        Sanctum::actingAs($this->lead);
        $this->postJson("/api/sire/releases/{$release->id}/transitions", [
            'action' => 'cancel', 'reason' => 'stop', 'status' => SireReleaseStatus::RELEASED,
        ])->assertOk();

        // The action decided the status; the smuggled field did nothing.
        $this->assertSame(SireReleaseStatus::CANCELLED, $release->fresh()->status);
    }

    public function test_a_clean_release_goes_ready_then_approved_then_released(): void
    {
        $release = $this->release();
        Report::factory()->create([
            'tenant_id' => $this->a->id, 'fixed_version_id' => $release->id,
            'status' => SireStatus::READY_FOR_RELEASE,
        ]);

        Sanctum::actingAs($this->lead);
        $this->postJson("/api/sire/releases/{$release->id}/gates/evaluate")->assertOk();
        $this->assertSame(SireReleaseStatus::READY, $release->fresh()->status);

        $this->postJson("/api/sire/releases/{$release->id}/transitions", ['action' => 'approve'])->assertOk();
        $this->assertSame(SireReleaseStatus::APPROVED, $release->fresh()->status);
        $this->assertNotNull($release->fresh()->approved_at);

        $this->postJson("/api/sire/releases/{$release->id}/transitions", ['action' => 'release'])->assertOk();
        $this->assertSame(SireReleaseStatus::RELEASED, $release->fresh()->status);
    }

    public function test_approval_is_rechecked_against_live_gates_not_the_cache(): void
    {
        $release = $this->release();
        $critical = ReportSeverity::factory()->create(['tenant_id' => $this->a->id, 'code' => 'critical']);

        Sanctum::actingAs($this->lead);
        $this->postJson("/api/sire/releases/{$release->id}/gates/evaluate")->assertOk();
        $this->assertSame(SireReleaseStatus::READY, $release->fresh()->status);

        // Somebody reopens a critical issue between the dashboard rendering and
        // the button being pressed. Approving on the stale reading is exactly the
        // failure a gate engine exists to prevent.
        Report::factory()->create([
            'tenant_id' => $this->a->id, 'fixed_version_id' => $release->id,
            'severity_id' => $critical->id, 'status' => SireStatus::IN_DEVELOPMENT,
        ]);

        $this->postJson("/api/sire/releases/{$release->id}/transitions", ['action' => 'approve'])->assertStatus(409);
        $this->assertNotSame(SireReleaseStatus::APPROVED, $release->fresh()->status);
    }

    // -------------------------------------------------------------- overrides

    public function test_an_override_unblocks_only_the_gates_it_names(): void
    {
        $release = $this->release();
        $critical = ReportSeverity::factory()->create(['tenant_id' => $this->a->id, 'code' => 'critical']);

        Report::factory()->create([
            'tenant_id' => $this->a->id, 'fixed_version_id' => $release->id,
            'severity_id' => $critical->id, 'status' => SireStatus::IN_DEVELOPMENT,
        ]);
        Report::factory()->create([
            'tenant_id' => $this->a->id, 'fixed_version_id' => $release->id,
            'status' => SireStatus::QA_FAILED,
        ]);

        Sanctum::actingAs($this->lead);

        $this->postJson("/api/sire/releases/{$release->id}/override", [
            'gates' => ['no_open_critical'],
            'reason' => 'hotfix',
            'justification' => 'Customer-blocking outage; the critical is cosmetic and tracked separately.',
        ])->assertCreated();

        // Still blocked — the QA gate was not named.
        $this->assertSame(SireReleaseStatus::BLOCKED, $release->fresh()->status);

        $this->postJson("/api/sire/releases/{$release->id}/override", [
            'gates' => ['no_open_critical', 'qa_failures_resolved'],
            'reason' => 'hotfix',
            'justification' => 'Both accepted by the release board; see the incident channel for detail.',
        ])->assertCreated();

        $this->assertSame(SireReleaseStatus::READY, $release->fresh()->status);
    }

    public function test_every_override_is_audited_and_recorded_with_its_gate_snapshot(): void
    {
        $release = $this->release();
        Report::factory()->create([
            'tenant_id' => $this->a->id, 'fixed_version_id' => $release->id, 'status' => SireStatus::QA_FAILED,
        ]);

        Sanctum::actingAs($this->lead);
        $this->postJson("/api/sire/releases/{$release->id}/override", [
            'gates' => ['qa_failures_resolved'],
            'reason' => 'regulatory',
            'justification' => 'Regulatory filing deadline is tomorrow; QA failure is in a disabled module.',
        ])->assertCreated();

        $override = ReleaseOverride::where('release_id', $release->id)->firstOrFail();

        $this->assertSame((int) $this->lead->id, (int) $override->authorized_by);
        $this->assertNotNull($override->authorized_at);
        $this->assertSame(['qa_failures_resolved'], $override->overridden_gates);
        // The snapshot is the point: "we overrode the QA gate" is worth little
        // next to "we overrode it while one issue sat in QA Failed".
        $this->assertNotEmpty($override->gate_snapshot['gates']);
        $this->assertSame('blocked', $override->gate_snapshot['status']);

        // sire_audit_events is where the bound provider writes in this install.
        $this->assertDatabaseHas('sire_audit_events', [
            'subject_id' => $release->id,
            'action'     => 'Emergency release override authorised',
        ]);
    }

    public function test_an_override_requires_a_written_justification_and_named_gates(): void
    {
        $release = $this->release();
        Sanctum::actingAs($this->lead);

        $this->postJson("/api/sire/releases/{$release->id}/override",
            ['gates' => [], 'reason' => 'hotfix', 'justification' => 'a proper justification here'])
            ->assertStatus(422);

        $this->postJson("/api/sire/releases/{$release->id}/override",
            ['gates' => ['no_open_critical'], 'reason' => 'hotfix', 'justification' => 'too short'])
            ->assertStatus(422);

        $this->assertDatabaseCount('sire_release_overrides', 0);
    }

    public function test_a_second_override_supersedes_the_first_rather_than_stacking(): void
    {
        $release = $this->release();
        Report::factory()->create([
            'tenant_id' => $this->a->id, 'fixed_version_id' => $release->id, 'status' => SireStatus::QA_FAILED,
        ]);

        Sanctum::actingAs($this->lead);

        foreach (['First authorisation, recorded at the time.', 'Second authorisation after review.'] as $text) {
            $this->postJson("/api/sire/releases/{$release->id}/override", [
                'gates' => ['qa_failures_resolved'], 'reason' => 'hotfix', 'justification' => $text,
            ])->assertCreated();
        }

        // Both kept for the record; only one active, so "which override let this
        // through" has exactly one answer.
        $this->assertDatabaseCount('sire_release_overrides', 2);
        $this->assertSame(1, ReleaseOverride::where('release_id', $release->id)->whereNull('revoked_at')->count());
    }

    public function test_cancelling_a_release_spends_its_override(): void
    {
        $release = $this->release();
        Report::factory()->create([
            'tenant_id' => $this->a->id, 'fixed_version_id' => $release->id, 'status' => SireStatus::QA_FAILED,
        ]);

        Sanctum::actingAs($this->lead);
        $this->postJson("/api/sire/releases/{$release->id}/override", [
            'gates' => ['qa_failures_resolved'], 'reason' => 'other', 'justification' => 'Recorded for the audit trail.',
        ])->assertCreated();

        $this->postJson("/api/sire/releases/{$release->id}/transitions",
            ['action' => 'cancel', 'reason' => 'Descoped'])->assertOk();

        $this->assertSame(0, ReleaseOverride::where('release_id', $release->id)->whereNull('revoked_at')->count());
    }

    // ------------------------------------------------------------- isolation

    public function test_governance_endpoints_refuse_another_tenants_release(): void
    {
        $theirs = Release::factory()->create(['tenant_id' => $this->b->id]);

        Sanctum::actingAs($this->lead);

        $this->getJson("/api/sire/releases/{$theirs->id}/governance")->assertNotFound();
        $this->postJson("/api/sire/releases/{$theirs->id}/gates/evaluate")->assertNotFound();
        $this->postJson("/api/sire/releases/{$theirs->id}/transitions", ['action' => 'approve'])->assertNotFound();
        $this->postJson("/api/sire/releases/{$theirs->id}/override", [
            'gates' => ['no_open_critical'], 'reason' => 'hotfix', 'justification' => 'Attempting a cross-tenant override.',
        ])->assertNotFound();

        $this->assertSame($theirs->status, $theirs->fresh()->status);
        $this->assertDatabaseCount('sire_release_overrides', 0);
    }

    public function test_the_release_board_and_override_register_are_tenant_scoped(): void
    {
        $mine = Release::factory()->create(['tenant_id' => $this->a->id]);
        $theirs = Release::factory()->create(['tenant_id' => $this->b->id]);

        Sanctum::actingAs($this->lead);
        $ids = collect($this->getJson('/api/sire/release-board')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    public function test_portal_roles_and_anonymous_callers_are_refused(): void
    {
        $this->getJson('/api/sire/release-board')->assertUnauthorized();
        $this->getJson('/api/sire/release-overrides')->assertUnauthorized();

        foreach (['client', 'third_party_vendor', 'company'] as $role) {
            Sanctum::actingAs(User::factory()->create(['tenant_id' => $this->a->id, 'role' => $role]));
            $this->getJson('/api/sire/release-board')->assertForbidden();
        }
    }

    private function release(array $attributes = []): Release
    {
        return Release::factory()->create(array_merge([
            'tenant_id' => $this->a->id,
            'status'    => SireReleaseStatus::BLOCKED,
        ], $attributes));
    }
}
