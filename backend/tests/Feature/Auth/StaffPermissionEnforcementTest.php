<?php

namespace Tests\Feature\Auth;

use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\StaffRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ticking a box in Staff Management changes what somebody can reach.
 *
 * The grid has been stored since Staff Management shipped and read by nothing:
 * access was decided by role strings written into PHP, so changing who may run
 * HR meant a code change and a deploy. These tests are the statement that it is
 * no longer true — and they are written against the ROUTE, over HTTP, because a
 * unit test of can() would have passed happily for the entire period the grid
 * governed nothing at all.
 */
class StaffPermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'S', 'slug' => 'perm-enf', 'status' => 'active']);
        app(StaffRoleService::class)->ensureSeeded($this->tenant->id);
    }

    private function staff(string $roleSlug = null, array $ownGrid = []): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Staffer',
            'email'     => 's'.random_int(1000, 9999).'@perm.test',
            'password'  => Hash::make('x'),
            'role'      => 'staff',
            'status'    => 'active',
            'meta'      => $ownGrid ? ['permissions' => $ownGrid] : null,
        ]);

        if ($roleSlug) {
            $role = StaffRole::where('tenant_id', $this->tenant->id)->where('slug', $roleSlug)->firstOrFail();
            $user->forceFill(['staff_role_id' => $role->id, 'internal_role' => $roleSlug])->save();
        }

        return $user->fresh();
    }

    /** An HR-gated route. Any one in the group would do. */
    private function hitHrQueue()
    {
        return $this->getJson('/api/hr/settings');
    }

    public function test_an_hr_executive_reaches_the_hr_queue(): void
    {
        Sanctum::actingAs($this->staff('hr_executive'));

        $this->hitHrQueue()->assertOk();
    }

    public function test_a_plain_employee_does_not(): void
    {
        Sanctum::actingAs($this->staff('employee'));

        $this->hitHrQueue()->assertForbidden();
    }

    /**
     * THE POINT OF ALL OF THIS.
     *
     * Same person, same role, no deploy — an admin ticks hr_attendance on their
     * personal grid and the door opens. This is the behaviour that did not exist
     * before: the checkbox was stored and ignored.
     */
    public function test_ticking_the_box_grants_access_with_no_code_change(): void
    {
        $user = $this->staff('employee');

        Sanctum::actingAs($user);
        $this->hitHrQueue()->assertForbidden();

        // Exactly what StaffManagementController writes when an admin saves the grid.
        $user->forceFill([
            'meta' => ['permissions' => ['hr_attendance' => ['view_global']]],
        ])->save();

        Sanctum::actingAs($user->fresh());
        $this->hitHrQueue()->assertOk();
    }

    /** And un-ticking takes it away again, which is the half that proves it is real. */
    public function test_unticking_the_box_removes_access(): void
    {
        $user = $this->staff('hr_executive');

        Sanctum::actingAs($user);
        $this->hitHrQueue()->assertOk();

        // The per-user grid overrides the role at module level.
        $user->forceFill(['meta' => ['permissions' => ['hr_attendance' => []]]])->save();

        Sanctum::actingAs($user->fresh());
        $this->hitHrQueue()->assertForbidden();
    }

    public function test_an_admin_passes_regardless_of_the_grid(): void
    {
        $admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'a@perm.test',
            'password' => Hash::make('x'), 'role' => 'admin', 'status' => 'active',
            'meta' => ['permissions' => []],
        ]);

        Sanctum::actingAs($admin);

        $this->hitHrQueue()->assertOk();
    }

    /**
     * view_global implies view_own, never the reverse.
     *
     * That asymmetry is the whole reason the two are separate: somebody trusted
     * with their own record is not thereby trusted with the company's.
     */
    public function test_view_own_alone_does_not_open_a_view_global_route(): void
    {
        $user = $this->staff('employee', ['hr_attendance' => ['view_own']]);

        Sanctum::actingAs($user);

        $this->hitHrQueue()->assertForbidden();
    }

    /**
     * The screens can ask what this person may do, instead of guessing.
     *
     * Everything client-side keyed off `role`, so the sidebar showed every HR
     * management item to everybody and each one 403'd when clicked. No guess
     * could have been right: the advances gate asks whether anyone reports to
     * you, which is a database question, not a string.
     */
    public function test_the_login_payload_says_what_the_user_may_do(): void
    {
        $user = $this->staff('hr_executive');
        Sanctum::actingAs($user);

        $r = $this->getJson('/api/auth/me')->assertOk();

        $this->assertSame('global', $r->json('data.user.permissions.scope.hr_attendance'));
        $this->assertContains('view_global', $r->json('data.user.permissions.can.hr_attendance'));
        $this->assertFalse($r->json('data.user.permissions.is_admin'));
    }

    public function test_an_employee_sees_only_their_own_scope(): void
    {
        Sanctum::actingAs($this->staff('employee'));

        $r = $this->getJson('/api/auth/me')->assertOk();

        $this->assertSame('own', $r->json('data.user.permissions.scope.self'));
        $this->assertArrayNotHasKey('hr_attendance', $r->json('data.user.permissions.scope'));
    }

    /** An admin bypasses the grid, and says so rather than being inferred. */
    public function test_an_admin_is_flagged_as_such(): void
    {
        $admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'a2@perm.test',
            'password' => Hash::make('x'), 'role' => 'admin', 'status' => 'active',
        ]);
        Sanctum::actingAs($admin);

        $r = $this->getJson('/api/auth/me')->assertOk();

        $this->assertTrue($r->json('data.user.permissions.is_admin'));
        $this->assertSame('global', $r->json('data.user.permissions.scope.hr_attendance'));
    }

    /** A portal identity must never satisfy a staff gate, whatever it carries. */
    public function test_a_client_account_cannot_reach_it(): void
    {
        $client = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client', 'email' => 'c@perm.test',
            'password' => Hash::make('x'), 'role' => 'client', 'status' => 'active',
            'meta' => ['permissions' => ['hr_attendance' => ['view_global']]],
        ]);

        Sanctum::actingAs($client);

        $this->hitHrQueue()->assertForbidden();
    }
}
