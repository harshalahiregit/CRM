<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Hr\CompanyRole;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The boundary between "has a login here" and "works here".
 *
 * Clients, vendors, third-party vendors, external companies and doctors are rows
 * in `users` exactly like staff — same model, same table, different `role` — and
 * they all carry `internal_role`, which is a free string with no constraint on
 * it. Every HR helper written as `in_array($this->internal_role, [...])`
 * therefore answered yes for a portal login whose column happened to match, and
 * the codebase had six such helpers.
 *
 * Four are closed here. The other two (canManageHrQueue, and
 * AdvanceTierService::holdsAnyTierRole) were closed earlier and are re-checked
 * below, because the value of a boundary is that it holds everywhere rather than
 * in the places somebody remembered.
 *
 * What this deliberately does NOT touch: the portals themselves. A doctor still
 * signs in and reaches routes/medical.php; a company still signs in and reaches
 * its hiring portal with its own CompanyRole sub-roles. Those are tested here
 * too, because the failure mode of a security fix is taking away something
 * legitimate and not noticing.
 */
class PortalAccountTypeBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /** Every account type that can authenticate but does not work inside the CRM. */
    private const PORTAL_ROLES = ['client', 'vendor', 'third_party_vendor', 'company', 'doctor'];

    /** Strings that look like HR authority to one helper or another. */
    private const HR_LOOKING = [
        'hr_executive', 'hr_recruiter', 'hr_manager',
        'department_head', 'hiring_manager', 'project_manager', 'senior_executive',
        'accounts', 'director',
    ];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Boundary', 'slug' => 'account-type-boundary', 'status' => 'active',
        ]);
    }

    private function user(string $role, string $email, ?string $internal = null, array $grid = null): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst($role), 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $role,
            'internal_role' => $internal, 'status' => 'active',
            'meta' => $grid === null ? null : ['permissions' => $grid],
        ]);
    }

    /** Every HR authority helper, asked of one account. */
    private function authorities(User $u): array
    {
        return [
            'canManageHrQueue'    => $u->canManageHrQueue(),
            'canApproveL1'        => $u->canApproveL1(),
            'canApproveL2'        => $u->canApproveL2(),
            'canManageOnboarding' => $u->canManageOnboarding(),
            'canGenerateAiJd'     => $u->canGenerateAiJd(),
            'holdsAnyTierRole'    => app(\App\Services\Hr\AdvanceTierService::class)->holdsAnyTierRole($u),
        ];
    }

    /* ── the boundary ─────────────────────────────────────────────────── */

    /**
     * The core claim: no portal account type holds ANY HR authority, whatever
     * its internal_role says. 5 roles x 9 strings x 6 helpers.
     *
     * @dataProvider portalRoles
     */
    public function test_no_portal_account_gains_hr_authority_from_a_role_string(string $role): void
    {
        foreach (self::HR_LOOKING as $internal) {
            $user = $this->user($role, "{$role}-{$internal}@boundary.test", $internal);

            foreach ($this->authorities($user) as $helper => $granted) {
                $this->assertFalse($granted,
                    "A {$role} carrying internal_role '{$internal}' must not satisfy {$helper}(). "
                    .'internal_role is a free string on every account, not a statement of authority.');
            }
        }
    }

    /**
     * Nor from stored permission DATA — the other way somebody could look
     * authorised. The grid is only consulted after the account type is decided.
     *
     * @dataProvider portalRoles
     */
    public function test_no_portal_account_gains_hr_authority_from_permission_data(string $role): void
    {
        $everything = [];
        foreach (StaffPermission::MODULES as $module) {
            $everything[$module] = StaffPermission::CAPABILITIES;
        }

        $user = $this->user($role, "{$role}-grid@boundary.test", 'hr_executive', $everything);

        foreach ($this->authorities($user) as $helper => $granted) {
            $this->assertFalse($granted,
                "A {$role} holding every permission in the grid must still not satisfy {$helper}().");
        }
    }

    /** A company sub-role is its own vocabulary and must not read as staff authority. */
    public function test_company_sub_roles_do_not_cross_into_hr(): void
    {
        foreach ([CompanyRole::ADMIN, CompanyRole::HR, CompanyRole::HIRING_MANAGER] as $sub) {
            $user = $this->user('company', "company-{$sub}@boundary.test", $sub);

            foreach ($this->authorities($user) as $helper => $granted) {
                $this->assertFalse($granted,
                    "Company sub-role '{$sub}' is authority inside THAT company's portal, not inside HR ({$helper}).");
            }
        }
    }

    public static function portalRoles(): array
    {
        return array_combine(
            self::PORTAL_ROLES,
            array_map(fn ($r) => [$r], self::PORTAL_ROLES),
        );
    }

    /* ── staff keep everything they had ───────────────────────────────── */

    public function test_staff_and_admin_authority_is_unchanged(): void
    {
        $admin = $this->user('admin', 'admin@boundary.test');
        foreach ($this->authorities($admin) as $helper => $granted) {
            if ($helper === 'holdsAnyTierRole') {
                continue; // an admin holds no rung BY ROLE; they stand in via isAdmin() elsewhere
            }
            $this->assertTrue($granted, "An admin must still satisfy {$helper}().");
        }

        $this->assertTrue($this->user('staff', 'hre@boundary.test', 'hr_executive')->canManageHrQueue());
        $this->assertTrue($this->user('staff', 'rec@boundary.test', 'hr_recruiter')->canManageHrQueue());
        $this->assertTrue($this->user('staff', 'dh@boundary.test', 'department_head')->canApproveL1());
        $this->assertTrue($this->user('staff', 'hm@boundary.test', 'hiring_manager')->canApproveL1());
        $this->assertTrue($this->user('staff', 'pm@boundary.test', 'project_manager')->canApproveL2());
        $this->assertTrue($this->user('staff', 'se@boundary.test', 'senior_executive')->canApproveL2());
        $this->assertTrue($this->user('staff', 'onb@boundary.test', 'hr_manager')->canManageOnboarding());
        $this->assertTrue($this->user('staff', 'jd@boundary.test', 'hr_recruiter')->canGenerateAiJd());
        $this->assertTrue(
            app(\App\Services\Hr\AdvanceTierService::class)
                ->holdsAnyTierRole($this->user('staff', 'acct@boundary.test', 'accounts'))
        );
    }

    /** And a staff member with no HR role is refused exactly as before. */
    public function test_a_plain_staff_member_is_still_refused(): void
    {
        foreach ($this->authorities($this->user('staff', 'plain@boundary.test')) as $helper => $granted) {
            $this->assertFalse($granted, "A staff member with no role must not satisfy {$helper}().");
        }
    }

    /* ── the portals themselves are untouched ─────────────────────────── */

    /**
     * The half that would be easy to break. A security fix that quietly locks a
     * doctor out of their own portal has not made anything safer.
     *
     * @dataProvider portalRoles
     */
    public function test_a_portal_identity_can_still_authenticate(string $role): void
    {
        RateLimiter::clear("login:{$role}-login@boundary.test|127.0.0.1");

        $this->user($role, "{$role}-login@boundary.test", $role === 'company' ? CompanyRole::ADMIN : null);

        $this->postJson('/api/auth/login', [
            'email' => "{$role}-login@boundary.test", 'password' => 'Password123!',
        ])->assertOk()->assertJsonPath('data.user.role', $role);
    }

    /** A company login keeps the sub-role abilities its own portal reads. */
    public function test_company_portal_abilities_are_intact(): void
    {
        $admin = $this->user('company', 'co-admin@boundary.test', CompanyRole::ADMIN);

        $this->assertTrue($admin->isCompany());
        $this->assertTrue($admin->isCompanyAdmin());
        $this->assertTrue($admin->canManageCompany());
        $this->assertTrue($admin->canManageCompanyInterviews());

        $viewer = $this->user('company', 'co-viewer@boundary.test', CompanyRole::VIEWER);
        $this->assertTrue($viewer->isCompanyViewer());
        $this->assertFalse($viewer->canManageCompany(), 'A viewer manages nothing — unchanged.');
    }

    /** A doctor keeps the identity its portal is built on. */
    public function test_doctor_identity_is_intact(): void
    {
        $doctor = $this->user('doctor', 'doc@boundary.test');

        $this->assertTrue($doctor->isDoctor());
        $this->assertFalse($doctor->isStaffAccount(), 'A doctor is a portal identity, which is the whole point.');
    }

    /* ── employee self-service is not narrowed ────────────────────────── */

    /**
     * Nothing here touches the `self` scope or the My* routes. A plain staff
     * member reaching their own record is not HR authority and must not have
     * been caught by the guard.
     */
    public function test_employee_self_service_still_works(): void
    {
        $user = $this->user('staff', 'selfserve@boundary.test');

        // The My* routes resolve the caller's own HrEmployee and 403 without one
        // ("Your login is not linked to an employee record"). That is their own
        // long-standing rule, not an authority check, so the fixture has to give
        // them a record or this tests the wrong refusal.
        \App\Models\Hr\HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'employee_code' => 'BD-1', 'name' => 'Self Serve',
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2024-01-01',
            'status' => 'Active', 'user_id' => $user->id,
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($user);

        foreach (['/api/hr/me/advances', '/api/hr/me/leave', '/api/hr/me/settings'] as $url) {
            $this->assertNotSame(403, $this->getJson($url)->status(),
                "{$url} is how somebody sees their own record and must stay open.");
        }
    }

    /* ── the attendance app admin door ────────────────────────────────── */

    /**
     * HrmAdminController::deny() admits on isAdmin || canManageHrQueue ||
     * holdsAnyTierRole. All three are now account-type guarded, so the app's 20
     * admin screens inherit the boundary. Checked end to end rather than assumed.
     *
     * @dataProvider portalRoles
     */
    public function test_a_portal_identity_cannot_reach_the_attendance_app_admin(string $role): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->user($role, "{$role}-app@boundary.test", 'director'));

        $this->getJson('/api/Hrm/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('message', 'You do not have access to this.');
    }

    /** And a real staff approver still reaches it. */
    public function test_a_staff_approver_still_reaches_the_attendance_app_admin(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->user('staff', 'appok@boundary.test', 'accounts'));

        $this->assertNotSame(
            'You do not have access to this.',
            $this->getJson('/api/Hrm/admin/dashboard')->json('message'),
        );
    }
}
