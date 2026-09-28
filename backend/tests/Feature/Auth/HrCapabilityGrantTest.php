<?php

namespace Tests\Feature\Auth;

use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\StaffRoleService;
use App\Support\Hr\StaffPermission;
use App\Support\Hr\StaffRoleTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Four HR authorities that a configured role could never hold.
 *
 * canApproveL1, canApproveL2, canManageOnboarding and canGenerateAiJd each
 * matched users.internal_role against a fixed list of slugs. An admin could
 * create "Regional Head" in HR Settings, tick every box on the grid, and that
 * person still could not approve a manpower request — a developer had to add
 * the slug to PHP first, which is exactly what configurable roles exist to
 * remove.
 *
 * Each is now an ADDITIONAL way in, never a replacement: every original clause
 * is evaluated first and unchanged, so this can only widen. That matters more
 * than it sounds — the alternative, making the grid authoritative, would have
 * revoked these authorities from every currently seeded role on deploy.
 *
 * Advance tiers are deliberately NOT here. Their manager rung is resolved per
 * record from the reporting line, which no permission can express, and the
 * three rungs are an ordered ladder rather than three capabilities. That is
 * approval-chain work.
 */
class HrCapabilityGrantTest extends TestCase
{
    use RefreshDatabase;

    /** module => the helper it now also answers. */
    private const AUTHORITIES = [
        'hr_manpower_l1' => 'canApproveL1',
        'hr_manpower_l2' => 'canApproveL2',
        'hr_onboarding'  => 'canManageOnboarding',
        'hr_ai_jd'       => 'canGenerateAiJd',
    ];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Caps', 'slug' => 'hr-capability-grant', 'status' => 'active',
        ]);
    }

    private function user(string $accountType, string $email, ?string $internal = null, ?StaffRole $role = null): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $accountType,
            'internal_role' => $internal, 'status' => 'active', 'staff_role_id' => $role?->id,
        ]);
    }

    private function roleGranting(string $module, string $name): StaffRole
    {
        return app(StaffRoleService::class)->create($this->tenant->id, [
            'name' => $name, 'permissions' => [$module => [StaffPermission::VIEW_GLOBAL]],
        ]);
    }

    /* ── the configurability gap, closed ──────────────────────────────── */

    /**
     * A role invented by an admin, whose slug appears in no hardcoded list,
     * holds the authority because the grid says so.
     *
     * @dataProvider authorities
     */
    public function test_a_custom_role_can_receive_the_authority(string $module, string $helper): void
    {
        $role = $this->roleGranting($module, 'Regional Head '.$module);
        $user = app(StaffRoleService::class)->assign($this->user('staff', "custom-{$module}@caps.test"), $role);

        $this->assertSame('regional_head_'.$module, $role->slug,
            'A slug that appears in no hardcoded list anywhere.');
        $this->assertTrue($user->fresh()->{$helper}(),
            "{$helper}() must honour a configured role, not only the slugs a developer wrote.");
    }

    /**
     * And taking the grant away takes the authority away — no deploy, which is
     * the half that makes it configuration rather than decoration.
     *
     * @dataProvider authorities
     */
    public function test_removing_the_grant_removes_the_authority(string $module, string $helper): void
    {
        $service = app(StaffRoleService::class);
        $role    = $this->roleGranting($module, 'Temp '.$module);
        $user    = $service->assign($this->user('staff', "temp-{$module}@caps.test"), $role);

        $this->assertTrue($user->fresh()->{$helper}());

        $service->update($role, ['permissions' => [$module => []]]);

        $this->assertFalse($user->fresh()->{$helper}());
    }

    /** One authority does not leak into another. */
    public function test_each_authority_is_separate(): void
    {
        foreach (self::AUTHORITIES as $module => $helper) {
            $role = $this->roleGranting($module, 'Only '.$module);
            $user = app(StaffRoleService::class)->assign($this->user('staff', "only-{$module}@caps.test"), $role);

            foreach (self::AUTHORITIES as $otherModule => $otherHelper) {
                $expected = $otherModule === $module;
                $this->assertSame($expected, $user->fresh()->{$otherHelper}(),
                    "Granting {$module} must ".($expected ? 'grant' : 'NOT grant')." {$otherHelper}().");
            }
        }
    }

    /** And none of them is a way into the HR queue. */
    public function test_no_narrow_authority_confers_the_hr_queue(): void
    {
        foreach (array_keys(self::AUTHORITIES) as $module) {
            $role = $this->roleGranting($module, 'Narrow '.$module);
            $user = app(StaffRoleService::class)->assign($this->user('staff', "narrow-{$module}@caps.test"), $role);

            $this->assertFalse($user->fresh()->canManageHrQueue(),
                "{$module} is one authority, not HR management.");
        }
    }

    public static function authorities(): array
    {
        return [
            'L1 approval'   => ['hr_manpower_l1', 'canApproveL1'],
            'L2 approval'   => ['hr_manpower_l2', 'canApproveL2'],
            'onboarding'    => ['hr_onboarding', 'canManageOnboarding'],
            'AI JD'         => ['hr_ai_jd', 'canGenerateAiJd'],
        ];
    }

    /* ── nothing that worked before may stop working ──────────────────── */

    /**
     * Every slug the hardcoded lists named still holds its authority, whether
     * or not a grid backs it. This is the property that makes the change safe
     * to deploy.
     *
     * @dataProvider legacySlugs
     */
    public function test_every_legacy_slug_keeps_its_authority(string $slug, string $helper): void
    {
        $user = $this->user('staff', "legacy-{$slug}-{$helper}@caps.test", $slug);

        $this->assertTrue($user->{$helper}(),
            "internal_role '{$slug}' granted {$helper}() before and must still, with no role and no grid.");
    }

    public static function legacySlugs(): array
    {
        return [
            'department_head L1'   => ['department_head', 'canApproveL1'],
            'hiring_manager L1'    => ['hiring_manager', 'canApproveL1'],
            'project_manager L2'   => ['project_manager', 'canApproveL2'],
            'senior_executive L2'  => ['senior_executive', 'canApproveL2'],
            'hr_recruiter onboard' => ['hr_recruiter', 'canManageOnboarding'],
            'hr_executive onboard' => ['hr_executive', 'canManageOnboarding'],
            // Not a seeded template slug, but the list names it, so it must work.
            'hr_manager onboard'   => ['hr_manager', 'canManageOnboarding'],
            'hr_recruiter ai'      => ['hr_recruiter', 'canGenerateAiJd'],
            'hr_manager ai'        => ['hr_manager', 'canGenerateAiJd'],
        ];
    }

    /** An admin keeps everything, and a bare staff member still has none of it. */
    public function test_admin_and_plain_staff_are_unchanged(): void
    {
        $admin = $this->user('admin', 'admin@caps.test');
        $plain = $this->user('staff', 'plain@caps.test');

        foreach (self::AUTHORITIES as $helper) {
            $this->assertTrue($admin->{$helper}(), "An admin must keep {$helper}().");
            $this->assertFalse($plain->{$helper}(), "A staff member with no role must not gain {$helper}().");
        }
    }

    /* ── the seeded roles say it in permissions now ───────────────────── */

    /**
     * The templates carry the authority the hardcoded list gave them, so a
     * freshly seeded tenant does not depend on the slug clause at all.
     *
     * Existing rows are NOT updated — ensureSeeded() is create-only by design —
     * and they do not need to be, because the slug clause still answers for them.
     */
    public function test_the_seeded_templates_carry_the_authorities(): void
    {
        $expected = [
            'hr_manpower_l1' => ['department_head', 'hiring_manager'],
            'hr_manpower_l2' => ['project_manager', 'senior_executive'],
            'hr_onboarding'  => ['hr_recruiter', 'hr_executive'],
            'hr_ai_jd'       => ['hr_recruiter'],
        ];

        foreach ($expected as $module => $slugs) {
            $holders = array_keys(array_filter(
                StaffRoleTemplate::DEFINITIONS,
                fn ($def) => isset($def['permissions'][$module]),
            ));

            sort($holders);
            sort($slugs);
            $this->assertSame($slugs, $holders,
                "{$module} must be granted to exactly the roles the hardcoded list named.");
        }
    }

    /** A seeded role holds its authority through the grid, not only its slug. */
    public function test_a_seeded_role_holds_its_authority_through_permissions(): void
    {
        app(StaffRoleService::class)->ensureSeeded($this->tenant->id);

        $role = StaffRole::where('tenant_id', $this->tenant->id)->where('slug', 'department_head')->firstOrFail();

        $this->assertSame([StaffPermission::VIEW_GLOBAL], $role->grants()['hr_manpower_l1'] ?? null);

        // internal_role deliberately left null: only the grid can answer here.
        $user = $this->user('staff', 'seeded@caps.test', null, $role);

        $this->assertTrue($user->canApproveL1());
    }

    /* ── the boundaries that must hold ────────────────────────────────── */

    /**
     * A portal identity granted every one of these still holds none of them:
     * isStaffAccount() runs before the grid is consulted.
     *
     * @dataProvider portalRoles
     */
    public function test_a_portal_account_gains_nothing_from_the_grid(string $accountType): void
    {
        $everything = [];
        foreach (array_keys(self::AUTHORITIES) as $module) {
            $everything[$module] = [StaffPermission::VIEW_GLOBAL];
        }

        $role = app(StaffRoleService::class)->create($this->tenant->id, [
            'name' => 'Sneak '.$accountType, 'permissions' => $everything,
        ]);

        $user = $this->user($accountType, "portal-{$accountType}@caps.test", 'hr_recruiter', $role);

        foreach (self::AUTHORITIES as $helper) {
            $this->assertFalse($user->{$helper}(),
                "A {$accountType} must not hold {$helper}(), by grid or by role string.");
        }
    }

    public static function portalRoles(): array
    {
        return [
            'client' => ['client'], 'vendor' => ['vendor'],
            'third party vendor' => ['third_party_vendor'],
            'company' => ['company'], 'doctor' => ['doctor'],
        ];
    }

    /** The Phase 2 vocabulary entries stay permissionless. */
    public function test_vocabulary_only_roles_gain_no_capability(): void
    {
        app(StaffRoleService::class)->ensureSeeded($this->tenant->id);

        foreach (['hr', 'manager'] as $slug) {
            $role = StaffRole::where('tenant_id', $this->tenant->id)->where('slug', $slug)->firstOrFail();

            $this->assertTrue($role->is_vocabulary_only);
            $this->assertSame(0, $role->granted_count);

            $user = app(StaffRoleService::class)->assign($this->user('staff', "vocab-{$slug}@caps.test"), $role);

            foreach (self::AUTHORITIES as $helper) {
                $this->assertFalse($user->fresh()->{$helper}(),
                    "'{$slug}' is a name, not a grant — it must not confer {$helper}().");
            }
        }
    }

    /* ── advance tiers are deliberately untouched ─────────────────────── */

    /**
     * Pinned so the deferral is explicit rather than an oversight: the tier
     * roles are still matched as slugs, because a tier is a rung on an ordered
     * ladder — and the manager rung is resolved per record from the reporting
     * line, which no permission can express.
     */
    public function test_advance_tiers_still_read_role_slugs(): void
    {
        $service = app(\App\Services\Hr\AdvanceTierService::class);

        $this->assertTrue($service->holdsAnyTierRole($this->user('staff', 'acct@caps.test', 'accounts')));
        $this->assertTrue($service->holdsAnyTierRole($this->user('staff', 'dir@caps.test', 'director')));

        // A grid grant does NOT confer a tier — there is no such permission.
        $role = $this->roleGranting('hr_manpower_l2', 'Not A Tier');
        $user = app(StaffRoleService::class)->assign($this->user('staff', 'nottier@caps.test'), $role);

        $this->assertFalse($service->holdsAnyTierRole($user->fresh()),
            'Tier membership is approval-chain work and must not leak out of a capability grant.');
    }
}
