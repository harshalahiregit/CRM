<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrPoshCase;
use App\Models\Hr\HrPoshCaseMember;
use App\Models\Hr\HrPoshCommittee;
use App\Models\Hr\HrPoshCommitteeMember;
use App\Models\Hr\HrPoshCommitteeRole;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\Posh\PoshAccessResolver;
use App\Support\Hr\DataScope;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who may read a POSH case.
 *
 * The answer is an active row in hr_posh_case_members and nothing else. Every
 * general-purpose authority in this product is excluded, and each exclusion
 * has the same reason behind it: a harassment complaint may name that person.
 * The administrator, the HR executive who runs the queue, the department head
 * whose scope would cover the complainant, the committee member who has not
 * been put on this case — each is a plausible respondent, and each would
 * otherwise be able to read the file about themselves.
 *
 * ScopeResolver is the sharpest case. Department scope would hand a complaint
 * to the complainant's own department head. The mechanism that protects every
 * other HR record is actively wrong here, which is why it is not consulted.
 *
 * The refusal is always the same 404 with the same message, whether the case
 * is absent, deleted, in another workspace, or simply not this person's.
 * Anything that distinguished them would answer "does case 41 exist", and on
 * this data that is itself a disclosure.
 */
class PoshCaseAccessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'posh-case-a', 'status' => 'active']);
        $this->other  = Tenant::create(['name' => 'Two', 'slug' => 'posh-case-b', 'status' => 'active']);
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

    private function staffWith(array $modules, string $scope = DataScope::GLOBAL): User
    {
        $permissions = [];
        foreach ($modules as $m) {
            $permissions[$m] = [StaffPermission::VIEW_GLOBAL];
        }

        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'R'.substr(uniqid(), -5),
            'slug' => 'r_'.substr(uniqid(), -5), 'permissions' => $permissions,
            'scope' => $scope, 'is_system' => false,
        ]);

        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'S', 'email' => uniqid().'@posh.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
            'staff_role_id' => $role->id,
        ]);
    }

    private function committee(?Tenant $t = null): HrPoshCommittee
    {
        return HrPoshCommittee::create([
            'tenant_id' => ($t ?: $this->tenant)->id, 'name' => 'ICC '.substr(uniqid(), -5),
            'quorum_mode' => HrPoshCommittee::QUORUM_ALL, 'is_active' => true, 'sort_order' => 0,
        ]);
    }

    private function poshCase(array $attrs = [], ?Tenant $t = null, ?HrPoshCommittee $committee = null): HrPoshCase
    {
        $t = $t ?: $this->tenant;
        $committee = $committee ?: $this->committee($t);

        return HrPoshCase::create(array_merge([
            'tenant_id' => $t->id, 'reference' => 'POSH-'.substr(uniqid(), -6),
            'committee_id' => $committee->id,
            'complainant_type' => HrPoshCase::COMPLAINANT_EMPLOYEE,
            'complainant_label' => 'Complainant',
            'respondent_label' => 'Respondent',
            'narrative' => 'What happened.',
            'status' => HrPoshCase::STATUS_RECEIVED,
        ], $attrs));
    }

    private function addMember(HrPoshCase $case, User $u, string $roleKey = 'internal_member'): HrPoshCaseMember
    {
        return HrPoshCaseMember::grant($case, $u->id, $roleKey);
    }

    private function show(HrPoshCase $case, User $actor)
    {
        Sanctum::actingAs($actor);

        return $this->getJson("/api/hr/posh-cases/{$case->id}");
    }

    /* ── the member ───────────────────────────────────────────────────── */

    public function test_an_active_case_member_can_read_the_case(): void
    {
        $case = $this->poshCase();
        $member = $this->user();
        $this->addMember($case, $member);

        $this->show($case, $member)
            ->assertOk()
            ->assertJsonPath('data.id', $case->id)
            ->assertJsonPath('data.reference', $case->reference);
    }

    public function test_a_member_can_read_the_current_roster(): void
    {
        $case = $this->poshCase();
        $member = $this->user();
        $this->addMember($case, $member, 'presiding_officer');

        Sanctum::actingAs($member);
        $this->getJson("/api/hr/posh-cases/{$case->id}/members")
            ->assertOk()
            ->assertJsonPath('data.0.user_id', $member->id)
            ->assertJsonPath('data.0.role_key', 'presiding_officer');
    }

    /* ── everybody else ───────────────────────────────────────────────── */

    public function test_a_removed_member_can_no_longer_read_the_case(): void
    {
        $case = $this->poshCase();
        $member = $this->user();
        $this->addMember($case, $member);
        $this->show($case, $member)->assertOk();

        HrPoshCaseMember::revoke($case, $member->id, null, 'Recused.');

        $this->show($case, $member)->assertStatus(404);
    }

    public function test_a_plain_non_member_is_refused(): void
    {
        $this->show($this->poshCase(), $this->user())->assertStatus(404);
    }

    public function test_an_administrator_who_is_not_a_member_is_refused(): void
    {
        // The complaint may be about them.
        $this->show($this->poshCase(), $this->user(role: 'admin'))->assertStatus(404);
    }

    public function test_an_hr_settings_holder_who_is_not_a_member_is_refused(): void
    {
        // Configuring committees is not reading complaints.
        $this->show($this->poshCase(), $this->staffWith(['hr_settings']))->assertStatus(404);
    }

    public function test_an_hr_queue_manager_who_is_not_a_member_is_refused(): void
    {
        $hr = $this->user(internal: 'hr_executive');
        $this->assertTrue($hr->canManageHrQueue(), 'fixture check');

        $this->show($this->poshCase(), $hr)->assertStatus(404);
    }

    public function test_a_globally_scoped_user_who_is_not_a_member_is_refused(): void
    {
        // ScopeResolver is not consulted at all. Global scope over every
        // employee record in the workspace still reads no POSH case.
        $this->show($this->poshCase(), $this->staffWith(['hr_employees'], DataScope::GLOBAL))
            ->assertStatus(404);
    }

    public function test_a_committee_member_who_is_not_a_case_member_is_refused(): void
    {
        $committee = $this->committee();
        $role = HrPoshCommitteeRole::create([
            'tenant_id' => $this->tenant->id, 'committee_id' => $committee->id,
            'key' => 'presiding_officer', 'label' => 'Presiding Officer',
            'can_manage_case' => true, 'sort_order' => 0, 'is_active' => true,
        ]);
        $onCommittee = $this->user();
        HrPoshCommitteeMember::create([
            'tenant_id' => $this->tenant->id, 'committee_id' => $committee->id,
            'role_id' => $role->id, 'user_id' => $onCommittee->id, 'is_active' => true,
        ]);

        $case = $this->poshCase(committee: $committee);

        // Sitting on the committee is not sitting on THIS case — and the role
        // even carries can_manage_case, which grants nothing on its own.
        $this->show($case, $onCommittee)->assertStatus(404);
    }

    public function test_the_respondent_is_refused(): void
    {
        $respondent = $this->user();
        $case = $this->poshCase(['respondent_employee_id' => $respondent->id]);

        // The respondent is case DATA, never a member.
        $this->show($case, $respondent)->assertStatus(404);
    }

    public function test_the_complainant_is_refused(): void
    {
        $complainant = $this->user();
        $case = $this->poshCase(['complainant_employee_id' => $complainant->id]);

        // Even an employee complainant with a normal login. Their surface is
        // separate and restricted, and it is not this one.
        $this->show($case, $complainant)->assertStatus(404);
    }

    public function test_the_creator_is_refused_unless_explicitly_added(): void
    {
        $creator = $this->user();
        $case = $this->poshCase(['created_by' => $creator->id]);

        $this->show($case, $creator)->assertStatus(404);

        // Only an explicit membership row changes that.
        $this->addMember($case, $creator);
        $this->show($case, $creator)->assertOk();
    }

    /* ── membership periods ───────────────────────────────────────────── */

    public function test_re_adding_somebody_opens_a_new_period_and_keeps_the_old(): void
    {
        $case = $this->poshCase();
        $member = $this->user();

        $this->addMember($case, $member);
        HrPoshCaseMember::revoke($case, $member->id, null, 'Stood down.');
        $this->addMember($case, $member, 'external_member');

        $periods = HrPoshCaseMember::where('case_id', $case->id)
            ->where('user_id', $member->id)->orderBy('id')->get();

        // Two rows, not one overwritten: the record of who could read the file
        // and when survives the re-admission.
        $this->assertCount(2, $periods);
        $this->assertNotNull($periods[0]->removed_at);
        $this->assertSame('Stood down.', $periods[0]->removed_reason);
        $this->assertNull($periods[1]->removed_at);

        $this->show($case, $member)->assertOk();
    }

    public function test_only_one_active_period_may_exist(): void
    {
        $case = $this->poshCase();
        $member = $this->user();
        $this->addMember($case, $member);

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->expectExceptionMessage('already has access');
        $this->addMember($case, $member);
    }

    public function test_revoking_leaves_the_historical_row_in_place(): void
    {
        $case = $this->poshCase();
        $member = $this->user();
        $this->addMember($case, $member);

        HrPoshCaseMember::revoke($case, $member->id, null, 'Reconstituted.');

        // Nothing is deleted. Access ends; the record of it does not.
        $this->assertSame(1, HrPoshCaseMember::where('case_id', $case->id)->count());
        $this->assertSame(0, $case->activeMembers()->count());
    }

    public function test_a_later_committee_joiner_gains_no_access_to_an_existing_case(): void
    {
        $committee = $this->committee();
        $case = $this->poshCase(committee: $committee);

        $role = HrPoshCommitteeRole::create([
            'tenant_id' => $this->tenant->id, 'committee_id' => $committee->id,
            'key' => 'internal_member', 'label' => 'Internal Member',
            'can_manage_case' => false, 'sort_order' => 0, 'is_active' => true,
        ]);
        $latecomer = $this->user();
        HrPoshCommitteeMember::create([
            'tenant_id' => $this->tenant->id, 'committee_id' => $committee->id,
            'role_id' => $role->id, 'user_id' => $latecomer->id, 'is_active' => true,
        ]);

        // Case membership is its own table. There is no mechanism by which
        // joining the committee could reach an open case.
        $this->show($case, $latecomer)->assertStatus(404);
    }

    public function test_the_role_key_is_held_by_value(): void
    {
        $case = $this->poshCase();
        $member = $this->user();
        $this->addMember($case, $member, 'presiding_officer');

        // Nothing points back at a committee role, so nothing done to one can
        // rename or revoke a case membership.
        $this->assertSame('presiding_officer',
            HrPoshCaseMember::where('case_id', $case->id)->value('role_key'));
    }

    /* ── tenant isolation ─────────────────────────────────────────────── */

    public function test_another_workspaces_case_is_not_found(): void
    {
        $theirs = $this->poshCase(t: $this->other);

        $this->show($theirs, $this->user())->assertStatus(404);
    }

    public function test_a_cross_tenant_membership_row_grants_nothing(): void
    {
        $theirs = $this->poshCase(t: $this->other);
        $mine = $this->user();

        // Written directly, as a corrupted row or bad import would be.
        HrPoshCaseMember::create([
            'tenant_id' => $this->tenant->id, 'case_id' => $theirs->id,
            'user_id' => $mine->id, 'role_key' => 'internal_member',
            'source' => HrPoshCaseMember::SOURCE_MANUAL, 'added_at' => now(),
        ]);

        $this->show($theirs, $mine)->assertStatus(404);
        $this->assertFalse(app(PoshAccessResolver::class)->isMember($mine, $theirs));
    }

    public function test_a_membership_row_cannot_reach_across_the_tenant_boundary(): void
    {
        // The row is stamped with the CASE's tenant, so it is internally
        // consistent — only the actor comes from elsewhere. This is what the
        // resolver's tenant check actually exists for, and without it the row
        // would look like perfectly good evidence of membership.
        $theirs = $this->poshCase(t: $this->other);
        $mine = $this->user();

        HrPoshCaseMember::create([
            'tenant_id' => $this->other->id, 'case_id' => $theirs->id,
            'user_id' => $mine->id, 'role_key' => 'internal_member',
            'source' => HrPoshCaseMember::SOURCE_MANUAL, 'added_at' => now(),
        ]);

        $this->assertFalse(app(PoshAccessResolver::class)->isMember($mine, $theirs));
        $this->show($theirs, $mine)->assertStatus(404);
    }

    public function test_a_user_from_another_tenant_cannot_read_a_case_here(): void
    {
        $case = $this->poshCase();
        $stranger = $this->user($this->other);

        $this->show($case, $stranger)->assertStatus(404);
    }

    /* ── indistinguishable refusals ───────────────────────────────────── */

    public function test_every_failure_looks_identical(): void
    {
        $realButNotMine = $this->poshCase();
        $anotherTenants = $this->poshCase(t: $this->other);
        $deleted = $this->poshCase();
        $deleted->delete();

        $outsider = $this->user();
        Sanctum::actingAs($outsider);

        $responses = [
            $this->getJson('/api/hr/posh-cases/999999'),
            $this->getJson("/api/hr/posh-cases/{$realButNotMine->id}"),
            $this->getJson("/api/hr/posh-cases/{$anotherTenants->id}"),
            $this->getJson("/api/hr/posh-cases/{$deleted->id}"),
        ];

        // Absent, present-but-not-mine, another workspace's, and deleted must
        // be indistinguishable — otherwise the difference answers "does this
        // case exist", which is itself a disclosure.
        foreach ($responses as $r) {
            $r->assertStatus(404);
            $this->assertSame('Case not found', $r->json('message'));
        }
    }

    public function test_a_refusal_never_names_the_case(): void
    {
        $case = $this->poshCase(['reference' => 'POSH-SECRET']);

        $body = $this->show($case, $this->user())->assertStatus(404)->json();

        $encoded = json_encode($body);
        $this->assertStringNotContainsString('POSH-SECRET', $encoded);
        $this->assertStringNotContainsString('What happened.', $encoded);
        $this->assertStringNotContainsString((string) $case->committee_id, $encoded);
    }

    /* ── surfaces that must not exist ─────────────────────────────────── */

    public function test_there_is_no_case_list_endpoint(): void
    {
        Sanctum::actingAs($this->user(role: 'admin'));

        // A list is an enumeration surface, and the existence of a case is
        // itself a disclosure.
        $this->getJson('/api/hr/posh-cases')->assertStatus(404);
    }

    public function test_no_write_surface_exists_in_this_phase(): void
    {
        $case = $this->poshCase();
        $before = $case->toArray();

        Sanctum::actingAs($this->user(role: 'admin'));

        // Whether the router answers 404 (no such route) or 405 (wrong verb
        // for one that exists) is a routing detail. The property that matters
        // is that none of these succeeds and none of them changes anything —
        // creating, editing and deleting a case all belong to later phases.
        foreach ([
            $this->postJson('/api/hr/posh-cases', ['narrative' => 'x']),
            $this->postJson("/api/hr/posh-cases/{$case->id}", []),
            $this->putJson("/api/hr/posh-cases/{$case->id}", ['narrative' => 'x']),
            $this->patchJson("/api/hr/posh-cases/{$case->id}", ['status' => 'closed']),
            $this->deleteJson("/api/hr/posh-cases/{$case->id}"),
        ] as $response) {
            $this->assertTrue(
                $response->status() >= 400,
                'No POSH case write surface may exist in this phase; got '.$response->status()
            );
        }

        $this->assertSame(1, HrPoshCase::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame($before['narrative'], $case->fresh()->narrative);
        $this->assertSame($before['status'], $case->fresh()->status);
    }

    public function test_the_members_endpoint_is_equally_protected(): void
    {
        $case = $this->poshCase();

        foreach ([
            $this->user(role: 'admin'),
            $this->staffWith(['hr_settings']),
            $this->user(internal: 'hr_executive'),
        ] as $outsider) {
            Sanctum::actingAs($outsider);
            $this->getJson("/api/hr/posh-cases/{$case->id}/members")->assertStatus(404);
        }
    }

    /* ── the resolver consults nothing else ───────────────────────────── */

    public function test_the_resolver_names_no_general_purpose_authority(): void
    {
        $source = file_get_contents(app_path('Services/Hr/Posh/PoshAccessResolver.php'));

        // The absence IS the design. Each of these is a plausible respondent
        // in a harassment case, so none of them may be a way in — and a test
        // that only checked behaviour would not notice one being added back
        // for a case the fixtures happen not to cover.
        foreach ([
            'StaffPermissionService',
            'ScopeResolver',
            'canManageHrQueue',
            'isAdmin',
            'HrPoshCommitteeMember',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden.'(', $source,
                "PoshAccessResolver must not consult {$forbidden}."
            );
        }
    }
}
