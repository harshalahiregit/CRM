<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrLeaveApplication;
use App\Models\Hr\HrLeaveType;
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
 * The first two modules to ask "whose records?" as well as "may they?".
 *
 * Employees and Leave are the proof that the resolver is reusable: the
 * hierarchy walk lives in one place and each module contributes only the column
 * its rows carry — hr_employees.id for the directory, employee_id for leave.
 *
 * Permission is untouched. Every actor here holds HR authority and would have
 * passed the gate before; what changes is how many rows come back. A global
 * role — which is every role on every existing database — sees exactly what it
 * saw, and that is asserted first because it is the thing most worth not
 * breaking.
 */
class ScopedListingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private HrLeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Scoped', 'slug' => 'scoped-listing', 'status' => 'active']);

        $this->leaveType = HrLeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Casual', 'code' => 'CL',
            'category' => 'Paid', 'paid' => true, 'requires_approval' => true, 'is_active' => true,
        ]);
    }

    /** Every actor gets HR authority — this file is about rows, not permission. */
    private function hrUser(string $email, string $scope, ?Tenant $tenant = null): User
    {
        $role = StaffRole::create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'name' => 'HR '.$scope.' '.substr(md5($email), 0, 4),
            'slug' => 'hr_'.$scope.'_'.substr(md5($email), 0, 4),
            'permissions' => ['hr_employees' => [StaffPermission::VIEW_GLOBAL]],
            'scope' => $scope, 'is_system' => false,
        ]);

        $user = User::create([
            'tenant_id' => ($tenant ?? $this->tenant)->id, 'name' => 'HR', 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
            'staff_role_id' => $role->id,
        ]);

        $this->assertTrue($user->canManageHrQueue(), 'Fixture: permission must not be what is under test.');

        return $user;
    }

    private function employee(string $code, ?User $user = null, string $dept = 'Ops', ?int $managerId = null, ?Tenant $tenant = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => ($tenant ?? $this->tenant)->id, 'employee_code' => $code, 'name' => 'E'.$code,
            'department' => $dept, 'designation' => 'Analyst', 'joining_date' => '2024-01-01',
            'status' => 'Active', 'user_id' => $user?->id, 'reporting_manager_id' => $managerId,
        ]);
    }

    private function leaveFor(HrEmployee $e, ?Tenant $tenant = null): HrLeaveApplication
    {
        return HrLeaveApplication::create([
            'tenant_id' => ($tenant ?? $this->tenant)->id, 'employee_id' => $e->id,
            'leave_type_id' => $this->leaveType->id, 'from_date' => '2026-03-01', 'to_date' => '2026-03-01',
            'days' => 1, 'reason' => 'x', 'status' => HrLeaveApplication::SUBMITTED,
        ]);
    }

    private function employeeNames(): array
    {
        $r = $this->getJson('/api/hr/employees')->assertOk()->json();

        return collect($r['data'] ?? $r)->pluck('name')->sort()->values()->all();
    }

    private function leaveEmployeeIds(): array
    {
        $r = $this->getJson('/api/hr/leave/applications')->assertOk()->json();

        return collect($r['data'] ?? $r)->pluck('employee_id')->filter()->sort()->values()->all();
    }

    /* ── global is unchanged ──────────────────────────────────────────── */

    public function test_a_global_role_sees_every_employee_as_before(): void
    {
        $user = $this->hrUser('global@scoped.test', DataScope::GLOBAL);
        $this->employee('G-1', $user);
        $this->employee('G-2', null, 'Sales');
        $this->employee('G-3', null, 'Ops');

        Sanctum::actingAs($user);

        $this->assertCount(3, $this->employeeNames(), 'Global must be byte-identical to the old behaviour.');
    }

    public function test_a_global_role_sees_every_leave_application_as_before(): void
    {
        $user = $this->hrUser('globalleave@scoped.test', DataScope::GLOBAL);
        $me   = $this->employee('GL-1', $user);
        $them = $this->employee('GL-2', null, 'Sales');

        $this->leaveFor($me);
        $this->leaveFor($them);

        Sanctum::actingAs($user);

        $this->assertCount(2, $this->leaveEmployeeIds());
    }

    /* ── Employees, narrowed ──────────────────────────────────────────── */

    public function test_own_scope_shows_only_the_actors_own_record(): void
    {
        $user = $this->hrUser('own@scoped.test', DataScope::OWN);
        $this->employee('O-1', $user);
        $this->employee('O-2');

        Sanctum::actingAs($user);

        $this->assertSame(['EO-1'], $this->employeeNames());
    }

    public function test_department_scope_shows_the_actors_department(): void
    {
        $user = $this->hrUser('dept@scoped.test', DataScope::DEPARTMENT);
        $this->employee('D-1', $user, 'Ops');
        $this->employee('D-2', null, 'Ops');
        $this->employee('D-3', null, 'Sales');

        Sanctum::actingAs($user);

        $this->assertSame(['ED-1', 'ED-2'], $this->employeeNames());
    }

    public function test_team_scope_shows_the_actor_and_their_reports(): void
    {
        $user = $this->hrUser('team@scoped.test', DataScope::TEAM);
        $me   = $this->employee('T-1', $user);
        $sub  = $this->employee('T-2', null, 'Ops', $me->id);
        $this->employee('T-3', null, 'Ops', $sub->id);   // reports to my report
        $this->employee('T-4');                          // nobody's report

        Sanctum::actingAs($user);

        $this->assertSame(['ET-1', 'ET-2', 'ET-3'], $this->employeeNames());
    }

    /**
     * Branch is NOT declared supported by either module: hr_employees.branch is
     * free text with no master and no data. A role scoped to it therefore reads
     * as global here — documented, logged, and pinned so the day it changes is a
     * deliberate one.
     */
    public function test_branch_scope_is_not_yet_honoured_and_stays_global(): void
    {
        $user = $this->hrUser('branch@scoped.test', DataScope::BRANCH);
        $this->employee('B-1', $user);
        $this->employee('B-2', null, 'Sales');

        Sanctum::actingAs($user);

        $this->assertCount(2, $this->employeeNames(),
            'Until branch data exists, narrowing on it would lock people out over an empty column.');
    }

    /**
     * The failure direction, end to end: authorised, scoped, but with no place
     * in the organisation chart.
     */
    public function test_a_scoped_actor_with_no_employee_record_sees_nothing(): void
    {
        $user = $this->hrUser('noemp@scoped.test', DataScope::DEPARTMENT);
        $this->employee('X-1');
        $this->employee('X-2');

        Sanctum::actingAs($user);

        $this->assertSame([], $this->employeeNames(),
            'Nothing is the safe answer here, not everything.');
    }

    /* ── Leave, narrowed ──────────────────────────────────────────────── */

    public function test_leave_own_scope_shows_only_the_actors_applications(): void
    {
        $user = $this->hrUser('lown@scoped.test', DataScope::OWN);
        $me   = $this->employee('LO-1', $user);
        $them = $this->employee('LO-2');

        $this->leaveFor($me);
        $this->leaveFor($them);

        Sanctum::actingAs($user);

        $this->assertSame([$me->id], $this->leaveEmployeeIds());
    }

    public function test_leave_team_scope_shows_the_teams_applications(): void
    {
        $user = $this->hrUser('lteam@scoped.test', DataScope::TEAM);
        $me   = $this->employee('LT-1', $user);
        $sub  = $this->employee('LT-2', null, 'Ops', $me->id);
        $away = $this->employee('LT-3');

        $this->leaveFor($me);
        $this->leaveFor($sub);
        $this->leaveFor($away);

        Sanctum::actingAs($user);

        $ids = $this->leaveEmployeeIds();

        $this->assertContains($me->id, $ids);
        $this->assertContains($sub->id, $ids);
        $this->assertNotContains($away->id, $ids);
    }

    public function test_leave_department_scope_filters_by_department(): void
    {
        $user = $this->hrUser('ldept@scoped.test', DataScope::DEPARTMENT);
        $me   = $this->employee('LD-1', $user, 'Ops');
        $mate = $this->employee('LD-2', null, 'Ops');
        $away = $this->employee('LD-3', null, 'Sales');

        foreach ([$me, $mate, $away] as $e) {
            $this->leaveFor($e);
        }

        Sanctum::actingAs($user);

        $ids = $this->leaveEmployeeIds();

        $this->assertContains($me->id, $ids);
        $this->assertContains($mate->id, $ids);
        $this->assertNotContains($away->id, $ids);
    }

    /* ── tenant isolation survives ────────────────────────────────────── */

    public function test_employees_stay_tenant_isolated_under_every_scope(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'scoped-other', 'status' => 'active']);
        $this->employee('F-1', null, 'Ops', null, $other);

        foreach ([DataScope::GLOBAL, DataScope::OWN, DataScope::DEPARTMENT, DataScope::TEAM] as $scope) {
            $user = $this->hrUser("iso-{$scope}@scoped.test", $scope);
            $this->employee('I-'.$scope, $user, 'Ops');

            Sanctum::actingAs($user);

            $this->assertNotContains('EF-1', $this->employeeNames(),
                "Scope '{$scope}' must narrow within the tenant, never reach outside it.");
        }
    }

    public function test_leave_stays_tenant_isolated(): void
    {
        $other   = Tenant::create(['name' => 'Other2', 'slug' => 'scoped-other2', 'status' => 'active']);
        $foreign = $this->employee('FL-1', null, 'Ops', null, $other);
        $this->leaveFor($foreign, $other);

        $user = $this->hrUser('liso@scoped.test', DataScope::GLOBAL);
        $mine = $this->employee('ML-1', $user);
        $this->leaveFor($mine);

        Sanctum::actingAs($user);

        $this->assertSame([$mine->id], $this->leaveEmployeeIds(),
            'Even a GLOBAL role is global within its own tenant only.');
    }

    /* ── permission is untouched ──────────────────────────────────────── */

    /**
     * Scope narrows an authorised view; it must never become the authorisation.
     * Somebody without HR permission is refused by the gate as before, whatever
     * their scope says.
     */
    public function test_scope_does_not_replace_the_permission_check(): void
    {
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Scoped No Perm', 'slug' => 'scoped_no_perm',
            'permissions' => [], 'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);

        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'NoPerm', 'email' => 'noperm@scoped.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
            'staff_role_id' => $role->id,
        ]);

        $this->assertFalse($user->canManageHrQueue());

        // A real record, so the write below is refused by the GATE rather than
        // 404'd by route-model binding before it gets there.
        $target = $this->employee('NP-1');

        Sanctum::actingAs($user);

        // The employee directory is readable by any authenticated staff member —
        // that is existing, deliberate behaviour and this phase does not change
        // it. What matters is that a global scope did not GRANT anything.
        $this->getJson('/api/hr/employees')->assertOk();
        $this->putJson("/api/hr/employees/{$target->id}", ['name' => 'X'])->assertForbidden();
    }
}
