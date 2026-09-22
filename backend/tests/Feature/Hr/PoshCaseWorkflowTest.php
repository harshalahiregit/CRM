<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrDecisionRound;
use App\Models\Hr\HrPoshCase;
use App\Models\Hr\HrPoshCaseMember;
use App\Models\Hr\HrPoshCaseRead;
use App\Models\Hr\HrPoshCommittee;
use App\Models\Hr\HrPoshCommitteeMember;
use App\Models\Hr\HrPoshCommitteeRole;
use App\Models\Hr\HrPoshFinding;
use App\Models\Notifications\HrNotification;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Hr\DataScope;
use App\Support\Hr\Decision\Decision;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Running a POSH case: intake, thread, evidence, inquiry, findings,
 * publication and reconstitution.
 *
 * The access model from 3b is unchanged and everything here sits on top of it.
 * Two properties are worth naming because they are the ones most easily lost
 * while adding features:
 *
 *   RAISING A CASE IS NOT READING ONE. The creator gets no membership, so the
 *   person who logs a complaint cannot open it afterwards — which is the point
 *   when the complaint concerns a colleague of theirs.
 *
 *   THE INQUIRY ROSTER IS THE CASE'S MEMBERS, NOT THE COMMITTEE'S. Somebody
 *   added to the committee after the case opened has no seat. Reading the live
 *   committee at round-open time would quietly undo 3b's snapshot.
 */
class PoshCaseWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'posh-c', 'status' => 'active']);
        $this->other  = Tenant::create(['name' => 'Two', 'slug' => 'posh-c2', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function user(?Tenant $t = null, string $role = 'staff', ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => ($t ?: $this->tenant)->id, 'name' => 'U'.substr(uniqid(), -4),
            'email' => uniqid().'@posh.test', 'password' => Hash::make('Password123!'),
            'role' => $role, 'status' => 'active', 'internal_role' => $internal,
        ]);
    }

    private function staffWith(array $modules, ?Tenant $t = null): User
    {
        $t = $t ?: $this->tenant;
        $permissions = [];
        foreach ($modules as $m) {
            $permissions[$m] = [StaffPermission::VIEW_GLOBAL];
        }

        $role = StaffRole::create([
            'tenant_id' => $t->id, 'name' => 'R'.substr(uniqid(), -5),
            'slug' => 'r_'.substr(uniqid(), -5), 'permissions' => $permissions,
            'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);

        return User::create([
            'tenant_id' => $t->id, 'name' => 'S', 'email' => uniqid().'@posh.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
            'staff_role_id' => $role->id,
        ]);
    }

    private function intaker(?Tenant $t = null): User
    {
        return $this->staffWith(['hr_posh_intake'], $t);
    }

    /**
     * A committee with a case-managing chair and N plain members.
     *
     * @return array{0:HrPoshCommittee,1:HrPoshCommitteeRole,2:HrPoshCommitteeRole,3:array<int,User>}
     */
    private function committee(int $members = 2, string $mode = HrPoshCommittee::QUORUM_ALL, ?int $quorum = null, ?Tenant $t = null): array
    {
        $t = $t ?: $this->tenant;

        $committee = HrPoshCommittee::create([
            'tenant_id' => $t->id, 'name' => 'ICC '.substr(uniqid(), -5),
            'quorum_mode' => $mode, 'quorum_required' => $quorum,
            'is_active' => true, 'sort_order' => 0,
        ]);

        $chairRole = HrPoshCommitteeRole::create([
            'tenant_id' => $t->id, 'committee_id' => $committee->id,
            'key' => 'presiding_officer', 'label' => 'Presiding Officer',
            'can_manage_case' => true, 'sort_order' => 0, 'is_active' => true,
        ]);

        $memberRole = HrPoshCommitteeRole::create([
            'tenant_id' => $t->id, 'committee_id' => $committee->id,
            'key' => 'internal_member', 'label' => 'Internal Member',
            'can_manage_case' => false, 'sort_order' => 1, 'is_active' => true,
        ]);

        $people = [];
        for ($i = 0; $i < $members; $i++) {
            $u = $this->user($t);
            HrPoshCommitteeMember::create([
                'tenant_id' => $t->id, 'committee_id' => $committee->id,
                'role_id' => $i === 0 ? $chairRole->id : $memberRole->id,
                'user_id' => $u->id, 'is_active' => true,
            ]);
            $people[] = $u;
        }

        return [$committee->fresh(), $chairRole, $memberRole, $people];
    }

    /** Raise a case through the real endpoint. @return array{0:HrPoshCase,1:array<int,User>,2:HrPoshCommittee} */
    private function raise(int $members = 2, string $mode = HrPoshCommittee::QUORUM_ALL, ?int $quorum = null): array
    {
        [$committee, , , $people] = $this->committee($members, $mode, $quorum);

        Sanctum::actingAs($this->intaker());
        $id = $this->postJson('/api/hr/posh-cases', [
            'committee_id' => $committee->id,
            'narrative' => 'What happened.',
            'respondent_label' => 'Respondent',
        ])->assertStatus(201)->json('data.id');

        return [HrPoshCase::findOrFail($id), $people, $committee];
    }

    /** The chair — the member whose role carries can_manage_case. */
    private function chair(array $people): User
    {
        return $people[0];
    }

    /* ── intake ───────────────────────────────────────────────────────── */

    public function test_intake_requires_the_intake_capability(): void
    {
        [$committee] = $this->committee();
        $payload = ['committee_id' => $committee->id, 'narrative' => 'x'];

        // Every other authority in the product, and none of them is this one.
        foreach ([
            $this->user(),
            $this->user(role: 'admin'),
            $this->staffWith(['hr_settings']),
            $this->user(internal: 'hr_executive'),
        ] as $wrong) {
            Sanctum::actingAs($wrong);
            $this->postJson('/api/hr/posh-cases', $payload)->assertStatus(403);
        }

        $this->assertSame(0, HrPoshCase::count());
    }

    /**
     * The intake capability is held explicitly or not at all.
     *
     * StaffPermissionService::can() lets any role === 'admin' through, which is
     * right for the settings screens it was written for and wrong here: an
     * administrator is often the person a complaint is about. The controller
     * reads the grid directly for that reason, so this pins every way in.
     */
    public function test_intake_is_held_explicitly_and_by_nobody_else(): void
    {
        [$committee] = $this->committee();
        $payload = ['committee_id' => $committee->id, 'narrative' => 'x'];

        // Granted explicitly — the only thing that works.
        Sanctum::actingAs($this->intaker());
        $this->postJson('/api/hr/posh-cases', $payload)->assertStatus(201);

        // An admin WITHOUT the explicit grant. The bypass does not reach here.
        Sanctum::actingAs($this->user(role: 'admin'));
        $this->postJson('/api/hr/posh-cases', $payload)->assertStatus(403);

        // An admin WITH it works — the rule is "not implied", not "not allowed".
        Sanctum::actingAs($this->staffWith(['hr_posh_intake']));
        $this->postJson('/api/hr/posh-cases', $payload)->assertStatus(201);

        // A staff account carrying other HR authority, but not this one.
        Sanctum::actingAs($this->staffWith(['hr_employees', 'hr_payroll', 'hr_settings']));
        $this->postJson('/api/hr/posh-cases', $payload)->assertStatus(403);

        // No grid at all.
        Sanctum::actingAs($this->user());
        $this->postJson('/api/hr/posh-cases', $payload)->assertStatus(403);

        $this->assertSame(2, HrPoshCase::count());
    }

    public function test_a_portal_account_cannot_raise_a_case(): void
    {
        [$committee] = $this->committee();

        // Not staff. StaffRoleService resolves no grid for them, and a client
        // logging a POSH complaint through the staff API is not a route that
        // should exist.
        Sanctum::actingAs($this->user(role: 'client'));
        $this->postJson('/api/hr/posh-cases', [
            'committee_id' => $committee->id, 'narrative' => 'x',
        ])->assertStatus(403);

        $this->assertSame(0, HrPoshCase::count());
    }

    public function test_intake_cannot_cross_the_tenant_boundary(): void
    {
        [$theirs] = $this->committee(2, HrPoshCommittee::QUORUM_ALL, null, $this->other);

        // Holding the capability in one workspace grants nothing in another.
        Sanctum::actingAs($this->intaker());
        $this->postJson('/api/hr/posh-cases', [
            'committee_id' => $theirs->id, 'narrative' => 'x',
        ])->assertStatus(404);

        $this->assertSame(0, HrPoshCase::count());
    }

    public function test_raising_a_case_snapshots_the_committee(): void
    {
        [$case, $people] = $this->raise(3);

        $this->assertSame(3, $case->activeMembers()->count());
        $this->assertSame(
            collect($people)->pluck('id')->sort()->values()->all(),
            $case->activeMembers()->pluck('user_id')->map(fn ($i) => (int) $i)->sort()->values()->all()
        );
        $this->assertSame('POSH-1', $case->reference);
        $this->assertNotNull($case->complaint_received_at);
    }

    public function test_the_creator_gets_no_access_to_what_they_raised(): void
    {
        [$committee] = $this->committee();
        $intaker = $this->intaker();

        Sanctum::actingAs($intaker);
        $id = $this->postJson('/api/hr/posh-cases', [
            'committee_id' => $committee->id, 'narrative' => 'What happened.',
        ])->assertStatus(201)->json('data.id');

        // Logging a complaint and being entitled to read it are different
        // things, particularly when it concerns a colleague.
        $this->getJson("/api/hr/posh-cases/{$id}")->assertStatus(404);
    }

    public function test_intake_returns_only_the_reference(): void
    {
        [$committee] = $this->committee();

        Sanctum::actingAs($this->intaker());
        $body = $this->postJson('/api/hr/posh-cases', [
            'committee_id' => $committee->id, 'narrative' => 'Secret narrative.',
        ])->assertStatus(201)->json();

        // The creator cannot read the case back, so returning its contents
        // would be the one disclosure the design exists to prevent.
        $this->assertStringNotContainsString('Secret narrative.', json_encode($body));
        $this->assertSame(['id', 'reference'], array_keys($body['data']));
    }

    public function test_references_are_sequential_per_tenant(): void
    {
        [$a] = $this->raise();
        [$b] = $this->raise();

        $this->assertSame('POSH-1', $a->reference);
        $this->assertSame('POSH-2', $b->reference);
    }

    public function test_an_inactive_committee_cannot_take_a_case(): void
    {
        [$committee] = $this->committee();
        $committee->update(['is_active' => false]);

        Sanctum::actingAs($this->intaker());
        $this->postJson('/api/hr/posh-cases', [
            'committee_id' => $committee->id, 'narrative' => 'x',
        ])->assertStatus(422);
    }

    public function test_a_committee_from_another_tenant_is_not_found(): void
    {
        [$theirs] = $this->committee(2, HrPoshCommittee::QUORUM_ALL, null, $this->other);

        Sanctum::actingAs($this->intaker());
        $this->postJson('/api/hr/posh-cases', [
            'committee_id' => $theirs->id, 'narrative' => 'x',
        ])->assertStatus(404);
    }

    /* ── thread ───────────────────────────────────────────────────────── */

    public function test_members_read_and_write_the_thread(): void
    {
        [$case, $people] = $this->raise();

        Sanctum::actingAs($people[1]);
        $this->postJson("/api/hr/posh-cases/{$case->id}/messages", ['body' => 'A message.'])->assertOk();
        $this->postJson("/api/hr/posh-cases/{$case->id}/notes", ['body' => 'An internal note.'])->assertOk();

        $kinds = collect($this->getJson("/api/hr/posh-cases/{$case->id}/thread")->assertOk()->json('data'))
            ->pluck('kind')->all();

        // Members see both. The restricted complainant surface, when it
        // exists, will pass asEmployee: true and see only the message.
        $this->assertContains('message', $kinds);
        $this->assertContains('note', $kinds);
    }

    public function test_a_non_member_cannot_touch_the_thread(): void
    {
        [$case] = $this->raise();

        foreach ([$this->user(role: 'admin'), $this->staffWith(['hr_settings'])] as $outsider) {
            Sanctum::actingAs($outsider);
            $this->getJson("/api/hr/posh-cases/{$case->id}/thread")->assertStatus(404);
            $this->postJson("/api/hr/posh-cases/{$case->id}/notes", ['body' => 'x'])->assertStatus(404);
        }
    }

    /* ── attachments ──────────────────────────────────────────────────── */

    public function test_a_member_can_upload_and_download_evidence(): void
    {
        \Storage::fake('local');
        [$case, $people] = $this->raise();

        Sanctum::actingAs($people[1]);
        $fileId = $this->postJson("/api/hr/posh-cases/{$case->id}/attachments", [
            'file' => UploadedFile::fake()->create('evidence.pdf', 20, 'application/pdf'),
        ])->assertStatus(201)->json('data.id');

        $this->get("/api/hr/posh-cases/{$case->id}/attachments/{$fileId}")->assertOk();
    }

    public function test_a_non_member_cannot_reach_evidence(): void
    {
        \Storage::fake('local');
        [$case, $people] = $this->raise();

        Sanctum::actingAs($people[1]);
        $fileId = $this->postJson("/api/hr/posh-cases/{$case->id}/attachments", [
            'file' => UploadedFile::fake()->create('evidence.pdf', 20, 'application/pdf'),
        ])->assertStatus(201)->json('data.id');

        Sanctum::actingAs($this->user(role: 'admin'));
        $this->getJson("/api/hr/posh-cases/{$case->id}/attachments")->assertStatus(404);
        $this->get("/api/hr/posh-cases/{$case->id}/attachments/{$fileId}")->assertStatus(404);
    }

    public function test_evidence_from_another_case_cannot_be_fetched_through_mine(): void
    {
        \Storage::fake('local');
        [$mine, $minePeople] = $this->raise();
        [$theirs, $theirPeople] = $this->raise();

        Sanctum::actingAs($theirPeople[1]);
        $theirFile = $this->postJson("/api/hr/posh-cases/{$theirs->id}/attachments", [
            'file' => UploadedFile::fake()->create('theirs.pdf', 20, 'application/pdf'),
        ])->assertStatus(201)->json('data.id');

        // The nested IDOR. A member of my case asking for a file id belonging
        // to another case must be refused — AttachmentService authorises
        // nothing, so the ordering here is the only thing preventing it.
        Sanctum::actingAs($minePeople[1]);
        $this->get("/api/hr/posh-cases/{$mine->id}/attachments/{$theirFile}")->assertStatus(404);
    }

    /* ── inquiry ──────────────────────────────────────────────────────── */

    public function test_only_a_case_manager_opens_the_inquiry(): void
    {
        [$case, $people] = $this->raise();

        // A member without can_manage_case: 403, not 404 — they can see the
        // case, they simply may not run it.
        Sanctum::actingAs($people[1]);
        $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry")->assertStatus(403);

        Sanctum::actingAs($this->chair($people));
        $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry")->assertOk();

        $this->assertSame(HrPoshCase::STATUS_UNDER_INQUIRY, $case->fresh()->status);
        $this->assertNotNull($case->fresh()->inquiry_started_at);
    }

    public function test_the_roster_is_the_cases_members_not_the_committees(): void
    {
        [$case, $people, $committee] = $this->raise(2);

        // Somebody joins the committee AFTER the case opened.
        $role = HrPoshCommitteeRole::where('committee_id', $committee->id)->where('key', 'internal_member')->firstOrFail();
        $latecomer = $this->user();
        HrPoshCommitteeMember::create([
            'tenant_id' => $this->tenant->id, 'committee_id' => $committee->id,
            'role_id' => $role->id, 'user_id' => $latecomer->id, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->chair($people));
        $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry")->assertOk();

        $round = HrDecisionRound::where('subject_id', $case->id)->firstOrFail();
        $seats = $round->participants->pluck('slot_key')->all();

        // Two seats, not three. The snapshot holds.
        $this->assertCount(2, $seats);
        $this->assertNotContains((string) $latecomer->id, $seats);
    }

    public function test_quorum_is_revalidated_when_the_inquiry_opens(): void
    {
        // Committee needs 3 to agree, but the case will only have 2 members.
        [$case, $people] = $this->raise(2, HrPoshCommittee::QUORUM_N_OF_M, 3);

        Sanctum::actingAs($this->chair($people));
        $response = $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry");

        $response->assertStatus(422);
        $this->assertStringContainsString('needs 3 member(s) to agree', $response->json('message'));
        $this->assertSame(HrPoshCase::STATUS_RECEIVED, $case->fresh()->status);
    }

    public function test_a_unanimous_inquiry_concludes_the_case(): void
    {
        [$case, $people] = $this->raise(2);

        Sanctum::actingAs($this->chair($people));
        $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry")->assertOk();

        foreach ($people as $member) {
            Sanctum::actingAs($member);
            $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry/decide", [
                'decision' => Decision::APPROVED,
            ])->assertOk();
        }

        $fresh = $case->fresh();
        $this->assertSame(HrPoshCase::STATUS_INQUIRY_COMPLETE, $fresh->status);
        $this->assertSame(Decision::OUTCOME_APPROVED, $fresh->outcome);
        $this->assertNotNull($fresh->inquiry_completed_at);
    }

    public function test_a_recusal_needs_a_reason_and_leaves_the_denominator(): void
    {
        [$case, $people] = $this->raise(3);

        Sanctum::actingAs($this->chair($people));
        $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry")->assertOk();

        Sanctum::actingAs($people[2]);
        $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry/decide", [
            'decision' => Decision::RECUSED,
        ])->assertStatus(422);

        $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry/decide", [
            'decision' => Decision::RECUSED, 'remarks' => 'Named in the complaint.',
        ])->assertOk();

        // The other two decide among themselves — the primitive's arithmetic,
        // reused unchanged.
        foreach ([$people[0], $people[1]] as $member) {
            Sanctum::actingAs($member);
            $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry/decide", [
                'decision' => Decision::APPROVED,
            ])->assertOk();
        }

        $this->assertSame(HrPoshCase::STATUS_INQUIRY_COMPLETE, $case->fresh()->status);
    }

    public function test_a_case_member_cannot_vote_twice(): void
    {
        [$case, $people] = $this->raise(3);

        Sanctum::actingAs($this->chair($people));
        $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry")->assertOk();
        $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry/decide", ['decision' => Decision::APPROVED])->assertOk();
        $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry/decide", ['decision' => Decision::REJECTED])
            ->assertStatus(422);
    }

    public function test_a_quorum_unreachable_round_does_not_conclude_the_case(): void
    {
        [$case, $people] = $this->raise(3, HrPoshCommittee::QUORUM_N_OF_M, 3);

        Sanctum::actingAs($this->chair($people));
        $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry")->assertOk();

        Sanctum::actingAs($people[2]);
        $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry/decide", [
            'decision' => Decision::RECUSED, 'remarks' => 'Conflict.',
        ])->assertOk();

        // Two left, three needed. A stalled round is a state to be resolved,
        // not a verdict to act on — the case must not move.
        $round = HrDecisionRound::where('subject_id', $case->id)->firstOrFail();
        $this->assertSame(Decision::STATE_QUORUM_UNREACHABLE, $round->state);
        $this->assertSame(HrPoshCase::STATUS_UNDER_INQUIRY, $case->fresh()->status);
        $this->assertNull($case->fresh()->outcome);
    }

    /* ── findings ─────────────────────────────────────────────────────── */

    /** A case whose inquiry has concluded. */
    private function concluded(): array
    {
        [$case, $people] = $this->raise(2);

        Sanctum::actingAs($this->chair($people));
        $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry")->assertOk();

        foreach ($people as $member) {
            Sanctum::actingAs($member);
            $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry/decide", ['decision' => Decision::APPROVED])->assertOk();
        }

        return [$case->fresh(), $people];
    }

    public function test_a_draft_finding_appears_when_the_inquiry_concludes(): void
    {
        [$case] = $this->concluded();

        $finding = HrPoshFinding::where('case_id', $case->id)->firstOrFail();
        $this->assertSame(HrPoshFinding::STATUS_DRAFT, $finding->status);
        $this->assertSame(Decision::OUTCOME_APPROVED, $finding->outcome);
    }

    public function test_only_a_case_manager_writes_records_and_publishes(): void
    {
        [$case, $people] = $this->concluded();

        Sanctum::actingAs($people[1]);
        $this->putJson("/api/hr/posh-cases/{$case->id}/findings", ['summary' => 'x'])->assertStatus(403);
        $this->postJson("/api/hr/posh-cases/{$case->id}/findings/record")->assertStatus(403);
        $this->postJson("/api/hr/posh-cases/{$case->id}/findings/publish")->assertStatus(403);

        Sanctum::actingAs($this->chair($people));
        $this->putJson("/api/hr/posh-cases/{$case->id}/findings", [
            'summary' => 'Upheld.', 'recommendation' => 'Training.',
        ])->assertOk();
        $this->postJson("/api/hr/posh-cases/{$case->id}/findings/record")->assertOk();
        $this->postJson("/api/hr/posh-cases/{$case->id}/findings/publish")->assertOk();
    }

    public function test_a_finding_cannot_be_published_before_it_is_recorded(): void
    {
        [$case, $people] = $this->concluded();

        Sanctum::actingAs($this->chair($people));
        $this->putJson("/api/hr/posh-cases/{$case->id}/findings", ['summary' => 'Draft wording.'])->assertOk();

        // Publishing a draft would make wording public the committee has not
        // settled on.
        $this->postJson("/api/hr/posh-cases/{$case->id}/findings/publish")->assertStatus(422);
    }

    public function test_a_recorded_finding_can_no_longer_be_edited(): void
    {
        [$case, $people] = $this->concluded();

        Sanctum::actingAs($this->chair($people));
        $this->putJson("/api/hr/posh-cases/{$case->id}/findings", ['summary' => 'Final.'])->assertOk();
        $this->postJson("/api/hr/posh-cases/{$case->id}/findings/record")->assertOk();

        $this->putJson("/api/hr/posh-cases/{$case->id}/findings", ['summary' => 'Changed.'])->assertStatus(422);
        $this->assertSame('Final.', HrPoshFinding::where('case_id', $case->id)->value('summary'));
    }

    public function test_a_published_finding_is_immutable(): void
    {
        [$case, $people] = $this->concluded();

        Sanctum::actingAs($this->chair($people));
        $this->putJson("/api/hr/posh-cases/{$case->id}/findings", [
            'summary' => 'Upheld.', 'recommendation' => 'Training.',
        ])->assertOk();
        $this->postJson("/api/hr/posh-cases/{$case->id}/findings/record")->assertOk();
        $this->postJson("/api/hr/posh-cases/{$case->id}/findings/publish")->assertOk();

        // No amendment path exists, by decision. A correction would be a new
        // finding, and what that means is not yet settled.
        //
        // The MESSAGE is asserted, not just the refusal. A published finding is
        // necessarily also a recorded one, so the recorded check alone would
        // refuse this edit — and would tell the committee their finding was
        // merely "recorded" when it has already gone out. The published branch
        // exists for that sentence, so the sentence is what pins it.
        $this->putJson("/api/hr/posh-cases/{$case->id}/findings", ['summary' => 'Rewritten.'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A published finding cannot be changed.');

        $this->postJson("/api/hr/posh-cases/{$case->id}/findings/publish")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This finding has already been published.');

        $finding = HrPoshFinding::where('case_id', $case->id)->firstOrFail();
        $this->assertSame('Upheld.', $finding->summary);
        $this->assertSame('Training.', $finding->recommendation);
        $this->assertNotNull($finding->published_at);
        $this->assertNotNull($case->fresh()->findings_published_at);
    }

    public function test_a_summary_is_required_before_recording(): void
    {
        [$case, $people] = $this->concluded();

        Sanctum::actingAs($this->chair($people));
        $this->postJson("/api/hr/posh-cases/{$case->id}/findings/record")->assertStatus(422);
    }

    public function test_findings_are_unreachable_to_a_non_member(): void
    {
        [$case] = $this->concluded();

        Sanctum::actingAs($this->user(role: 'admin'));
        $this->getJson("/api/hr/posh-cases/{$case->id}/findings")->assertStatus(404);
    }

    /* ── authority, asked directly ────────────────────────────────────── */

    /**
     * PoshCaseAuthority is exercised here WITHOUT a controller.
     *
     * Every HTTP route resolves membership before it asks about authority, so
     * over the wire the membership check inside canManageCase() is unreachable
     * — a mutation removing it leaves the whole suite green. That makes it look
     * like dead code, and the next person to read it may delete it.
     *
     * It is not dead. PoshCaseService::withdraw(), close() and acknowledge()
     * call assertCanManageCase() directly, and the ordering that currently
     * protects them is the controller's, not theirs. This pins the contract the
     * class actually states: not a member, no authority, whatever else is held.
     */
    public function test_authority_refuses_a_non_member_on_its_own(): void
    {
        [$case, $people, $committee] = $this->raise(2);
        $authority = app(\App\Services\Hr\Posh\PoshCaseAuthority::class);

        // On the committee with a managing role, but NOT on this case.
        $chairRole = HrPoshCommitteeRole::where('committee_id', $committee->id)
            ->where('key', 'presiding_officer')->firstOrFail();
        $outsider = $this->user();
        HrPoshCommitteeMember::create([
            'tenant_id' => $this->tenant->id, 'committee_id' => $committee->id,
            'role_id' => $chairRole->id, 'user_id' => $outsider->id, 'is_active' => true,
        ]);

        $this->assertFalse($authority->canManageCase($outsider, $case));
        $this->assertFalse($authority->canManageCase($this->user(role: 'admin'), $case));
        $this->assertFalse($authority->canManageCase($this->staffWith(['hr_settings']), $case));
        $this->assertTrue($authority->canManageCase($this->chair($people), $case));

        // And once their period is closed, the authority goes with it.
        HrPoshCaseMember::revoke($case, $this->chair($people)->id, $this->user(), 'Recused.');
        $this->assertFalse($authority->canManageCase($this->chair($people), $case->fresh()));
    }

    /**
     * The one case where canManageCase()'s membership check is not redundant.
     *
     * Its own role_key lookup filters case, tenant, user and removed_at — the
     * same four conditions isMember() checks — so the explicit call looks like
     * a duplicate that could be deleted. It is not. isMember() additionally
     * compares the ACTOR's tenant to the case's, and this is the row where that
     * matters: stamped with the case's tenant, held by somebody from another.
     *
     * The same hostile row 3b pins against the read gate, aimed at the
     * authority gate instead.
     */
    public function test_a_membership_row_cannot_confer_authority_across_tenants(): void
    {
        [$case] = $this->raise(2);
        $authority = app(\App\Services\Hr\Posh\PoshCaseAuthority::class);

        $stranger = $this->user($this->other);

        HrPoshCaseMember::create([
            // The CASE's tenant, deliberately — a row that looks local.
            'tenant_id'    => $case->tenant_id,
            'case_id'      => $case->id,
            'user_id'      => $stranger->id,
            'role_key'     => 'presiding_officer',
            'source'       => HrPoshCaseMember::SOURCE_MANUAL,
            'added_at'     => now(),
            'added_reason' => 'Planted.',
        ]);

        $this->assertFalse($authority->canManageCase($stranger, $case));
    }

    /* ── withdrawal ───────────────────────────────────────────────────── */

    public function test_only_a_case_manager_withdraws(): void
    {
        [$case, $people] = $this->raise();

        Sanctum::actingAs($people[1]);
        $this->patchJson("/api/hr/posh-cases/{$case->id}/withdraw", ['reason' => 'x'])->assertStatus(403);

        Sanctum::actingAs($this->chair($people));
        $this->patchJson("/api/hr/posh-cases/{$case->id}/withdraw", ['reason' => 'Complaint retracted.'])->assertOk();

        $this->assertSame(HrPoshCase::STATUS_WITHDRAWN, $case->fresh()->status);
    }

    public function test_withdrawing_cancels_a_live_inquiry_and_keeps_its_decisions(): void
    {
        [$case, $people] = $this->raise(3);

        Sanctum::actingAs($this->chair($people));
        $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry")->assertOk();
        $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry/decide", ['decision' => Decision::APPROVED])->assertOk();

        $this->patchJson("/api/hr/posh-cases/{$case->id}/withdraw", ['reason' => 'Retracted.'])->assertOk();

        $round = HrDecisionRound::where('subject_id', $case->id)->firstOrFail();
        $this->assertSame(Decision::STATE_CANCELLED, $round->state);
        // The decision already made survives as history.
        $this->assertSame(Decision::APPROVED, $round->participants->firstWhere('decision', Decision::APPROVED)?->decision);
    }

    public function test_a_finished_case_takes_no_more_content(): void
    {
        [$case, $people] = $this->raise();

        Sanctum::actingAs($this->chair($people));
        $this->patchJson("/api/hr/posh-cases/{$case->id}/withdraw", ['reason' => 'Retracted.'])->assertOk();

        $this->postJson("/api/hr/posh-cases/{$case->id}/notes", ['body' => 'x'])->assertStatus(422);
    }

    /* ── reconstitution ───────────────────────────────────────────────── */

    public function test_reconstitution_needs_hr_settings_and_grants_no_access(): void
    {
        [$case, $people, $committee] = $this->raise(2);
        $newcomer = $this->user();
        $settings = $this->staffWith(['hr_settings']);

        Sanctum::actingAs($settings);
        $this->postJson("/api/hr/posh-cases/{$case->id}/reconstitute", [
            'reason' => 'Chair recused.',
            'members' => [
                ['user_id' => $people[1]->id, 'role_key' => 'presiding_officer'],
                ['user_id' => $newcomer->id, 'role_key' => 'internal_member'],
            ],
        ])->assertOk();

        // The authority to repair a committee is not the authority to read the
        // complaint.
        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertStatus(404);
        $this->assertFalse(
            HrPoshCaseMember::where('case_id', $case->id)->where('user_id', $settings->id)->exists()
        );
    }

    public function test_reconstitution_closes_and_opens_periods_without_deleting(): void
    {
        [$case, $people] = $this->raise(2);
        $newcomer = $this->user();

        Sanctum::actingAs($this->staffWith(['hr_settings']));
        $this->postJson("/api/hr/posh-cases/{$case->id}/reconstitute", [
            'reason' => 'Chair recused.',
            'members' => [
                ['user_id' => $people[1]->id, 'role_key' => 'presiding_officer'],
                ['user_id' => $newcomer->id, 'role_key' => 'internal_member'],
            ],
        ])->assertOk();

        // The removed chair's period is closed, not deleted.
        $chairRow = HrPoshCaseMember::where('case_id', $case->id)
            ->where('user_id', $people[0]->id)->firstOrFail();
        $this->assertNotNull($chairRow->removed_at);
        $this->assertSame('Chair recused.', $chairRow->removed_reason);

        // And they lose access immediately.
        Sanctum::actingAs($people[0]);
        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertStatus(404);

        Sanctum::actingAs($newcomer);
        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertOk();
    }

    public function test_reconstitution_supersedes_the_round_and_keeps_its_decisions(): void
    {
        [$case, $people] = $this->raise(3);

        Sanctum::actingAs($this->chair($people));
        $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry")->assertOk();
        $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry/decide", ['decision' => Decision::APPROVED])->assertOk();

        $original = HrDecisionRound::where('subject_id', $case->id)->firstOrFail();

        Sanctum::actingAs($this->staffWith(['hr_settings']));
        $this->postJson("/api/hr/posh-cases/{$case->id}/reconstitute", [
            'reason' => 'Member replaced.',
            'members' => [
                ['user_id' => $people[0]->id, 'role_key' => 'presiding_officer'],
                ['user_id' => $people[1]->id, 'role_key' => 'internal_member'],
            ],
        ])->assertOk();

        $old = $original->fresh();
        $this->assertSame(Decision::STATE_SUPERSEDED, $old->state);
        // The previous committee's decision survives on the old round.
        $this->assertSame(Decision::APPROVED,
            $old->participants->firstWhere('slot_key', (string) $people[0]->id)?->decision);

        // The new round starts from nothing: a set of people who have changed
        // has not answered.
        $replacement = HrDecisionRound::where('subject_id', $case->id)
            ->where('state', Decision::STATE_OPEN)->firstOrFail();
        foreach ($replacement->participants as $seat) {
            $this->assertNull($seat->decision);
        }
    }

    public function test_reconstitution_refuses_a_cross_tenant_user(): void
    {
        [$case, $people] = $this->raise(2);
        $stranger = $this->user($this->other);

        Sanctum::actingAs($this->staffWith(['hr_settings']));
        $this->postJson("/api/hr/posh-cases/{$case->id}/reconstitute", [
            'reason' => 'x',
            'members' => [['user_id' => $stranger->id, 'role_key' => 'presiding_officer']],
        ])->assertStatus(422);

        $this->assertSame(2, $case->fresh()->activeMembers()->count());
    }

    public function test_reconstitution_refuses_a_role_from_another_committee(): void
    {
        [$case] = $this->raise(2);
        [$otherCommittee] = $this->committee(2);
        $role = HrPoshCommitteeRole::create([
            'tenant_id' => $this->tenant->id, 'committee_id' => $otherCommittee->id,
            'key' => 'foreign_role', 'label' => 'Foreign', 'can_manage_case' => true,
            'sort_order' => 0, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->staffWith(['hr_settings']));
        $this->postJson("/api/hr/posh-cases/{$case->id}/reconstitute", [
            'reason' => 'x',
            'members' => [['user_id' => $this->user()->id, 'role_key' => $role->key]],
        ])->assertStatus(422);
    }

    public function test_the_reconstitution_response_carries_no_case_content(): void
    {
        [$case, $people] = $this->raise(2);

        Sanctum::actingAs($this->staffWith(['hr_settings']));
        $body = $this->postJson("/api/hr/posh-cases/{$case->id}/reconstitute", [
            'reason' => 'x',
            'members' => [['user_id' => $people[0]->id, 'role_key' => 'presiding_officer']],
        ])->assertOk()->json();

        $encoded = json_encode($body);
        $this->assertStringNotContainsString('What happened.', $encoded);
        $this->assertStringNotContainsString('Respondent', $encoded);
    }

    /* ── reconstitution: self-nomination ──────────────────────────────── */

    public function test_a_reconstitutor_cannot_put_themselves_on_the_case(): void
    {
        [$case, $people] = $this->raise(2);
        $settings = $this->staffWith(['hr_settings']);

        Sanctum::actingAs($settings);
        $roster = $this->postJson("/api/hr/posh-cases/{$case->id}/reconstitute", [
            'reason' => 'Committee replaced.',
            'members' => [
                ['user_id' => $settings->id,   'role_key' => 'presiding_officer'],
                ['user_id' => $people[1]->id,  'role_key' => 'internal_member'],
            ],
        ])->assertOk()->json('data.members');

        // Their own entry is gone; the other is honoured.
        $stored = collect($roster)->pluck('user_id')->all();
        $this->assertNotContains($settings->id, $stored);
        $this->assertContains($people[1]->id, $stored);

        $this->assertFalse(
            HrPoshCaseMember::where('case_id', $case->id)
                ->where('user_id', $settings->id)->whereNull('removed_at')->exists()
        );

        // And the refusal is real, not cosmetic: still no access.
        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertStatus(404);
    }

    public function test_the_response_shows_the_roster_actually_stored(): void
    {
        [$case, $people] = $this->raise(2);
        $settings = $this->staffWith(['hr_settings']);

        Sanctum::actingAs($settings);
        $roster = $this->postJson("/api/hr/posh-cases/{$case->id}/reconstitute", [
            'reason' => 'Committee replaced.',
            'members' => [
                ['user_id' => $settings->id,  'role_key' => 'presiding_officer'],
                ['user_id' => $people[1]->id, 'role_key' => 'internal_member'],
            ],
        ])->assertOk()->json('data.members');

        // A dropped entry that the screen still displayed would be worse than
        // refusing outright — the caller would believe a committee exists that
        // the server never wrote.
        $this->assertSame(
            HrPoshCaseMember::where('case_id', $case->id)->whereNull('removed_at')
                ->pluck('user_id')->map(fn ($i) => (int) $i)->sort()->values()->all(),
            collect($roster)->pluck('user_id')->map(fn ($i) => (int) $i)->sort()->values()->all()
        );
    }

    public function test_a_roster_of_nothing_but_the_reconstitutor_is_refused(): void
    {
        [$case, $people] = $this->raise(2);
        $settings = $this->staffWith(['hr_settings']);

        Sanctum::actingAs($settings);
        $this->postJson("/api/hr/posh-cases/{$case->id}/reconstitute", [
            'reason' => 'Taking this one myself.',
            'members' => [['user_id' => $settings->id, 'role_key' => 'presiding_officer']],
        ])->assertStatus(422);

        // Nothing half-applied: the sitting committee is untouched rather than
        // revoked into an empty, unreadable case.
        $this->assertSame(2, $case->fresh()->activeMembers()->count());
        $this->assertSame(
            collect($people)->pluck('id')->sort()->values()->all(),
            $case->fresh()->activeMembers()->pluck('user_id')->map(fn ($i) => (int) $i)->sort()->values()->all()
        );

        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertStatus(404);
    }

    public function test_a_reconstitutor_who_is_already_a_member_keeps_their_seat(): void
    {
        [$case, $people, $committee] = $this->raise(2);

        // This person holds hr_settings AND sits on the case already.
        $sitting = $this->staffWith(['hr_settings']);
        $chairRole = HrPoshCommitteeRole::where('committee_id', $committee->id)
            ->where('key', 'presiding_officer')->firstOrFail();
        HrPoshCaseMember::grant(
            $case, $sitting->id, 'presiding_officer',
            HrPoshCaseMember::SOURCE_MANUAL, $people[0], 'Appointed.'
        );

        Sanctum::actingAs($sitting);
        $this->postJson("/api/hr/posh-cases/{$case->id}/reconstitute", [
            'reason' => 'Trimming the committee.',
            'members' => [
                ['user_id' => $sitting->id,   'role_key' => 'presiding_officer'],
                ['user_id' => $people[1]->id, 'role_key' => 'internal_member'],
            ],
        ])->assertOk();

        // Keeping a seat is not taking one. Revoking them for having submitted
        // the request would be the bug.
        $row = HrPoshCaseMember::where('case_id', $case->id)
            ->where('user_id', $sitting->id)->firstOrFail();
        $this->assertNull($row->removed_at);
        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertOk();

        // And no second period was opened beside the first.
        $this->assertSame(1, HrPoshCaseMember::where('case_id', $case->id)
            ->where('user_id', $sitting->id)->count());
    }

    public function test_a_reconstitutor_is_never_granted_membership_unasked(): void
    {
        [$case, $people] = $this->raise(2);
        $settings = $this->staffWith(['hr_settings']);

        Sanctum::actingAs($settings);
        $this->postJson("/api/hr/posh-cases/{$case->id}/reconstitute", [
            'reason' => 'Swap one member.',
            'members' => [
                ['user_id' => $people[0]->id, 'role_key' => 'presiding_officer'],
                ['user_id' => $people[1]->id, 'role_key' => 'internal_member'],
            ],
        ])->assertOk();

        $this->assertSame(0, HrPoshCaseMember::where('case_id', $case->id)
            ->where('user_id', $settings->id)->count());
        $this->getJson("/api/hr/posh-cases/{$case->id}")->assertStatus(404);
    }

    public function test_a_reconstitutor_cannot_reach_another_tenants_case(): void
    {
        // The case belongs to the other workspace entirely.
        [$theirCommittee, , , $theirPeople] = $this->committee(2, HrPoshCommittee::QUORUM_ALL, null, $this->other);

        Sanctum::actingAs($this->intaker($this->other));
        $theirCaseId = $this->postJson('/api/hr/posh-cases', [
            'committee_id' => $theirCommittee->id, 'narrative' => 'Theirs.',
        ])->assertStatus(201)->json('data.id');

        // hr_settings in MY workspace is not hr_settings in theirs, and naming
        // myself does not change that.
        $mine = $this->staffWith(['hr_settings']);
        Sanctum::actingAs($mine);

        $this->postJson("/api/hr/posh-cases/{$theirCaseId}/reconstitute", [
            'reason' => 'x',
            'members' => [
                ['user_id' => $mine->id, 'role_key' => 'presiding_officer'],
                ['user_id' => $theirPeople[1]->id, 'role_key' => 'internal_member'],
            ],
        ])->assertStatus(404);

        $this->assertSame(2, HrPoshCase::findOrFail($theirCaseId)->activeMembers()->count());
        $this->assertSame(0, HrPoshCaseMember::where('case_id', $theirCaseId)
            ->where('user_id', $mine->id)->count());
    }

    /* ── the procedural clock ─────────────────────────────────────────── */

    /**
     * All four dates persist and come back.
     *
     * Each was added to the migration and each was silently dropped, because
     * the columns were never added to $fillable — Eloquent discards an
     * unfillable attribute without a word. Every write path is exercised here
     * so a fifth date added later cannot repeat it.
     */
    public function test_every_procedural_date_persists(): void
    {
        [$case, $people] = $this->raise(2);

        // complaint_received_at — stamped at intake.
        $this->assertNotNull($case->complaint_received_at);

        Sanctum::actingAs($this->chair($people));

        // acknowledged_at — its own explicit act.
        $acknowledged = $this->patchJson("/api/hr/posh-cases/{$case->id}/acknowledge")
            ->assertOk()->json('data.acknowledged_at');
        $this->assertNotNull($acknowledged);

        // inquiry_started_at — stamped when the round opens.
        $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry")->assertOk();
        $this->assertNotNull($case->fresh()->inquiry_started_at);

        // inquiry_completed_at — stamped when it concludes.
        foreach ($people as $member) {
            Sanctum::actingAs($member);
            $this->postJson("/api/hr/posh-cases/{$case->id}/inquiry/decide", ['decision' => Decision::APPROVED])->assertOk();
        }

        $fresh = $case->fresh();
        $this->assertNotNull($fresh->inquiry_completed_at);

        // Read back as dates, not as strings that merely look like them.
        foreach ([
            'complaint_received_at', 'acknowledged_at',
            'inquiry_started_at', 'inquiry_completed_at',
        ] as $column) {
            $this->assertInstanceOf(
                \Illuminate\Support\Carbon::class, $fresh->{$column},
                "{$column} is not cast to a date"
            );
        }

        // And the clock runs forwards.
        $this->assertTrue($fresh->inquiry_completed_at->greaterThanOrEqualTo($fresh->inquiry_started_at));
        $this->assertTrue($fresh->inquiry_started_at->greaterThanOrEqualTo($fresh->complaint_received_at));
    }

    public function test_acknowledging_twice_is_refused(): void
    {
        [$case, $people] = $this->raise(2);

        Sanctum::actingAs($this->chair($people));
        $this->patchJson("/api/hr/posh-cases/{$case->id}/acknowledge")->assertOk();
        $this->patchJson("/api/hr/posh-cases/{$case->id}/acknowledge")->assertStatus(422);
    }

    /* ── read auditing ────────────────────────────────────────────────── */

    public function test_every_content_surface_is_read_audited(): void
    {
        \Storage::fake('local');
        [$case, $people] = $this->concluded();

        Sanctum::actingAs($people[1]);
        HrPoshCaseRead::where('case_id', $case->id)->delete();

        $this->getJson("/api/hr/posh-cases/{$case->id}/thread")->assertOk();
        $this->getJson("/api/hr/posh-cases/{$case->id}/attachments")->assertOk();
        $this->getJson("/api/hr/posh-cases/{$case->id}/inquiry")->assertOk();
        $this->getJson("/api/hr/posh-cases/{$case->id}/findings")->assertOk();

        $surfaces = HrPoshCaseRead::where('case_id', $case->id)->pluck('surface')->all();

        foreach (['case.thread', 'case.attachment', 'case.inquiry', 'case.findings'] as $expected) {
            $this->assertContains($expected, $surfaces);
        }
    }

    public function test_a_refused_read_is_never_audited(): void
    {
        [$case] = $this->concluded();
        HrPoshCaseRead::where('case_id', $case->id)->delete();

        Sanctum::actingAs($this->user(role: 'admin'));
        $this->getJson("/api/hr/posh-cases/{$case->id}/thread")->assertStatus(404);
        $this->getJson("/api/hr/posh-cases/{$case->id}/findings")->assertStatus(404);

        $this->assertSame(0, HrPoshCaseRead::where('case_id', $case->id)->count());
    }

    /* ── notifications ────────────────────────────────────────────────── */

    public function test_notifications_reach_members_and_carry_no_case_detail(): void
    {
        [$case, $people] = $this->raise(2);

        $rows = HrNotification::where('module', 'Posh')->get();

        $this->assertTrue($rows->isNotEmpty());
        foreach ($rows as $row) {
            // The reference and nothing else.
            $this->assertNotNull($row->recipient_user_id, 'POSH notifications are user-addressed only');
            $this->assertNull($row->recipient_role);
            $this->assertStringNotContainsString('What happened.', (string) $row->message);
            $this->assertStringNotContainsString('Respondent', (string) $row->message);
            $this->assertStringContainsString($case->reference, (string) $row->title.$row->message);
        }

        $recipients = $rows->pluck('recipient_user_id')->map(fn ($i) => (int) $i)->all();
        foreach ($people as $member) {
            $this->assertContains($member->id, $recipients);
        }
    }

    /* ── tenant isolation ─────────────────────────────────────────────── */

    public function test_every_case_route_refuses_another_tenant(): void
    {
        [$case] = $this->raise();
        $stranger = $this->user($this->other);

        Sanctum::actingAs($stranger);

        foreach ([
            "/api/hr/posh-cases/{$case->id}",
            "/api/hr/posh-cases/{$case->id}/thread",
            "/api/hr/posh-cases/{$case->id}/attachments",
            "/api/hr/posh-cases/{$case->id}/inquiry",
            "/api/hr/posh-cases/{$case->id}/findings",
        ] as $path) {
            $this->getJson($path)->assertStatus(404);
        }
    }
}
