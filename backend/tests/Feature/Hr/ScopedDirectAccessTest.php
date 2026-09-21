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
 * The half of a data boundary that a list does not give you.
 *
 * Phase 1 narrowed the Employees and Leave LISTS. A narrowed list is worth very
 * little on its own: the boundary holds right up until somebody types an id
 * into the URL, which is the first thing anyone tries. This covers the direct-id
 * surface — show, detail, update, approve, reject, cancel, download — for both
 * modules.
 *
 * Out of scope answers 404, not 403. "You may not see employee 41" confirms
 * that 41 exists and sits outside your department, which is exactly what the
 * boundary is withholding; and it matches the tenant guard beside it, which has
 * always answered 404.
 *
 * Permission and scope stay separate throughout. Every actor here holds HR
 * authority and would have passed the gate before — what changes is whose
 * records they may touch, never whether they may touch records at all.
 */
class ScopedDirectAccessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private HrLeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Direct', 'slug' => 'scoped-direct', 'status' => 'active']);

        $this->leaveType = HrLeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Casual', 'code' => 'CL',
            'category' => 'Paid', 'paid' => true, 'requires_approval' => true, 'is_active' => true,
        ]);
    }

    /** HR authority always granted — this file is about records, not permission. */
    private function hrUser(string $email, string $scope, string $accountType = 'staff'): User
    {
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'R '.substr(md5($email), 0, 6),
            'slug' => 'r_'.substr(md5($email), 0, 6),
            'permissions' => ['hr_employees' => [StaffPermission::VIEW_GLOBAL]],
            'scope' => $scope, 'is_system' => false,
        ]);

        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $accountType,
            'status' => 'active', 'staff_role_id' => $role->id,
        ]);
    }

    private function employee(string $code, ?User $user = null, string $dept = 'Ops', ?int $managerId = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'employee_code' => $code, 'name' => 'E'.$code,
            'department' => $dept, 'designation' => 'Analyst', 'joining_date' => '2024-01-01',
            'status' => 'Active', 'user_id' => $user?->id, 'reporting_manager_id' => $managerId,
        ]);
    }

    private function leaveFor(HrEmployee $e): HrLeaveApplication
    {
        return HrLeaveApplication::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
            'leave_type_id' => $this->leaveType->id, 'from_date' => '2026-03-01', 'to_date' => '2026-03-01',
            'days' => 1, 'reason' => 'x', 'status' => HrLeaveApplication::SUBMITTED,
        ]);
    }

    /** An actor scoped to their own department, plus a colleague and an outsider. */
    private function cast(string $scope = DataScope::DEPARTMENT): array
    {
        $user    = $this->hrUser("actor-{$scope}@direct.test", $scope);
        $me      = $this->employee('A-1', $user, 'Ops');
        $mate    = $this->employee('A-2', null, 'Ops');
        $outside = $this->employee('A-3', null, 'Sales');

        return [$user, $me, $mate, $outside];
    }

    /* ── Employees: direct id ─────────────────────────────────────────── */

    /** @dataProvider employeeReadRoutes */
    public function test_an_employee_outside_scope_is_not_readable(string $suffix): void
    {
        [$user, $me, , $outside] = $this->cast();
        Sanctum::actingAs($user);

        $this->getJson("/api/hr/employees/{$me->id}{$suffix}")->assertOk();
        $this->getJson("/api/hr/employees/{$outside->id}{$suffix}")->assertNotFound();
    }

    public static function employeeReadRoutes(): array
    {
        return ['show' => [''], 'detail' => ['/detail'], 'profile' => ['/profile']];
    }

    public function test_an_employee_outside_scope_cannot_be_updated(): void
    {
        [$user, $me, , $outside] = $this->cast();
        Sanctum::actingAs($user);

        $this->putJson("/api/hr/employees/{$outside->id}", ['designation' => 'Hijacked'])->assertNotFound();
        $this->assertSame('Analyst', $outside->fresh()->designation, 'The record must be untouched.');

        // And the same call on somebody in scope is refused only by PERMISSION,
        // which is a different question and unchanged by this phase.
        $this->assertNotSame(404, $this->putJson("/api/hr/employees/{$me->id}", ['designation' => 'Senior'])->status());
    }

    public function test_bank_and_identity_details_outside_scope_cannot_be_written(): void
    {
        [$user, , , $outside] = $this->cast();
        Sanctum::actingAs($user);

        $this->putJson("/api/hr/employees/{$outside->id}/detail", [
            'bank_account_number' => '999888777666',
        ])->assertNotFound();

        $this->assertDatabaseMissing('hr_employee_details', ['bank_account_number' => '999888777666']);
    }

    public function test_an_employee_outside_scope_cannot_be_deleted(): void
    {
        [$user, , , $outside] = $this->cast();
        Sanctum::actingAs($user);

        $this->deleteJson("/api/hr/employees/{$outside->id}")->assertNotFound();
        $this->assertNotNull(HrEmployee::find($outside->id), 'The record must still be there.');
    }

    /* ── Leave: direct id ─────────────────────────────────────────────── */

    public function test_a_leave_application_outside_scope_is_not_readable(): void
    {
        [$user, $me, , $outside] = $this->cast();
        $mine   = $this->leaveFor($me);
        $theirs = $this->leaveFor($outside);

        Sanctum::actingAs($user);

        $this->getJson("/api/hr/leave/applications/{$mine->id}")->assertOk();
        $this->getJson("/api/hr/leave/applications/{$theirs->id}")->assertNotFound();
    }

    public function test_leave_outside_scope_cannot_be_approved_or_rejected(): void
    {
        [$user, , , $outside] = $this->cast();
        $theirs = $this->leaveFor($outside);

        Sanctum::actingAs($user);

        $this->patchJson("/api/hr/leave/approvals/{$theirs->id}/approve")->assertNotFound();
        $this->patchJson("/api/hr/leave/approvals/{$theirs->id}/reject", ['remarks' => 'no'])->assertNotFound();

        $this->assertSame(HrLeaveApplication::SUBMITTED, $theirs->fresh()->status,
            'A refused decision must not have moved the application.');
    }

    public function test_leave_outside_scope_cannot_be_cancelled(): void
    {
        [$user, , , $outside] = $this->cast();
        $theirs = $this->leaveFor($outside);

        Sanctum::actingAs($user);

        $this->patchJson("/api/hr/leave/applications/{$theirs->id}/cancel")->assertNotFound();
        $this->assertSame(HrLeaveApplication::SUBMITTED, $theirs->fresh()->status);
    }

    public function test_a_leave_attachment_outside_scope_cannot_be_downloaded(): void
    {
        [$user, , , $outside] = $this->cast();
        $theirs = $this->leaveFor($outside);

        Sanctum::actingAs($user);

        // 404 either way here, but it must be the SCOPE check that answers —
        // the record is refused before its attachment is even looked for.
        $this->getJson("/api/hr/leave/applications/{$theirs->id}/attachment")->assertNotFound();
    }

    public function test_leave_history_for_an_employee_outside_scope_is_refused(): void
    {
        [$user, $me, , $outside] = $this->cast();
        Sanctum::actingAs($user);

        $this->getJson("/api/hr/leave/approvals/history/{$me->id}")->assertOk();
        $this->getJson("/api/hr/leave/approvals/history/{$outside->id}")->assertNotFound();
    }

    /* ── the scopes behave as Phase 1 defined ─────────────────────────── */

    public function test_own_scope_refuses_a_colleague(): void
    {
        [$user, $me, $mate] = $this->cast(DataScope::OWN);
        Sanctum::actingAs($user);

        $this->getJson("/api/hr/employees/{$me->id}")->assertOk();
        $this->getJson("/api/hr/employees/{$mate->id}")->assertNotFound();
    }

    public function test_department_scope_allows_a_colleague(): void
    {
        [$user, , $mate] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $this->getJson("/api/hr/employees/{$mate->id}")->assertOk();
    }

    public function test_team_scope_follows_the_reporting_line(): void
    {
        $user = $this->hrUser('team@direct.test', DataScope::TEAM);
        $me   = $this->employee('T-1', $user);
        $sub  = $this->employee('T-2', null, 'Ops', $me->id);
        $away = $this->employee('T-3');

        Sanctum::actingAs($user);

        $this->getJson("/api/hr/employees/{$sub->id}")->assertOk();
        $this->getJson("/api/hr/employees/{$away->id}")->assertNotFound();
    }

    /** The failure direction, on the direct-id surface. */
    public function test_a_scoped_actor_with_no_employee_record_can_reach_nothing(): void
    {
        $user = $this->hrUser('noemp@direct.test', DataScope::DEPARTMENT);
        $someone = $this->employee('N-1');
        $leave   = $this->leaveFor($someone);

        Sanctum::actingAs($user);

        $this->getJson("/api/hr/employees/{$someone->id}")->assertNotFound();
        $this->getJson("/api/hr/leave/applications/{$leave->id}")->assertNotFound();
    }

    /* ── what must not change ─────────────────────────────────────────── */

    public function test_a_global_actor_reaches_everything_as_before(): void
    {
        $user    = $this->hrUser('global@direct.test', DataScope::GLOBAL);
        $this->employee('G-0', $user);
        $outside = $this->employee('G-1', null, 'Sales');
        $leave   = $this->leaveFor($outside);

        Sanctum::actingAs($user);

        $this->getJson("/api/hr/employees/{$outside->id}")->assertOk();
        $this->getJson("/api/hr/employees/{$outside->id}/detail")->assertOk();
        $this->getJson("/api/hr/leave/applications/{$leave->id}")->assertOk();
    }

    public function test_an_admin_is_never_restricted(): void
    {
        $admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'A', 'email' => 'admin@direct.test',
            'password' => Hash::make('Password123!'), 'role' => 'admin', 'status' => 'active',
        ]);
        $outside = $this->employee('AD-1', null, 'Sales');

        Sanctum::actingAs($admin);

        $this->getJson("/api/hr/employees/{$outside->id}")->assertOk();
        $this->getJson("/api/hr/employees/{$outside->id}/detail")->assertOk();
    }

    /** Scope is a data boundary, never a grant. */
    public function test_a_global_scope_does_not_confer_permission(): void
    {
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Wide No Perm', 'slug' => 'wide_no_perm',
            'permissions' => [], 'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'email' => 'wide@direct.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
            'staff_role_id' => $role->id,
        ]);
        $target = $this->employee('W-1');

        $this->assertFalse($user->canManageHrQueue());

        Sanctum::actingAs($user);

        $this->putJson("/api/hr/employees/{$target->id}", ['designation' => 'X'])->assertForbidden();
    }

    /** @dataProvider portalRoles */
    public function test_a_portal_account_is_still_refused(string $accountType): void
    {
        $user   = $this->hrUser("portal-{$accountType}@direct.test", DataScope::GLOBAL, $accountType);
        $target = $this->employee('P-'.strtoupper(substr($accountType, 0, 3)));

        Sanctum::actingAs($user);

        $this->putJson("/api/hr/employees/{$target->id}", ['designation' => 'X'])->assertForbidden();
    }

    public static function portalRoles(): array
    {
        return [
            'client' => ['client'], 'vendor' => ['vendor'],
            'third party vendor' => ['third_party_vendor'],
            'company' => ['company'], 'doctor' => ['doctor'],
        ];
    }
}
