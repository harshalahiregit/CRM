<?php

namespace Tests\Feature\Hr;

use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\StaffRoleService;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The point at which a role created in HR Settings starts to mean something.
 *
 * canManageHrQueue() was three hardcoded internal_role strings. An admin could
 * create "Senior HR Executive", tick every box on the permission grid, assign it
 * — and the holder still could not approve a leave request, because none of the
 * 117 call sites read the grid. Configuring a role required a developer to add
 * its slug to PHP first, which is exactly what the product requirement rules out.
 *
 * The grid is now an ALTERNATIVE way through the same gate, not a replacement.
 * Every original clause is evaluated first and unchanged, so this can only widen
 * — and the account-type guard still runs before all of them, so a portal login
 * holding the permission is refused before the grid is ever consulted.
 *
 * What this does NOT do: split HR into payroll/leave/exit. hr_employees is the
 * whole of HR for now. The other modules exist in the vocabulary so that split
 * can happen later without changing it again.
 */
class HrQueueGridAccessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Grid', 'slug' => 'hr-queue-grid', 'status' => 'active',
        ]);
    }

    private function user(string $role, string $email, ?string $internal = null, array $grid = []): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst($role), 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $role,
            'internal_role' => $internal, 'status' => 'active',
            'meta' => $grid ? ['permissions' => $grid] : null,
        ]);
    }

    /* ── the new path ─────────────────────────────────────────────────── */

    public function test_a_staff_user_with_the_grant_passes_the_hr_gate(): void
    {
        $user = $this->user('staff', 'granted@grid.test', null, [
            'hr_employees' => [StaffPermission::VIEW_GLOBAL],
        ]);

        $this->assertTrue($user->canManageHrQueue(),
            'hr_employees:view_global is the configurable way into HR — without it a custom role is decoration.');
    }

    /**
     * A custom role, created the way an admin would, with no slug anywhere in PHP.
     *
     * This is the requirement stated as a test: configuring a role must not need
     * a developer. 'senior_hr_executive' appears in no hardcoded list.
     */
    public function test_a_custom_role_nobody_wrote_into_php_can_manage_hr(): void
    {
        $role = app(StaffRoleService::class)->create($this->tenant->id, [
            'name'        => 'Senior HR Executive',
            'permissions' => ['hr_employees' => [StaffPermission::VIEW_GLOBAL]],
        ]);

        $this->assertSame('senior_hr_executive', $role->slug);

        $user = app(StaffRoleService::class)->assign($this->user('staff', 'custom@grid.test'), $role);

        $this->assertTrue($user->canManageHrQueue(),
            'A role configured in HR Settings must work without a developer adding its slug to PHP.');
    }

    /* ── the vocabulary on its own grants nothing ─────────────────────── */

    /**
     * Item 1 added hr_payroll, hr_leave and hr_exit to the vocabulary. Holding
     * them must NOT open the HR gate — only hr_employees does, by design, and a
     * future per-area split is what will give the others force.
     */
    public function test_the_other_hr_modules_do_not_open_the_gate(): void
    {
        foreach (['hr_payroll', 'hr_leave', 'hr_exit', 'hr_attendance', 'hr_recruitment'] as $module) {
            $user = $this->user('staff', "only-{$module}@grid.test", null, [
                $module => [StaffPermission::VIEW_GLOBAL],
            ]);

            $this->assertFalse($user->canManageHrQueue(),
                "'{$module}' must not grant the whole HR queue — this gate reads hr_employees only.");
        }
    }

    public function test_a_lesser_capability_on_hr_employees_does_not_open_the_gate(): void
    {
        foreach ([StaffPermission::CREATE, StaffPermission::EDIT, StaffPermission::DELETE] as $capability) {
            $user = $this->user('staff', "cap-{$capability}@grid.test", null, [
                'hr_employees' => [$capability],
            ]);

            $this->assertFalse($user->canManageHrQueue(),
                "'{$capability}' alone is not view_global — the gate must not be satisfied by it.");
        }
    }

    public function test_a_staff_user_with_no_grant_is_still_refused(): void
    {
        $this->assertFalse($this->user('staff', 'plain@grid.test')->canManageHrQueue(),
            'Adding vocabulary must not hand HR to everybody.');
        $this->assertFalse($this->user('staff', 'empty@grid.test', null, ['hr_employees' => []])->canManageHrQueue(),
            'An empty list means "may do nothing here" and must deny.');
    }

    /* ── nothing that worked before may stop working ──────────────────── */

    public function test_every_previously_authorised_actor_still_passes(): void
    {
        $this->assertTrue($this->user('admin', 'admin@grid.test')->canManageHrQueue());
        $this->assertTrue($this->user('staff', 'hre@grid.test', 'hr_executive')->canManageHrQueue());
        $this->assertTrue($this->user('staff', 'rec@grid.test', 'hr_recruiter')->canManageHrQueue());
    }

    public function test_every_previously_refused_staff_actor_is_still_refused(): void
    {
        foreach ([null, 'accounts', 'director', 'employee', 'team_lead', 'hiring_manager'] as $internal) {
            $user = $this->user('staff', 'was-'.($internal ?? 'none').'@grid.test', $internal);

            $this->assertFalse($user->canManageHrQueue(),
                "internal_role '".($internal ?? 'null')."' did not manage HR before and must not start now.");
        }
    }

    /**
     * An admin bypasses the grid, so the new clause must not be what lets them
     * in — isAdmin() already did, and the order matters if the grid ever changes.
     */
    public function test_an_admin_passes_without_depending_on_the_grid(): void
    {
        $admin = $this->user('admin', 'boss@grid.test', null, ['hr_employees' => []]);

        $this->assertTrue($admin->canManageHrQueue(),
            'An explicit empty hr_employees must not lock an admin out of HR.');
    }

    /* ── the account-type guard still wins ────────────────────────────── */

    /**
     * The guard runs before the grid, so this is the ordering made explicit:
     * granting a portal login the permission must change nothing.
     *
     * @dataProvider portalRoles
     */
    public function test_a_portal_account_is_refused_even_when_granted(string $role): void
    {
        $user = $this->user($role, "portal-{$role}@grid.test", null, [
            'hr_employees' => [StaffPermission::VIEW_GLOBAL],
        ]);

        $this->assertFalse($user->canManageHrQueue(),
            "A {$role} holding hr_employees:view_global must still be refused — the account type decides first.");
    }

    /** And the same through an assigned ROLE rather than a personal grid. */
    public function test_a_portal_account_is_refused_even_when_holding_an_hr_role(): void
    {
        $role = app(StaffRoleService::class)->create($this->tenant->id, [
            'name'        => 'Portal Sneak',
            'permissions' => ['hr_employees' => [StaffPermission::VIEW_GLOBAL]],
        ]);

        $user = app(StaffRoleService::class)->assign($this->user('client', 'sneak@grid.test'), $role);

        $this->assertFalse($user->canManageHrQueue());
    }

    public static function portalRoles(): array
    {
        return [
            'client'             => ['client'],
            'vendor'             => ['vendor'],
            'third party vendor' => ['third_party_vendor'],
            'company'            => ['company'],
            'doctor'             => ['doctor'],
        ];
    }

    /* ── stored permissions are untouched ─────────────────────────────── */

    /**
     * This step reads the grid; it must never write to it. An account with no
     * role and no grid keeps resolving to exactly nothing.
     */
    public function test_reading_the_grid_does_not_change_it(): void
    {
        $user = $this->user('staff', 'untouched@grid.test', null, [
            'hr_employees' => [StaffPermission::VIEW_GLOBAL],
            'tasks'        => [StaffPermission::VIEW_OWN],
        ]);

        $before = $user->meta['permissions'];
        $user->canManageHrQueue();

        $this->assertSame($before, $user->fresh()->meta['permissions'],
            'Evaluating the gate must not rewrite what is stored.');

        $bare = $this->user('staff', 'bare@grid.test');
        $bare->canManageHrQueue();
        $this->assertNull($bare->fresh()->meta,
            'A user with no grid must not have one created by being asked a question.');
    }

    /** A role the user holds is read live — the grid is not copied into them. */
    public function test_the_role_is_consulted_without_being_copied_into_the_user(): void
    {
        $role = app(StaffRoleService::class)->create($this->tenant->id, [
            'name'        => 'HR Ops',
            'permissions' => ['hr_employees' => [StaffPermission::VIEW_GLOBAL]],
        ]);

        $user = app(StaffRoleService::class)->assign($this->user('staff', 'live@grid.test'), $role);

        $this->assertTrue($user->canManageHrQueue());
        $this->assertNull($user->fresh()->meta['permissions'] ?? null,
            'The gate must read the role, not snapshot it onto the user.');
    }

    /**
     * Revoking the role revokes the access, with no code change and no deploy.
     * The whole point of the step, stated once.
     */
    public function test_removing_the_grant_removes_the_access(): void
    {
        $service = app(StaffRoleService::class);

        $role = $service->create($this->tenant->id, [
            'name' => 'Temporary HR', 'permissions' => ['hr_employees' => [StaffPermission::VIEW_GLOBAL]],
        ]);

        $user = $service->assign($this->user('staff', 'temp@grid.test'), $role);
        $this->assertTrue($user->canManageHrQueue());

        $service->update($role, ['permissions' => ['hr_employees' => []]]);

        $this->assertFalse($user->fresh()->canManageHrQueue(),
            'Editing the role in HR Settings must take effect without a developer.');
    }

    /* ── the shared gate reaches the attendance app ───────────────────── */

    /**
     * canManageHrQueue() is consulted by HrmAdminController::deny(), so widening
     * it widens the app's admin screens by exactly the same set. That is intended
     * and understood; it is pinned here so the consequence is visible in a test
     * rather than discovered on a phone.
     */
    public function test_the_grant_reaches_the_attendance_app_admin_gate(): void
    {
        $user = $this->user('staff', 'appadmin@grid.test', null, [
            'hr_employees' => [StaffPermission::VIEW_GLOBAL],
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($user);

        $response = $this->getJson('/api/Hrm/admin/dashboard');

        $this->assertNotSame('You do not have access to this.', $response->json('message'),
            'The app admin gate reads the same helper, so a configured HR role reaches it too.');
    }

    /** And a portal account still cannot, through that same door. */
    public function test_a_granted_portal_account_still_cannot_reach_the_app_admin_gate(): void
    {
        $user = $this->user('client', 'appsneak@grid.test', null, [
            'hr_employees' => [StaffPermission::VIEW_GLOBAL],
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->getJson('/api/Hrm/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('message', 'You do not have access to this.');
    }
}
