<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\StaffRoleService;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The screen asks the server who somebody is, instead of working it out again.
 *
 * modules/hr/constants.js used to rebuild three backend rules from role strings,
 * and ten call sites across nine HR screens asked it. It was a fair copy when
 * written and had since stopped being one in two separate ways:
 *
 *   • canManageHrQueue grew a fourth clause — hr_employees:view_global — so a
 *     role created in HR Settings passed the SERVER and was hidden by the
 *     SCREEN. A role you can configure but not use is worse than no roles.
 *   • canApproveL1/L2 grew the account-type guard, so a portal login carrying
 *     'department_head' was still shown approval buttons it would be refused.
 *
 * The payload now carries the answers. These tests are what stop the copy coming
 * back: they assert the server states each capability, and — the part that
 * matters for configurable roles — that a CUSTOM role's holder is told yes.
 */
class FrontendCapabilityPayloadTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Caps', 'slug' => 'capability-payload', 'status' => 'active',
        ]);
    }

    private function user(string $role, string $email, ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst($role), 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $role,
            'internal_role' => $internal, 'status' => 'active',
        ]);
    }

    private function capabilities(User $user): array
    {
        Sanctum::actingAs($user);

        // data.user.permissions — the same object the browser stores as `user`,
        // which is why constants.js reads u?.permissions?.capabilities.
        return $this->getJson('/api/auth/me')->assertOk()->json('data.user.permissions.capabilities') ?? [];
    }

    /* ── the payload exists and is truthful ───────────────────────────── */

    public function test_the_payload_carries_all_three_capabilities(): void
    {
        $caps = $this->capabilities($this->user('staff', 'plain@caps.test'));

        foreach (['hr_manage', 'approve_l1', 'approve_l2'] as $key) {
            $this->assertArrayHasKey($key, $caps,
                "The screen reads {$key}; without it every button silently closes.");
            $this->assertIsBool($caps[$key]);
        }
    }

    /**
     * Each capability must equal the method that actually gates the request.
     * Anything else is the drift this replaces.
     *
     * @dataProvider actors
     */
    public function test_each_capability_matches_the_backend_gate(string $role, ?string $internal): void
    {
        $user = $this->user($role, "match-{$role}-{$internal}@caps.test", $internal);
        $caps = $this->capabilities($user->fresh());

        $this->assertSame($user->canManageHrQueue(), $caps['hr_manage']);
        $this->assertSame($user->canApproveL1(), $caps['approve_l1']);
        $this->assertSame($user->canApproveL2(), $caps['approve_l2']);
    }

    public static function actors(): array
    {
        return [
            'admin'            => ['admin', null],
            'hr executive'     => ['staff', 'hr_executive'],
            'hr recruiter'     => ['staff', 'hr_recruiter'],
            'department head'  => ['staff', 'department_head'],
            'project manager'  => ['staff', 'project_manager'],
            'plain staff'      => ['staff', null],
            'accounts'         => ['staff', 'accounts'],
        ];
    }

    /* ── the configurable-role requirement ────────────────────────────── */

    /**
     * The whole point of Items 1–3, verified end to end at the screen's boundary.
     *
     * A role invented by an admin, whose slug appears in no hardcoded list, must
     * be told "yes" by the payload — otherwise the UI hides what the API allows.
     */
    public function test_a_custom_role_is_reported_as_able_to_manage_hr(): void
    {
        $service = app(StaffRoleService::class);

        $role = $service->create($this->tenant->id, [
            'name'        => 'Senior HR Executive',
            'permissions' => ['hr_employees' => [StaffPermission::VIEW_GLOBAL]],
        ]);

        $user = $service->assign($this->user('staff', 'custom@caps.test'), $role);

        $this->assertSame('senior_hr_executive', $role->slug,
            'A slug that appears in no hardcoded list anywhere.');
        $this->assertTrue($user->canManageHrQueue(), 'Fixture check: the server allows them.');
        $this->assertTrue($this->capabilities($user->fresh())['hr_manage'],
            'And the screen must be told so. The old hardcoded copy said no, which is the bug.');
    }

    /** Revoking the grant on the ROLE closes the buttons, with no deploy. */
    public function test_editing_the_role_changes_what_the_screen_is_told(): void
    {
        $service = app(StaffRoleService::class);

        $role = $service->create($this->tenant->id, [
            'name' => 'Temp HR', 'permissions' => ['hr_employees' => [StaffPermission::VIEW_GLOBAL]],
        ]);
        $user = $service->assign($this->user('staff', 'temp@caps.test'), $role);

        $this->assertTrue($this->capabilities($user->fresh())['hr_manage']);

        $service->update($role, ['permissions' => ['hr_employees' => []]]);

        $this->assertFalse($this->capabilities($user->fresh())['hr_manage']);
    }

    /* ── the HR dashboard nav item ────────────────────────────────────── */

    /**
     * The sidebar hides the HR Dashboard on canSee('hr_attendance'), and
     * /api/hr/dashboard is permission:hr_attendance,view_global. Those must be
     * the same answer, or the menu either offers a locked door or hides an open
     * one. Checked against the REAL endpoint rather than against the rule.
     *
     * @dataProvider dashboardActors
     */
    public function test_the_dashboard_nav_signal_matches_the_dashboard_endpoint(
        array $grants,
        bool $expected,
    ): void {
        $service = app(StaffRoleService::class);
        $role    = $service->create($this->tenant->id, ['name' => 'Nav Role '.md5(json_encode($grants)), 'permissions' => $grants]);
        $user    = $service->assign($this->user('staff', 'nav-'.md5(json_encode($grants)).'@caps.test'), $role);

        Sanctum::actingAs($user->fresh());

        $scope   = $this->getJson('/api/auth/me')->assertOk()->json('data.user.permissions.scope');
        $navShows = isset($scope['hr_attendance']);

        $reachable = $this->getJson('/api/hr/dashboard')->status() !== 403;

        $this->assertSame($expected, $navShows, 'The sidebar signal is wrong for this role.');
        $this->assertSame($navShows, $reachable,
            'The menu must offer exactly what the endpoint allows — no locked doors, no hidden ones.');
    }

    public static function dashboardActors(): array
    {
        return [
            'granted hr_attendance'       => [['hr_attendance' => [StaffPermission::VIEW_GLOBAL]], true],
            'granted only hr_employees'   => [['hr_employees'  => [StaffPermission::VIEW_GLOBAL]], false],
            'granted nothing'             => [['tasks' => [StaffPermission::VIEW_OWN]], false],
        ];
    }

    /**
     * Worth stating on its own: a custom role that can MANAGE HR still does not
     * see the dashboard unless it is also granted hr_attendance. That is not a
     * bug in the menu — the endpoint refuses them too — but it is a thing to
     * know when configuring a role.
     */
    public function test_managing_hr_does_not_by_itself_open_the_dashboard(): void
    {
        $service = app(StaffRoleService::class);
        $role    = $service->create($this->tenant->id, [
            'name' => 'HR Without Dashboard', 'permissions' => ['hr_employees' => [StaffPermission::VIEW_GLOBAL]],
        ]);
        $user = $service->assign($this->user('staff', 'nodash@caps.test'), $role);

        $this->assertTrue($user->canManageHrQueue(), 'They manage HR…');

        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/hr/dashboard')->assertForbidden();   // …but not this screen
    }

    /* ── portal identities ────────────────────────────────────────────── */

    /**
     * A portal account gets no permissions block at all — UserResource only
     * emits it for admin and staff. The helpers coerce the absence to false, so
     * the buttons close rather than opening on an undefined.
     *
     * @dataProvider portalRoles
     */
    public function test_a_portal_identity_is_offered_no_capabilities(string $role): void
    {
        $user = $this->user($role, "portal-{$role}@caps.test", 'hr_executive');

        Sanctum::actingAs($user);
        $permissions = $this->getJson('/api/auth/me')->assertOk()->json('data.user.permissions');

        $this->assertNull($permissions,
            "A {$role} carrying an HR-looking internal_role must not be handed HR capabilities.");
        $this->assertFalse($user->canManageHrQueue(), 'And the server refuses them anyway.');
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

    /* ── the copy must not come back ──────────────────────────────────── */

    /**
     * Source-level, because this is the regression itself rather than a
     * behaviour: if the helpers ever re-derive the rule from role strings, the
     * screen and the server start drifting again and nothing fails until
     * somebody creates a role and finds it does nothing.
     */
    public function test_the_frontend_helpers_do_not_rebuild_the_rules(): void
    {
        $constants = file_get_contents(
            __DIR__.'/../../../../frontend/src/modules/hr/constants.js'
        );

        $start = strpos($constants, 'export const canApproveL1');
        $block = substr($constants, $start, strpos($constants, "\n\n", $start) - $start);

        foreach (['hr_executive', 'hr_recruiter', 'department_head', 'hiring_manager',
                  'project_manager', 'senior_executive', "'admin'"] as $literal) {
            $this->assertStringNotContainsString($literal, $block,
                "The gating helpers must not hardcode '{$literal}' — that is the copy that went stale. "
                .'Read permissions.capabilities instead.');
        }

        foreach (['hr_manage', 'approve_l1', 'approve_l2'] as $key) {
            $this->assertStringContainsString($key, $block);
        }
    }
}
