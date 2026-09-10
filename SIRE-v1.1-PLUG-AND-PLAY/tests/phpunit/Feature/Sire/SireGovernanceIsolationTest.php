<?php

namespace Tests\Feature\Sire;

use App\Models\Sire\CorrectiveAction;
use App\Models\Sire\RecurrenceGroup;
use App\Models\Sire\Release;
use App\Models\Sire\ReleaseNote;
use App\Models\Sire\Report;
use App\Models\Sire\RootCause;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Sire\SireStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2 — tenant isolation and the governance rules that must not be bypassable.
 *
 * Two tenants throughout. Assertions are on IDS and on STATE, never on counts:
 * a count assertion passes when the right number of wrong rows comes back.
 */
class SireGovernanceIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;
    private Tenant $b;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Tenant::factory()->create();
        $this->b = Tenant::factory()->create();
        $this->userA = User::factory()->create(['tenant_id' => $this->a->id, 'role' => 'admin']);
        $this->userB = User::factory()->create(['tenant_id' => $this->b->id, 'role' => 'admin']);
    }

    // ------------------------------------------------------------- isolation

    public function test_every_phase_two_endpoint_refuses_another_tenants_record(): void
    {
        $theirReport  = Report::factory()->create(['tenant_id' => $this->b->id]);
        $theirGroup   = RecurrenceGroup::factory()->create(['tenant_id' => $this->b->id]);
        $theirRelease = Release::factory()->create(['tenant_id' => $this->b->id]);
        $theirNote    = ReleaseNote::factory()->create(['tenant_id' => $this->b->id, 'release_id' => $theirRelease->id]);
        $theirCapa    = CorrectiveAction::factory()->create(['tenant_id' => $this->b->id, 'report_id' => $theirReport->id]);

        Sanctum::actingAs($this->userA);

        $cases = [
            ['get',    "/api/sire/reports/{$theirReport->id}/root-cause"],
            ['post',   "/api/sire/reports/{$theirReport->id}/root-cause"],
            ['get',    "/api/sire/reports/{$theirReport->id}/relations"],
            ['post',   "/api/sire/reports/{$theirReport->id}/regression"],
            ['get',    "/api/sire/reports/{$theirReport->id}/kb-links"],
            ['post',   "/api/sire/reports/{$theirReport->id}/capa"],
            ['get',    "/api/sire/recurrence-groups/{$theirGroup->id}"],
            ['post',   "/api/sire/recurrence-groups/{$theirGroup->id}/recompute"],
            ['get',    "/api/sire/releases/{$theirRelease->id}"],
            ['post',   "/api/sire/releases/{$theirRelease->id}/release"],
            ['get',    "/api/sire/release-notes/{$theirNote->id}"],
            ['post',   "/api/sire/release-notes/{$theirNote->id}/publish"],
            ['post',   "/api/sire/capa/{$theirCapa->id}/start"],
        ];

        foreach ($cases as [$method, $uri]) {
            // 404, not 403 — existence hiding, the same choice the rest of the
            // codebase makes via AssertsTenantOwnership.
            $this->json($method, $uri)->assertNotFound("{$method} {$uri}");
        }

        // And nothing was mutated on the way past.
        $this->assertSame($theirRelease->status, $theirRelease->fresh()->status);
        $this->assertSame($theirNote->status, $theirNote->fresh()->status);
        $this->assertSame($theirCapa->status, $theirCapa->fresh()->status);
    }

    public function test_cross_tenant_ids_in_a_payload_are_rejected_by_validation(): void
    {
        $mine = Report::factory()->create(['tenant_id' => $this->a->id]);
        $theirs = Report::factory()->create(['tenant_id' => $this->b->id]);
        $theirRelease = Release::factory()->create(['tenant_id' => $this->b->id]);

        Sanctum::actingAs($this->userA);

        $this->postJson("/api/sire/reports/{$mine->id}/links",
            ['to_report_id' => $theirs->id, 'link_type' => 'related_to'])->assertStatus(422);

        $this->postJson("/api/sire/reports/{$mine->id}/regression",
            ['regression_of_id' => $theirs->id])->assertStatus(422);

        $this->postJson("/api/sire/reports/{$mine->id}/regression",
            ['caused_by_release_id' => $theirRelease->id])->assertStatus(422);
    }

    public function test_recurrence_lists_and_releases_are_tenant_scoped(): void
    {
        $mine = RecurrenceGroup::factory()->create(['tenant_id' => $this->a->id]);
        $theirs = RecurrenceGroup::factory()->create(['tenant_id' => $this->b->id]);

        Sanctum::actingAs($this->userA);
        $ids = collect($this->getJson('/api/sire/recurrence-groups')->assertOk()->json('data.data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    // ------------------------------------------------------- governance rules

    public function test_an_issue_cannot_be_a_duplicate_of_itself_or_form_a_loop(): void
    {
        $a = Report::factory()->create(['tenant_id' => $this->a->id, 'status' => SireStatus::TRIAGED]);
        $b = Report::factory()->create(['tenant_id' => $this->a->id, 'status' => SireStatus::DUPLICATE, 'duplicate_of_id' => $a->id]);

        Sanctum::actingAs($this->userA);

        $this->postJson("/api/sire/reports/{$a->id}/transitions",
            ['action' => 'mark_duplicate', 'duplicate_of_id' => $a->id])->assertStatus(422);

        // A → B where B already points at A would close the loop.
        $this->postJson("/api/sire/reports/{$a->id}/transitions",
            ['action' => 'mark_duplicate', 'duplicate_of_id' => $b->id])->assertStatus(422);

        $this->assertNull($a->fresh()->duplicate_of_id);
    }

    public function test_marking_a_duplicate_preserves_the_original_report(): void
    {
        $canonical = Report::factory()->create(['tenant_id' => $this->a->id, 'status' => SireStatus::TRIAGED]);
        $dupe = Report::factory()->create(['tenant_id' => $this->a->id, 'status' => SireStatus::TRIAGED]);

        Sanctum::actingAs($this->userA);
        $this->postJson("/api/sire/reports/{$dupe->id}/transitions",
            ['action' => 'mark_duplicate', 'duplicate_of_id' => $canonical->id])->assertOk();

        // The brief is explicit: duplicates are not deleted.
        $this->assertDatabaseHas('sire_reports', ['id' => $dupe->id, 'deleted_at' => null]);
        $this->assertSame(SireStatus::DUPLICATE, $dupe->fresh()->status);
        $this->assertSame($canonical->id, $dupe->fresh()->duplicate_of_id);
        $this->assertNotNull($dupe->fresh()->report_number, 'a duplicate keeps its number');
    }

    public function test_a_duplicate_cannot_join_a_recurrence_group(): void
    {
        $canonical = Report::factory()->create(['tenant_id' => $this->a->id]);
        $dupe = Report::factory()->create([
            'tenant_id' => $this->a->id, 'status' => SireStatus::DUPLICATE, 'duplicate_of_id' => $canonical->id,
        ]);
        $group = RecurrenceGroup::factory()->create(['tenant_id' => $this->a->id]);

        Sanctum::actingAs($this->userA);

        // Counting a duplicate would inflate the number the whole engine exists
        // to report.
        $this->postJson("/api/sire/recurrence-groups/{$group->id}/occurrences", ['report_id' => $dupe->id])
            ->assertStatus(422);

        $this->assertSame(0, $group->fresh()->occurrence_count);
    }

    public function test_release_notes_cannot_be_published_without_approval(): void
    {
        $release = Release::factory()->create(['tenant_id' => $this->a->id]);
        $note = ReleaseNote::factory()->create([
            'tenant_id' => $this->a->id, 'release_id' => $release->id,
            'audience' => 'user', 'status' => ReleaseNote::DRAFT,
            'sections' => [['key' => 'fixed', 'label' => 'Fixes', 'entries' => [['summary' => 'x']]]],
        ]);

        Sanctum::actingAs($this->userA);

        $this->postJson("/api/sire/release-notes/{$note->id}/publish")->assertStatus(422);
        $this->assertSame(ReleaseNote::DRAFT, $note->fresh()->status);

        $this->postJson("/api/sire/release-notes/{$note->id}/submit")->assertOk();
        $this->postJson("/api/sire/release-notes/{$note->id}/approve")->assertOk();
        $this->postJson("/api/sire/release-notes/{$note->id}/publish")->assertOk();

        $this->assertSame(ReleaseNote::PUBLISHED, $note->fresh()->status);
        $this->assertNotNull($note->fresh()->approved_at);
    }

    public function test_published_release_notes_are_frozen(): void
    {
        $release = Release::factory()->create(['tenant_id' => $this->a->id]);
        $note = ReleaseNote::factory()->create([
            'tenant_id' => $this->a->id, 'release_id' => $release->id,
            'audience' => 'internal', 'status' => ReleaseNote::PUBLISHED,
        ]);

        Sanctum::actingAs($this->userA);

        // What customers have already read must not silently change.
        $this->postJson("/api/sire/release-notes/{$note->id}/regenerate")->assertStatus(422);
        $this->patchJson("/api/sire/release-notes/{$note->id}", ['title' => 'edited'])->assertStatus(422);
    }

    public function test_user_facing_notes_exclude_issues_with_no_customer_summary(): void
    {
        $release = Release::factory()->create(['tenant_id' => $this->a->id]);

        Report::factory()->create([
            'tenant_id' => $this->a->id, 'released_version_id' => $release->id,
            'status' => SireStatus::CLOSED, 'title' => 'null deref in LeadPolicy::view',
            'user_facing_summary' => null, 'include_in_release_notes' => true,
        ]);
        Report::factory()->create([
            'tenant_id' => $this->a->id, 'released_version_id' => $release->id,
            'status' => SireStatus::CLOSED, 'user_facing_summary' => 'Leads now open correctly.',
        ]);

        Sanctum::actingAs($this->userA);
        $note = $this->postJson("/api/sire/releases/{$release->id}/notes", ['audience' => 'user'])
            ->assertOk()->json('data');

        $body = json_encode($note['sections']);
        $this->assertStringNotContainsString('LeadPolicy', $body, 'internal titles must never reach customers');
        $this->assertStringContainsString('Leads now open correctly', $body);
        $this->assertSame(1, $note['issue_count']);
    }

    public function test_five_whys_are_required_before_confirming_a_serious_issue(): void
    {
        $report = Report::factory()->create(['tenant_id' => $this->a->id, 'priority' => 'p1']);

        Sanctum::actingAs($this->userA);

        $this->postJson("/api/sire/reports/{$report->id}/root-cause", [
            'category' => 'code', 'description' => 'Missing null check.',
            'five_whys' => ['a', 'b', ''],
        ])->assertOk();

        $this->postJson("/api/sire/reports/{$report->id}/root-cause/confirm")->assertStatus(422);

        $this->postJson("/api/sire/reports/{$report->id}/root-cause", [
            'category' => 'code', 'description' => 'Missing null check.',
            'five_whys' => ['a', 'b', 'c', 'd', 'e'],
        ])->assertOk();

        $this->postJson("/api/sire/reports/{$report->id}/root-cause/confirm")->assertOk();
        $this->assertNotNull(RootCause::where('report_id', $report->id)->first()->confirmed_at);
    }

    public function test_editing_a_confirmed_analysis_clears_the_confirmation(): void
    {
        $report = Report::factory()->create(['tenant_id' => $this->a->id, 'priority' => 'p3']);

        Sanctum::actingAs($this->userA);
        $this->postJson("/api/sire/reports/{$report->id}/root-cause",
            ['category' => 'code', 'description' => 'first'])->assertOk();
        $this->postJson("/api/sire/reports/{$report->id}/root-cause/confirm")->assertOk();

        $this->postJson("/api/sire/reports/{$report->id}/root-cause",
            ['category' => 'design', 'description' => 'actually something else'])->assertOk();

        // A signature attests to what it signed. Carrying it onto different words
        // would be a forged sign-off.
        $this->assertNull(RootCause::where('report_id', $report->id)->first()->confirmed_at);
    }

    public function test_portal_roles_cannot_reach_governance_endpoints(): void
    {
        $report = Report::factory()->create(['tenant_id' => $this->a->id]);

        foreach (['client', 'third_party_vendor', 'company'] as $role) {
            Sanctum::actingAs(User::factory()->create(['tenant_id' => $this->a->id, 'role' => $role]));
            $this->getJson("/api/sire/reports/{$report->id}/relations")->assertForbidden();
            $this->getJson('/api/sire/quality')->assertForbidden();
            $this->getJson('/api/sire/releases')->assertForbidden();
        }
    }

    public function test_governance_endpoints_require_authentication(): void
    {
        // Regression for the chained-->middleware() trap.
        $this->getJson('/api/sire/quality')->assertUnauthorized();
        $this->getJson('/api/sire/releases')->assertUnauthorized();
        $this->getJson('/api/sire/recurrence-groups')->assertUnauthorized();
        $this->getJson('/api/sire/capa')->assertUnauthorized();
    }
}
