<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrPoshCommittee;
use App\Models\Hr\HrPoshCommitteeMember;
use App\Models\Hr\HrPoshCommitteeRole;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Hr\DataScope;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Configuring a POSH committee — composition, its own role vocabulary, and who
 * sits on it.
 *
 * NO STATUTORY MINIMUMS are asserted anywhere here, deliberately. A one-person
 * committee with a quorum of one is valid as far as this code is concerned.
 * What the law requires is a question for a lawyer, and a test that encoded a
 * guess would make that guess permanent.
 *
 * THE RULE THIS SUITE IS MOSTLY ABOUT: an ACTIVE committee must be able to
 * function — enough active members to meet its own quorum, at least one of them
 * able to open an inquiry. An edit that would break that is REFUSED, and the
 * refusal names the way out. An INACTIVE committee can be edited into any
 * state at all, because that is how one is built up in the first place.
 *
 * Configuration authority is hr_settings. That is emphatically NOT the future
 * case-access mechanism: StaffPermissionService carries an administrator
 * bypass, which is right for editing a master and would be exactly wrong for
 * reading a harassment complaint. No case data exists in this phase.
 */
class PoshCommitteeConfigTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'posh-a', 'status' => 'active']);
        $this->other  = Tenant::create(['name' => 'Two', 'slug' => 'posh-b', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function admin(?Tenant $tenant = null): User
    {
        return User::create([
            'tenant_id' => ($tenant ?: $this->tenant)->id, 'name' => 'Admin',
            'email' => uniqid().'@posh.test', 'password' => Hash::make('Password123!'),
            'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function user(?Tenant $tenant = null, string $role = 'staff', string $status = 'active'): User
    {
        return User::create([
            'tenant_id' => ($tenant ?: $this->tenant)->id, 'name' => 'U'.substr(uniqid(), -4),
            'email' => uniqid().'@posh.test', 'password' => Hash::make('Password123!'),
            'role' => $role, 'status' => $status,
        ]);
    }

    /**
     * Staff with the modules named, plus hr_attendance.
     *
     * hr_attendance is the route GROUP's gate, held by both users below, so the
     * only difference between them is hr_settings — otherwise the refusal would
     * come from the middleware and this controller's check could be missing.
     */
    private function staff(array $modules, string $slug): User
    {
        $permissions = ['hr_attendance' => [StaffPermission::VIEW_GLOBAL]];
        foreach ($modules as $m) {
            $permissions[$m] = [StaffPermission::VIEW_GLOBAL];
        }

        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst($slug), 'slug' => $slug,
            'permissions' => $permissions, 'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);

        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'S', 'email' => uniqid().'@posh.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
            'staff_role_id' => $role->id,
        ]);
    }

    private function committee(array $attrs = [], ?Tenant $tenant = null): HrPoshCommittee
    {
        return HrPoshCommittee::create(array_merge([
            'tenant_id' => ($tenant ?: $this->tenant)->id, 'name' => 'ICC '.substr(uniqid(), -5),
            'quorum_mode' => HrPoshCommittee::QUORUM_ALL, 'quorum_required' => null,
            'is_active' => false, 'sort_order' => 0,
        ], $attrs));
    }

    private function role(HrPoshCommittee $c, array $attrs = []): HrPoshCommitteeRole
    {
        return HrPoshCommitteeRole::create(array_merge([
            'tenant_id' => $c->tenant_id, 'committee_id' => $c->id,
            'key' => 'role_'.substr(uniqid(), -5), 'label' => 'Member',
            'can_manage_case' => false, 'sort_order' => 0, 'is_active' => true,
        ], $attrs));
    }

    private function member(HrPoshCommittee $c, HrPoshCommitteeRole $r, User $u, bool $active = true): HrPoshCommitteeMember
    {
        return HrPoshCommitteeMember::create([
            'tenant_id' => $c->tenant_id, 'committee_id' => $c->id,
            'role_id' => $r->id, 'user_id' => $u->id, 'is_active' => $active,
        ]);
    }

    /** A committee that satisfies every invariant, ready to be activated. */
    private function usableCommittee(array $attrs = []): array
    {
        $c = $this->committee($attrs);
        $manager = $this->role($c, ['label' => 'Presiding Officer', 'can_manage_case' => true]);
        $plain   = $this->role($c, ['label' => 'Internal Member']);
        $m1 = $this->member($c, $manager, $this->user());
        $m2 = $this->member($c, $plain, $this->user());

        return [$c->fresh(), $manager, $plain, $m1, $m2];
    }

    private function activate(HrPoshCommittee $c)
    {
        return $this->patchJson("/api/hr/posh-committees/{$c->id}/status", ['is_active' => true]);
    }

    /* ── permission ───────────────────────────────────────────────────── */

    public function test_an_hr_settings_holder_may_configure(): void
    {
        Sanctum::actingAs($this->staff(['hr_settings'], 'posh_cfg'));

        $this->getJson('/api/hr/posh-committees')->assertOk();
        $this->postJson('/api/hr/posh-committees', ['name' => 'ICC'])->assertStatus(201);
    }

    public function test_hr_access_without_hr_settings_cannot_configure(): void
    {
        $c = $this->committee();

        Sanctum::actingAs($this->staff(['hr_employees'], 'posh_nocfg'));

        $this->getJson('/api/hr/posh-committees')->assertStatus(403);
        $this->postJson('/api/hr/posh-committees', ['name' => 'ICC'])->assertStatus(403);
        $this->putJson("/api/hr/posh-committees/{$c->id}", ['name' => 'Mine'])->assertStatus(403);
        $this->patchJson("/api/hr/posh-committees/{$c->id}/status", ['is_active' => true])->assertStatus(403);
        $this->deleteJson("/api/hr/posh-committees/{$c->id}")->assertStatus(403);
        $this->postJson("/api/hr/posh-committees/{$c->id}/roles", ['label' => 'X'])->assertStatus(403);
        $this->putJson("/api/hr/posh-committees/{$c->id}/members", ['members' => []])->assertStatus(403);

        $this->assertDatabaseHas('hr_posh_committees', ['id' => $c->id, 'is_active' => false]);
    }

    /* ── V1-V3: committee basics ──────────────────────────────────────── */

    public function test_v1_a_committee_needs_a_unique_non_empty_name(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/hr/posh-committees', ['name' => '   '])->assertStatus(422);
        $this->postJson('/api/hr/posh-committees', ['name' => 'ICC'])->assertStatus(201);
        $this->postJson('/api/hr/posh-committees', ['name' => 'ICC'])->assertStatus(422);
    }

    public function test_v2_an_unknown_quorum_mode_is_refused(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/hr/posh-committees', ['name' => 'ICC', 'quorum_mode' => 'majority'])
            ->assertStatus(422);
    }

    public function test_v3_a_set_number_quorum_must_be_at_least_one(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/hr/posh-committees', [
            'name' => 'ICC', 'quorum_mode' => HrPoshCommittee::QUORUM_N_OF_M, 'quorum_required' => 0,
        ])->assertStatus(422);
    }

    public function test_all_members_mode_stores_no_quorum_number(): void
    {
        Sanctum::actingAs($this->admin());

        // "Everybody" is not a number, and keeping one beside it would let the
        // two disagree.
        $this->postJson('/api/hr/posh-committees', [
            'name' => 'ICC', 'quorum_mode' => HrPoshCommittee::QUORUM_ALL, 'quorum_required' => 4,
        ])->assertStatus(201)->assertJsonPath('data.quorum_required', null);
    }

    public function test_a_new_committee_is_never_born_active(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/hr/posh-committees', ['name' => 'ICC'])
            ->assertStatus(201)->assertJsonPath('data.is_active', false);
    }

    /* ── V4-V6: the active-committee invariants ───────────────────────── */

    public function test_v6_activation_is_refused_without_an_active_member(): void
    {
        $c = $this->committee();
        $this->role($c, ['can_manage_case' => true]);

        Sanctum::actingAs($this->admin());
        $response = $this->activate($c);

        $response->assertStatus(422);
        $this->assertStringContainsString('at least one active member', $response->json('message'));
        $this->assertFalse($c->fresh()->is_active);
    }

    public function test_v5_activation_is_refused_without_a_case_managing_member(): void
    {
        $c = $this->committee();
        $plain = $this->role($c, ['label' => 'Internal Member']);
        $this->member($c, $plain, $this->user());

        Sanctum::actingAs($this->admin());
        $response = $this->activate($c);

        $response->assertStatus(422);
        $this->assertStringContainsString('can manage cases', $response->json('message'));
    }

    public function test_v5_a_case_managing_role_nobody_holds_does_not_count(): void
    {
        $c = $this->committee();
        $this->role($c, ['label' => 'Presiding Officer', 'can_manage_case' => true]);   // vacant
        $plain = $this->role($c, ['label' => 'Internal Member']);
        $this->member($c, $plain, $this->user());

        Sanctum::actingAs($this->admin());

        // A seat title with nobody in it is the same as not having one.
        $this->activate($c)->assertStatus(422);
    }

    public function test_v5_an_inactive_case_managing_member_does_not_count(): void
    {
        $c = $this->committee();
        $manager = $this->role($c, ['can_manage_case' => true]);
        $this->member($c, $manager, $this->user(), active: false);
        $plain = $this->role($c, ['label' => 'Internal Member']);
        $this->member($c, $plain, $this->user());

        Sanctum::actingAs($this->admin());
        $this->activate($c)->assertStatus(422);
    }

    public function test_v4_activation_is_refused_when_the_quorum_exceeds_the_roster(): void
    {
        [$c] = $this->usableCommittee([
            'quorum_mode' => HrPoshCommittee::QUORUM_N_OF_M, 'quorum_required' => 5,
        ]);

        Sanctum::actingAs($this->admin());
        $response = $this->activate($c);

        $response->assertStatus(422);
        $this->assertStringContainsString('quorum of 5 cannot be met by 2', $response->json('message'));
    }

    public function test_a_usable_committee_activates(): void
    {
        [$c] = $this->usableCommittee();

        Sanctum::actingAs($this->admin());
        $this->activate($c)->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_a_one_person_committee_with_quorum_one_is_valid(): void
    {
        $c = $this->committee([
            'quorum_mode' => HrPoshCommittee::QUORUM_N_OF_M, 'quorum_required' => 1,
        ]);
        $manager = $this->role($c, ['can_manage_case' => true]);
        $this->member($c, $manager, $this->user());

        Sanctum::actingAs($this->admin());

        // No statutory minimum is encoded. Whether this is lawful is a question
        // for a lawyer, not for this code.
        $this->activate($c)->assertOk();
    }

    /* ── R-3a: continuous invariants on an ACTIVE committee ───────────── */

    public function test_the_last_case_managing_member_cannot_be_removed_while_active(): void
    {
        [$c, $manager, $plain, $m1, $m2] = $this->usableCommittee();
        Sanctum::actingAs($this->admin());
        $this->activate($c)->assertOk();

        $response = $this->putJson("/api/hr/posh-committees/{$c->id}/members", [
            'members' => [['user_id' => $m2->user_id, 'role_id' => $plain->id]],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Deactivate', $response->json('message'));
        // Refused means unchanged — the transaction unwound.
        $this->assertSame(2, $c->fresh()->members()->count());
    }

    public function test_the_case_managing_role_cannot_be_switched_off_while_active(): void
    {
        [$c, $manager] = $this->usableCommittee();
        Sanctum::actingAs($this->admin());
        $this->activate($c)->assertOk();

        $this->putJson("/api/hr/posh-committees/{$c->id}/roles/{$manager->id}", [
            'can_manage_case' => false,
        ])->assertStatus(422);

        $this->assertTrue($manager->fresh()->can_manage_case, 'the change must have rolled back');
    }

    public function test_the_quorum_cannot_be_raised_above_the_active_roster(): void
    {
        [$c] = $this->usableCommittee();
        Sanctum::actingAs($this->admin());
        $this->activate($c)->assertOk();

        $response = $this->putJson("/api/hr/posh-committees/{$c->id}", [
            'quorum_mode' => HrPoshCommittee::QUORUM_N_OF_M, 'quorum_required' => 4,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('quorum of 4 cannot be met by 2', $response->json('message'));
        $this->assertSame(HrPoshCommittee::QUORUM_ALL, $c->fresh()->quorum_mode);
    }

    public function test_the_last_active_member_cannot_be_removed_while_active(): void
    {
        $c = $this->committee([
            'quorum_mode' => HrPoshCommittee::QUORUM_N_OF_M, 'quorum_required' => 1,
        ]);
        $manager = $this->role($c, ['can_manage_case' => true]);
        $this->member($c, $manager, $this->user());

        Sanctum::actingAs($this->admin());
        $this->activate($c)->assertOk();

        $this->putJson("/api/hr/posh-committees/{$c->id}/members", ['members' => []])
            ->assertStatus(422);
    }

    public function test_deactivating_the_committee_first_makes_the_same_edit_allowed(): void
    {
        [$c, $manager, $plain, $m1, $m2] = $this->usableCommittee();
        Sanctum::actingAs($this->admin());
        $this->activate($c)->assertOk();

        // The way out that every refusal names.
        $this->patchJson("/api/hr/posh-committees/{$c->id}/status", ['is_active' => false])->assertOk();

        $this->putJson("/api/hr/posh-committees/{$c->id}/members", [
            'members' => [['user_id' => $m2->user_id, 'role_id' => $plain->id]],
        ])->assertOk();
    }

    public function test_an_inactive_committee_may_be_edited_into_an_unusable_state(): void
    {
        [$c] = $this->usableCommittee();

        Sanctum::actingAs($this->admin());

        // Never activated, so nothing to protect. This is how a committee gets
        // built up in the first place.
        $this->putJson("/api/hr/posh-committees/{$c->id}/members", ['members' => []])->assertOk();
        $this->putJson("/api/hr/posh-committees/{$c->id}", [
            'quorum_mode' => HrPoshCommittee::QUORUM_N_OF_M, 'quorum_required' => 9,
        ])->assertOk();

        // And it simply cannot be switched on until it is repaired.
        $this->activate($c->fresh())->assertStatus(422);
    }

    public function test_the_listing_reports_what_blocks_activation(): void
    {
        $c = $this->committee();
        $this->role($c, ['can_manage_case' => true]);

        Sanctum::actingAs($this->admin());
        $rows = $this->getJson('/api/hr/posh-committees')->assertOk()->json('data.committees');

        // The screen can say what is missing rather than waiting for a failed
        // save to explain it.
        $this->assertNotEmpty($rows[0]['blockers']);
        $this->assertStringContainsString('at least one active member', $rows[0]['blockers'][0]);
    }

    public function test_a_usable_committee_reports_no_blockers(): void
    {
        $this->usableCommittee();

        Sanctum::actingAs($this->admin());
        $rows = $this->getJson('/api/hr/posh-committees')->assertOk()->json('data.committees');

        $this->assertSame([], $rows[0]['blockers']);
    }

    /* ── V9: roles ────────────────────────────────────────────────────── */

    public function test_v9_a_role_needs_a_label_and_a_unique_key(): void
    {
        $c = $this->committee();
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/hr/posh-committees/{$c->id}/roles", ['label' => '  '])->assertStatus(422);
        $this->postJson("/api/hr/posh-committees/{$c->id}/roles", ['label' => 'Presiding Officer'])
            ->assertStatus(201);
        $this->postJson("/api/hr/posh-committees/{$c->id}/roles", ['label' => 'Presiding Officer'])
            ->assertStatus(422);
    }

    public function test_the_same_role_key_may_exist_on_two_committees(): void
    {
        $a = $this->committee(['name' => 'ICC A']);
        $b = $this->committee(['name' => 'ICC B']);

        Sanctum::actingAs($this->admin());

        // The vocabulary belongs to the committee, not to the workspace.
        $this->postJson("/api/hr/posh-committees/{$a->id}/roles", ['label' => 'Presiding Officer'])->assertStatus(201);
        $this->postJson("/api/hr/posh-committees/{$b->id}/roles", ['label' => 'Presiding Officer'])->assertStatus(201);
    }

    public function test_can_manage_case_defaults_off(): void
    {
        $c = $this->committee();
        Sanctum::actingAs($this->admin());

        $data = $this->postJson("/api/hr/posh-committees/{$c->id}/roles", ['label' => 'Internal Member'])
            ->assertStatus(201)->json('data.roles');

        $this->assertFalse($data[0]['can_manage_case']);
    }

    public function test_a_role_with_members_cannot_be_deleted(): void
    {
        $c = $this->committee();
        $r = $this->role($c);
        $this->member($c, $r, $this->user());

        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/hr/posh-committees/{$c->id}/roles/{$r->id}")->assertStatus(422);
        $this->assertDatabaseHas('hr_posh_committee_roles', ['id' => $r->id]);
    }

    /* ── V7-V8: members ───────────────────────────────────────────────── */

    public function test_v8_an_inactive_or_portal_account_cannot_be_a_member(): void
    {
        $c = $this->committee();
        $r = $this->role($c, ['can_manage_case' => true]);

        Sanctum::actingAs($this->admin());

        foreach ([$this->user(status: 'inactive'), $this->user(role: 'client')] as $bad) {
            $this->putJson("/api/hr/posh-committees/{$c->id}/members", [
                'members' => [['user_id' => $bad->id, 'role_id' => $r->id]],
            ])->assertStatus(422);
        }

        $this->assertSame(0, $c->fresh()->members()->count());
    }

    public function test_v7_a_role_from_another_committee_is_refused(): void
    {
        $mine = $this->committee(['name' => 'ICC A']);
        $other = $this->committee(['name' => 'ICC B']);
        $theirRole = $this->role($other);

        Sanctum::actingAs($this->admin());

        $this->putJson("/api/hr/posh-committees/{$mine->id}/members", [
            'members' => [['user_id' => $this->user()->id, 'role_id' => $theirRole->id]],
        ])->assertStatus(422);
    }

    public function test_one_person_cannot_hold_two_seats(): void
    {
        $c = $this->committee();
        $a = $this->role($c, ['label' => 'Presiding Officer', 'can_manage_case' => true]);
        $b = $this->role($c, ['label' => 'Internal Member']);
        $person = $this->user();

        Sanctum::actingAs($this->admin());

        // Two seats would count twice towards a quorum, which is a quiet way
        // of lowering it.
        $this->putJson("/api/hr/posh-committees/{$c->id}/members", [
            'members' => [
                ['user_id' => $person->id, 'role_id' => $a->id],
                ['user_id' => $person->id, 'role_id' => $b->id],
            ],
        ])->assertStatus(422);
    }

    public function test_members_are_replaced_wholesale_and_an_invalid_entry_refuses_the_whole_set(): void
    {
        $c = $this->committee();
        $r = $this->role($c, ['can_manage_case' => true]);
        $good = $this->user();
        $bad = $this->user($this->other);

        Sanctum::actingAs($this->admin());

        // Not silently skipped: one bad entry refuses the call, so the screen
        // can never show a committee the server did not store.
        $this->putJson("/api/hr/posh-committees/{$c->id}/members", [
            'members' => [
                ['user_id' => $good->id, 'role_id' => $r->id],
                ['user_id' => $bad->id, 'role_id' => $r->id],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, $c->fresh()->members()->count());
    }

    /* ── tenant isolation ─────────────────────────────────────────────── */

    public function test_another_workspaces_committee_is_invisible_and_uneditable(): void
    {
        $theirs = $this->committee(['name' => 'Theirs'], $this->other);

        Sanctum::actingAs($this->admin());

        $names = array_column(
            $this->getJson('/api/hr/posh-committees')->assertOk()->json('data.committees'), 'name'
        );
        $this->assertNotContains('Theirs', $names);

        $this->putJson("/api/hr/posh-committees/{$theirs->id}", ['name' => 'Mine'])->assertStatus(404);
        $this->deleteJson("/api/hr/posh-committees/{$theirs->id}")->assertStatus(404);
        $this->assertSame('Theirs', $theirs->fresh()->name);
    }

    public function test_another_workspaces_role_cannot_be_edited_through_my_committee(): void
    {
        $mine = $this->committee();
        $theirs = $this->committee([], $this->other);
        $theirRole = $this->role($theirs);

        Sanctum::actingAs($this->admin());

        $this->putJson("/api/hr/posh-committees/{$mine->id}/roles/{$theirRole->id}", ['label' => 'X'])
            ->assertStatus(404);
    }

    public function test_a_cross_tenant_user_cannot_be_made_a_member(): void
    {
        $c = $this->committee();
        $r = $this->role($c);
        $stranger = $this->user($this->other);

        Sanctum::actingAs($this->admin());

        $this->putJson("/api/hr/posh-committees/{$c->id}/members", [
            'members' => [['user_id' => $stranger->id, 'role_id' => $r->id]],
        ])->assertStatus(422);

        $this->assertSame(0, $c->fresh()->members()->count());
    }

    /* ── delete ───────────────────────────────────────────────────────── */

    public function test_deleting_a_committee_takes_its_roles_and_members_with_it(): void
    {
        [$c, $manager] = $this->usableCommittee();
        $roleIds = $c->roles->pluck('id')->all();

        Sanctum::actingAs($this->admin());
        $this->deleteJson("/api/hr/posh-committees/{$c->id}")->assertOk();

        $this->assertDatabaseMissing('hr_posh_committees', ['id' => $c->id]);
        $this->assertSame(0, HrPoshCommitteeRole::whereIn('id', $roleIds)->count());
        $this->assertSame(0, HrPoshCommitteeMember::where('committee_id', $c->id)->count());
    }

    public function test_deleting_one_committee_leaves_another_alone(): void
    {
        [$a] = $this->usableCommittee(['name' => 'ICC A']);
        [$b] = $this->usableCommittee(['name' => 'ICC B']);

        Sanctum::actingAs($this->admin());
        $this->deleteJson("/api/hr/posh-committees/{$a->id}")->assertOk();

        $this->assertDatabaseHas('hr_posh_committees', ['id' => $b->id]);
        $this->assertSame(2, HrPoshCommitteeRole::where('committee_id', $b->id)->count());
    }

    /* ── ordering ─────────────────────────────────────────────────────── */

    public function test_committees_are_listed_in_creation_order(): void
    {
        Sanctum::actingAs($this->admin());

        foreach (['First', 'Second', 'Third'] as $name) {
            $this->postJson('/api/hr/posh-committees', ['name' => $name])->assertStatus(201);
        }

        $names = array_column(
            $this->getJson('/api/hr/posh-committees')->assertOk()->json('data.committees'), 'name'
        );
        $this->assertSame(['First', 'Second', 'Third'], $names);
    }

    /* ── scope boundary ───────────────────────────────────────────────── */

    public function test_this_phase_exposes_no_case_surface(): void
    {
        Sanctum::actingAs($this->admin());

        // 3a is configuration only. Case, token and access endpoints belong to
        // later sub-phases and must not exist yet.
        foreach ([
            '/api/hr/posh-cases',
            '/api/hr/posh-cases/1',
            '/api/hr/posh/public/sometoken',
        ] as $path) {
            $this->getJson($path)->assertStatus(404);
        }
    }
}
