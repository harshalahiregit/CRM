<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrClearanceDepartment;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrExitClearance;
use App\Models\Hr\HrExitClearanceItem;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\Clearance\ClearanceAuthorityResolver;
use App\Support\Hr\DataScope;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who may clear which department.
 *
 * One canManageHrQueue() check used to cover all five departments identically,
 * so anybody on the HR queue could clear IT, Finance or the reporting
 * manager's item. The only other candidate in the schema was
 * hr_exit_clearance_items.assigned_to — a free-text name copied from
 * reporting_manager_name, which is a label and authorises nothing.
 *
 * Three states, and the distinction between the last two is the design:
 *
 *   FALLBACK      nobody configured → the HR queue acts, exactly as before.
 *                 Every department starts here, which is what makes this safe
 *                 on deploy.
 *   CONFIGURED    somebody configured → ONLY the resolved union acts. Not the
 *                 HR queue, and not an administrator: naming an authority
 *                 means nothing if anyone senior can act anyway.
 *   MISCONFIGURED configured, and none of them can currently act → refuses and
 *                 says what is wrong. It does NOT drift back to the HR queue,
 *                 because silently re-widening authority is how a control
 *                 stops being one.
 *
 * Authority and scope stay separate throughout. This decides WHO may perform a
 * clearance action; ScopeResolver decides WHOSE records they may perform it
 * on, and runs first.
 */
class ClearanceDepartmentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'clr-auth', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function user(string $role = 'staff', ?string $internal = null, ?Tenant $tenant = null, string $status = 'active'): User
    {
        return User::create([
            'tenant_id' => ($tenant ?: $this->tenant)->id, 'name' => 'U'.substr(uniqid(), -4),
            'email' => uniqid().'@clr.test', 'password' => Hash::make('Password123!'),
            'role' => $role, 'status' => $status, 'internal_role' => $internal,
        ]);
    }

    /** Somebody on the HR queue — the fallback authority. */
    private function hrQueueUser(): User
    {
        $u = $this->user('staff', 'hr_executive');
        $this->assertTrue($u->canManageHrQueue(), 'fixture check');

        return $u;
    }

    private function scopedUser(string $scope, array $modules = ['hr_employees']): User
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
            'tenant_id' => $this->tenant->id, 'name' => 'S', 'email' => uniqid().'@clr.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff',
            'status' => 'active', 'staff_role_id' => $role->id,
        ]);
    }

    private function employee(?User $user = null, string $dept = 'Ops', ?Tenant $tenant = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => ($tenant ?: $this->tenant)->id, 'name' => 'E'.substr(uniqid(), -5),
            'employee_code' => 'E'.substr(uniqid(), -6), 'department' => $dept,
            'designation' => 'Analyst', 'joining_date' => '2024-01-01', 'status' => 'Active',
            'user_id' => $user?->id,
        ]);
    }

    /** A clearance with the five standard items, on a real exit request. */
    private function clearance(?HrEmployee $employee = null, ?Tenant $tenant = null): HrExitClearance
    {
        $tenant   = $tenant ?: $this->tenant;
        $employee = $employee ?: $this->employee(null, 'Ops', $tenant);

        $type = \App\Models\Hr\HrExitType::firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'RES'],
            ['name' => 'Resignation', 'notice_required' => true, 'default_notice_days' => 30,
             'clearance_required' => true, 'fnf_required' => true,
             'exit_interview_required' => false, 'is_active' => true]
        );

        $request = \App\Models\Hr\HrExitRequest::create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id,
            'exit_type_id' => $type->id, 'request_date' => '2026-09-01',
            'notice_days' => 30, 'status' => \App\Models\Hr\HrExitRequest::APPROVED,
        ]);

        $clearance = HrExitClearance::create([
            'tenant_id' => $tenant->id, 'exit_request_id' => $request->id,
            'employee_id' => $employee->id, 'status' => HrExitClearance::PENDING,
        ]);

        foreach (HrExitClearanceItem::DEPARTMENTS as $dept) {
            HrExitClearanceItem::create([
                'tenant_id' => $tenant->id, 'clearance_id' => $clearance->id,
                'department' => $dept, 'is_mandatory' => true,
                'status' => HrExitClearanceItem::PENDING,
            ]);
        }

        return $clearance->fresh(['items']);
    }

    private function itemFor(HrExitClearance $clearance, string $department): HrExitClearanceItem
    {
        return $clearance->items()->where('department', $department)->firstOrFail();
    }

    /** Seed the five departments, as the migration does for existing tenants. */
    private function seedDepartments(?Tenant $tenant = null): void
    {
        $tenant = $tenant ?: $this->tenant;
        $order = 0;
        foreach (HrExitClearanceItem::DEPARTMENTS as $name) {
            HrClearanceDepartment::create([
                'tenant_id' => $tenant->id, 'name' => $name,
                'is_mandatory' => true, 'sort_order' => $order++, 'is_active' => true,
            ]);
        }
    }

    private function department(string $name, ?Tenant $tenant = null): HrClearanceDepartment
    {
        return HrClearanceDepartment::where('tenant_id', ($tenant ?: $this->tenant)->id)
            ->where('name', $name)->firstOrFail();
    }

    private function resolver(): ClearanceAuthorityResolver
    {
        return app(ClearanceAuthorityResolver::class);
    }

    /** Drive the real endpoint — start is the first mutation on an item. */
    private function start(HrExitClearance $clearance, string $department, User $actor)
    {
        Sanctum::actingAs($actor);
        $item = $this->itemFor($clearance, $department);

        return $this->patchJson("/api/hr/exit/clearances/{$clearance->id}/items/{$item->id}/start", []);
    }

    /* ── 1-2. fallback and configured ─────────────────────────────────── */

    public function test_1_an_hr_queue_user_can_still_operate_a_fallback_department(): void
    {
        $this->seedDepartments();
        $clearance = $this->clearance();

        // Nothing configured anywhere — exactly the state after deploy.
        $this->start($clearance, 'IT', $this->hrQueueUser())->assertOk();
    }

    public function test_2_a_configured_user_operates_without_the_hr_queue(): void
    {
        $this->seedDepartments();
        $it = $this->user('staff', null);
        $this->assertFalse($it->canManageHrQueue(), 'fixture check: not on the HR queue');

        $this->department('IT')->users()->attach($it->id, ['tenant_id' => $this->tenant->id]);

        // The whole point: the IT technician clears IT without being given
        // authority over the rest of HR.
        $this->start($this->clearance(), 'IT', $it)->assertOk();
    }

    public function test_3_a_configured_user_cannot_operate_a_different_department(): void
    {
        $this->seedDepartments();
        $it = $this->user('staff', null);
        $this->department('IT')->users()->attach($it->id, ['tenant_id' => $this->tenant->id]);

        // Finance is still in fallback, and this person is not on the HR queue.
        $this->start($this->clearance(), 'Finance', $it)->assertStatus(403);
    }

    /* ── 4-6. staff roles and the union ───────────────────────────────── */

    public function test_4_a_member_of_a_configured_staff_role_can_operate(): void
    {
        $this->seedDepartments();
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'IT Support', 'slug' => 'it_support',
            'permissions' => [], 'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);
        $member = $this->user('staff', null);
        $member->update(['staff_role_id' => $role->id]);

        $this->department('IT')->roles()->attach($role->id, ['tenant_id' => $this->tenant->id]);

        $this->start($this->clearance(), 'IT', $member->fresh())->assertOk();
    }

    public function test_5_explicit_users_and_roles_are_a_union_not_an_intersection(): void
    {
        $this->seedDepartments();
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'IT Support', 'slug' => 'it_support2',
            'permissions' => [], 'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);

        $namedOnly  = $this->user('staff', null);
        $roleOnly   = $this->user('staff', null);
        $roleOnly->update(['staff_role_id' => $role->id]);

        $it = $this->department('IT');
        $it->users()->attach($namedOnly->id, ['tenant_id' => $this->tenant->id]);
        $it->roles()->attach($role->id, ['tenant_id' => $this->tenant->id]);

        // BOTH can act. A role exists so an organisation need not list every
        // individual; naming one person as well must not cancel that out.
        $eligible = $this->resolver()->authorizedUserIds($this->tenant->id, 'IT');
        $this->assertContains($namedOnly->id, $eligible);
        $this->assertContains($roleOnly->fresh()->id, $eligible);

        $this->start($this->clearance(), 'IT', $namedOnly)->assertOk();
        $this->start($this->clearance(), 'IT', $roleOnly->fresh())->assertOk();
    }

    public function test_6_a_non_member_of_the_configured_role_cannot_operate(): void
    {
        $this->seedDepartments();
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'IT Support', 'slug' => 'it_support3',
            'permissions' => [], 'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);
        $this->department('IT')->roles()->attach($role->id, ['tenant_id' => $this->tenant->id]);

        // The role must actually HAVE somebody, or the department is
        // misconfigured and everybody is refused for that reason instead —
        // which would make this test pass without proving anything about
        // membership.
        $member = $this->user('staff', null);
        $member->update(['staff_role_id' => $role->id]);

        $outsider = $this->user('staff', null);   // no staff_role_id

        $this->start($this->clearance(), 'IT', $outsider)->assertStatus(403);
        $this->start($this->clearance(), 'IT', $member->fresh())->assertOk();
    }

    /* ── 7. account quality ───────────────────────────────────────────── */

    public function test_7_inactive_and_portal_accounts_never_become_authorities(): void
    {
        $this->seedDepartments();

        $inactive = $this->user('staff', null, status: 'inactive');
        $client   = $this->user('client', null);

        $it = $this->department('IT');
        $it->users()->attach([$inactive->id, $client->id], ['tenant_id' => $this->tenant->id]);

        // Both are configured, and neither is eligible — so the department is
        // misconfigured rather than authorising either of them.
        $this->assertSame([], $this->resolver()->authorizedUserIds($this->tenant->id, 'IT'));
        $this->assertSame(
            ClearanceAuthorityResolver::MODE_MISCONFIGURED,
            $this->resolver()->mode($this->tenant->id, 'IT')
        );
    }

    /* ── 8. cross tenant ──────────────────────────────────────────────── */

    public function test_8_a_cross_tenant_user_cannot_authorize(): void
    {
        $this->seedDepartments();
        $other = Tenant::create(['name' => 'Other', 'slug' => 'clr-other', 'status' => 'active']);
        $stranger = $this->user('staff', null, $other);

        // Written directly, as a corrupted row or a bad import would be — the
        // service refuses this on write, so the resolver is the second line.
        $this->department('IT')->users()->attach($stranger->id, ['tenant_id' => $this->tenant->id]);

        $this->assertSame([], $this->resolver()->authorizedUserIds($this->tenant->id, 'IT'));
    }

    /* ── 9-15. scope stays a separate question ────────────────────────── */

    public function test_9_a_configured_authority_outside_employee_scope_is_still_refused(): void
    {
        $this->seedDepartments();

        // Department-scoped to Ops, but the leaver is in Sales.
        $auth = $this->scopedUser(DataScope::DEPARTMENT);
        $this->employee($auth, 'Ops');
        $leaver = $this->employee(null, 'Sales');

        $this->department('IT')->users()->attach($auth->id, ['tenant_id' => $this->tenant->id]);

        // Authority over IT does not confer sight of another department's
        // people. Scope runs first and answers 404, not 403.
        $this->start($this->clearance($leaver), 'IT', $auth)->assertStatus(404);
    }

    public function test_10_a_global_scoped_authority_can_act(): void
    {
        $this->seedDepartments();
        $auth = $this->scopedUser(DataScope::GLOBAL);
        $this->department('IT')->users()->attach($auth->id, ['tenant_id' => $this->tenant->id]);

        $this->start($this->clearance($this->employee(null, 'Sales')), 'IT', $auth)->assertOk();
    }

    public function test_11_a_department_scoped_authority_acts_within_its_department(): void
    {
        $this->seedDepartments();
        $auth = $this->scopedUser(DataScope::DEPARTMENT);
        $this->employee($auth, 'Ops');
        $leaver = $this->employee(null, 'Ops');

        $this->department('IT')->users()->attach($auth->id, ['tenant_id' => $this->tenant->id]);

        $this->start($this->clearance($leaver), 'IT', $auth)->assertOk();
    }

    public function test_12_a_branch_scoped_authority_is_not_narrowed_on_a_clearance(): void
    {
        $this->seedDepartments();
        $auth = $this->scopedUser(DataScope::BRANCH);
        $me = $this->employee($auth, 'Ops');
        $me->update(['branch' => 'North']);

        $leaver = $this->employee(null, 'Sales');
        $leaver->update(['branch' => 'South']);

        $this->department('IT')->users()->attach($auth->id, ['tenant_id' => $this->tenant->id]);

        // BRANCH is excluded from collection scoping everywhere — branch is a
        // free-text column with no master behind it — so an unsupported scope
        // reads as global. Asserted so wiring the master later is a decision.
        $this->start($this->clearance($leaver), 'IT', $auth)->assertOk();
    }

    public function test_13_a_team_scoped_authority_acts_for_its_reports(): void
    {
        $this->seedDepartments();
        $auth = $this->scopedUser(DataScope::TEAM);
        $me = $this->employee($auth, 'Ops');

        $report = HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'employee_code' => 'T-9', 'name' => 'Report',
            'department' => 'Sales', 'designation' => 'Analyst', 'joining_date' => '2024-01-01',
            'status' => 'Active', 'reporting_manager_id' => $me->id,
        ]);
        $stranger = $this->employee(null, 'Sales');

        $this->department('IT')->users()->attach($auth->id, ['tenant_id' => $this->tenant->id]);

        $this->start($this->clearance($report), 'IT', $auth)->assertOk();
        $this->start($this->clearance($stranger), 'IT', $auth)->assertStatus(404);
    }

    public function test_14_an_own_scoped_authority_sees_only_their_own_clearance(): void
    {
        $this->seedDepartments();
        $auth = $this->scopedUser(DataScope::OWN);
        $me = $this->employee($auth, 'Ops');
        $someoneElse = $this->employee(null, 'Ops');

        $this->department('IT')->users()->attach($auth->id, ['tenant_id' => $this->tenant->id]);

        $this->start($this->clearance($me), 'IT', $auth)->assertOk();
        $this->start($this->clearance($someoneElse), 'IT', $auth)->assertStatus(404);
    }

    public function test_15_scope_is_checked_before_department_authority(): void
    {
        $this->seedDepartments();
        $auth = $this->scopedUser(DataScope::DEPARTMENT);
        $this->employee($auth, 'Ops');
        $leaver = $this->employee(null, 'Sales');

        // Not configured for Finance AND out of scope. The 404 wins: the
        // record's existence is withheld before its authority is discussed.
        $this->start($this->clearance($leaver), 'Finance', $auth)->assertStatus(404);
    }

    /* ── 16-18. fallback stops, per department ────────────────────────── */

    public function test_16_no_configuration_means_hr_fallback(): void
    {
        $this->seedDepartments();

        $this->assertSame(
            ClearanceAuthorityResolver::MODE_FALLBACK,
            $this->resolver()->mode($this->tenant->id, 'IT')
        );
    }

    public function test_17_configuration_stops_the_hr_fallback_for_that_department(): void
    {
        $this->seedDepartments();
        $it = $this->user('staff', null);
        $this->department('IT')->users()->attach($it->id, ['tenant_id' => $this->tenant->id]);

        // THE rule this phase turns on: once somebody is named, the HR queue
        // is not an alternative for that department. Not even for an admin.
        $this->start($this->clearance(), 'IT', $this->hrQueueUser())->assertStatus(403);
        $this->start($this->clearance(), 'IT', $this->user('admin'))->assertStatus(403);
    }

    public function test_18_departments_use_different_modes_independently(): void
    {
        $this->seedDepartments();
        $it = $this->user('staff', null);
        $this->department('IT')->users()->attach($it->id, ['tenant_id' => $this->tenant->id]);

        $hrUser = $this->hrQueueUser();

        // IT enforces; Finance is untouched and still on the HR queue.
        $this->assertSame(ClearanceAuthorityResolver::MODE_CONFIGURED, $this->resolver()->mode($this->tenant->id, 'IT'));
        $this->assertSame(ClearanceAuthorityResolver::MODE_FALLBACK, $this->resolver()->mode($this->tenant->id, 'Finance'));

        $this->start($this->clearance(), 'Finance', $hrUser)->assertOk();
        $this->start($this->clearance(), 'IT', $hrUser)->assertStatus(403);
    }

    /* ── 19-22. deployment safety and existing records ────────────────── */

    public function test_19_the_five_departments_keep_working_immediately_after_deploy(): void
    {
        // What the migration produces: five departments, no authorities.
        $this->seedDepartments();
        $hrUser = $this->hrQueueUser();

        foreach (HrExitClearanceItem::DEPARTMENTS as $dept) {
            $this->start($this->clearance(), $dept, $hrUser)->assertOk();
        }
    }

    public function test_20_a_deactivated_department_does_not_silently_authorize_anyone(): void
    {
        $this->seedDepartments();
        $it = $this->user('staff', null);
        $department = $this->department('IT');
        $department->users()->attach($it->id, ['tenant_id' => $this->tenant->id]);
        $department->update(['is_active' => false]);

        // Deactivation stops NEW clearances carrying the department. It does
        // not hand an existing item back to the HR queue, and it does not
        // widen it to anybody else.
        $this->start($this->clearance(), 'IT', $this->hrQueueUser())->assertStatus(403);
        $this->start($this->clearance(), 'IT', $it)->assertOk();
    }

    public function test_21_existing_clearance_item_department_snapshots_are_untouched(): void
    {
        $this->seedDepartments();
        $clearance = $this->clearance();

        // Renaming the master must not rewrite an item already created.
        $this->department('IT')->update(['name' => 'Information Technology']);

        $this->assertSame(
            HrExitClearanceItem::DEPARTMENTS,
            $clearance->fresh()->items->pluck('department')->all()
        );
    }

    public function test_22_the_parent_status_still_derives_from_the_items(): void
    {
        $this->seedDepartments();
        $clearance = $this->clearance();
        $hrUser = $this->hrQueueUser();

        // Every mandatory department cleared → Completed. The state machine is
        // untouched by this phase; only who may drive it changed.
        foreach (HrExitClearanceItem::DEPARTMENTS as $dept) {
            $item = $this->itemFor($clearance, $dept);
            Sanctum::actingAs($hrUser);
            $this->patchJson("/api/hr/exit/clearances/{$clearance->id}/items/{$item->id}/start", [])->assertOk();
            $this->patchJson("/api/hr/exit/clearances/{$clearance->id}/items/{$item->id}/clear", [])->assertOk();
        }

        $this->assertSame(HrExitClearance::COMPLETED, $clearance->fresh()->status);
    }

    /* ── misconfiguration ─────────────────────────────────────────────── */

    public function test_a_configured_department_with_nobody_eligible_refuses_rather_than_falling_back(): void
    {
        $this->seedDepartments();
        $gone = $this->user('staff', null);
        $this->department('IT')->users()->attach($gone->id, ['tenant_id' => $this->tenant->id]);
        $gone->update(['status' => 'inactive']);

        // The dangerous case: configuration exists but resolves to nobody.
        // Drifting back to the HR queue would silently undo the control at
        // exactly the moment it is broken.
        $response = $this->start($this->clearance(), 'IT', $this->hrQueueUser());

        $response->assertStatus(422);
        $this->assertStringContainsString('No active authority is configured', $response->json('message'));
    }

    public function test_a_misconfigured_department_never_auto_approves(): void
    {
        $this->seedDepartments();
        $gone = $this->user('staff', null, status: 'inactive');
        $this->department('IT')->users()->attach($gone->id, ['tenant_id' => $this->tenant->id]);

        $clearance = $this->clearance();
        $this->start($clearance, 'IT', $this->hrQueueUser())->assertStatus(422);

        $this->assertSame(HrExitClearanceItem::PENDING, $this->itemFor($clearance->fresh(), 'IT')->status);
        $this->assertSame(HrExitClearance::PENDING, $clearance->fresh()->status);
    }

    /* ── every mutation endpoint goes through the same door ───────────── */

    public function test_no_clearance_mutation_endpoint_bypasses_the_authority_check(): void
    {
        $this->seedDepartments();
        $it = $this->user('staff', null);
        $this->department('IT')->users()->attach($it->id, ['tenant_id' => $this->tenant->id]);

        $clearance = $this->clearance();
        $item = $this->itemFor($clearance, 'IT');
        $base = "/api/hr/exit/clearances/{$clearance->id}/items/{$item->id}";

        // An HR-queue user, refused on all four now that IT is configured.
        Sanctum::actingAs($this->hrQueueUser());
        $this->patchJson("{$base}/start", [])->assertStatus(403);
        $this->patchJson("{$base}/clear", [])->assertStatus(403);
        $this->patchJson("{$base}/reject", ['remarks' => 'no'])->assertStatus(403);
        $this->patchJson("{$base}/remarks", ['remarks' => 'note'])->assertStatus(403);

        // And the item never moved.
        $this->assertSame(HrExitClearanceItem::PENDING, $item->fresh()->status);
    }

    public function test_a_legacy_department_string_with_no_master_row_falls_back(): void
    {
        // No departments seeded at all: a workspace created before the master,
        // or a clearance carrying a department since removed. It must keep
        // working under the HR queue rather than stranding.
        $clearance = $this->clearance();

        $this->start($clearance, 'IT', $this->hrQueueUser())->assertOk();
    }
}
