<?php

namespace Tests\Feature\Hr;

use App\Models\StaffRole;
use App\Models\Tenant;
use App\Services\Auth\StaffRoleService;
use App\Support\Hr\StaffPermission;
use App\Support\Hr\StaffRoleTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two standard roles the product named and the templates did not carry.
 *
 * HR Executive and HR Recruiter both existed, but neither is the person who
 * ADMINISTERS HR — who configures the module, edits anybody's employee record
 * and owns leave and exit. And nothing described somebody who runs payroll
 * without also running recruitment: 'accounts' is the finance role, scoped to
 * invoices and expenses, granting no HR module at all.
 *
 * Both are built from the existing permission vocabulary. No capability,
 * module or scope is introduced for them, and the tests below are mostly about
 * what they must NOT carry — a standard role that quietly grows privileges is
 * worse than a missing one.
 *
 * Interviewer, Reporting Manager and Super Admin are deliberately NOT here.
 * The first two are relationships — an interviewer is assigned per round on
 * hr_interview_rounds, a reporting manager is a column on the employee — and
 * the third is not a cross-tenant concept in this product. A previous audit of
 * mine listed Interviewer as a missing role; that was wrong, and a test below
 * pins the decision so it is not "fixed" later.
 */
class StandardHrRoleTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;
    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Tenant::create(['name' => 'Alpha', 'slug' => 'srt-a', 'status' => 'active']);
        $this->b = Tenant::create(['name' => 'Beta', 'slug' => 'srt-b', 'status' => 'active']);
    }

    private function roles(): StaffRoleService
    {
        return app(StaffRoleService::class);
    }

    private function role(string $slug, ?Tenant $t = null): ?StaffRole
    {
        return StaffRole::where('tenant_id', ($t ?: $this->a)->id)->where('slug', $slug)->first();
    }

    /* ═══════════════ 1. THEY EXIST AND SEED ════════════════════════ */

    /** @test */
    public function both_roles_are_registered_as_templates(): void
    {
        foreach (['hr_admin' => 'HR Admin', 'payroll_admin' => 'Payroll Admin'] as $slug => $label) {
            $def = StaffRoleTemplate::find($slug);
            $this->assertNotNull($def, "{$slug} is not a template");
            $this->assertSame($label, $def['label']);
            $this->assertNotEmpty($def['permissions'], "{$slug} must grant something");
        }
    }

    /** @test */
    public function seeding_a_tenant_creates_both_roles_as_system_roles(): void
    {
        $this->roles()->forTenant($this->a->id);

        foreach (['hr_admin', 'payroll_admin'] as $slug) {
            $role = $this->role($slug);
            $this->assertNotNull($role, "{$slug} was not seeded");
            $this->assertTrue((bool) $role->is_system);
            $this->assertFalse((bool) ($role->is_vocabulary_only ?? false), "{$slug} must actually grant access");
        }
    }

    /** @test */
    public function seeding_is_idempotent_and_creates_no_duplicates(): void
    {
        $this->roles()->ensureSeeded($this->a->id);
        $firstCount = StaffRole::where('tenant_id', $this->a->id)->count();

        $added = $this->roles()->ensureSeeded($this->a->id);

        $this->assertSame(0, $added, 'A second run adds nothing.');
        $this->assertSame($firstCount, StaffRole::where('tenant_id', $this->a->id)->count());

        foreach (['hr_admin', 'payroll_admin'] as $slug) {
            $this->assertSame(1, StaffRole::where('tenant_id', $this->a->id)->where('slug', $slug)->count());
        }
    }

    /** A role somebody renamed is left alone by a later seed. */
    public function test_seeding_never_overwrites_a_renamed_role(): void
    {
        $this->roles()->ensureSeeded($this->a->id);
        $this->role('hr_admin')->update(['name' => 'People Ops Lead', 'permissions' => ['self' => ['view_own']]]);

        $this->roles()->ensureSeeded($this->a->id);

        $role = $this->role('hr_admin');
        $this->assertSame('People Ops Lead', $role->name);
        $this->assertSame(['self' => ['view_own']], $role->permissions);
    }

    /** @test */
    public function the_roles_are_scoped_to_their_own_tenant(): void
    {
        $this->roles()->ensureSeeded($this->a->id);

        $this->assertNotNull($this->role('hr_admin', $this->a));
        $this->assertNull($this->role('hr_admin', $this->b), 'Seeding one tenant must not touch another.');
    }

    /* ═══════════════ 2. THE PERMISSIONS ARE REAL ═══════════════════ */

    /**
     * Every module and capability comes from the existing vocabulary.
     *
     * sanitise() drops anything it does not recognise, so a template naming a
     * module that does not exist would silently seed a role granting less than
     * it claims.
     *
     * @test
     */
    public function every_permission_survives_sanitisation(): void
    {
        foreach (['hr_admin', 'payroll_admin'] as $slug) {
            $declared = StaffRoleTemplate::find($slug)['permissions'];
            $kept     = StaffPermission::sanitise($declared);

            $this->assertSame(
                array_keys($declared), array_keys($kept),
                "{$slug} declares a module the permission grid does not know"
            );

            foreach ($declared as $module => $caps) {
                $this->assertTrue(StaffPermission::isModule($module), "{$module} is not a module");
                $this->assertSame($caps, $kept[$module], "{$slug}/{$module} lost a capability");
            }
        }
    }

    /** @test */
    public function hr_admin_owns_the_hr_module_and_its_settings(): void
    {
        $perms = StaffRoleTemplate::find('hr_admin')['permissions'];

        foreach (['hr_employees', 'hr_leave', 'hr_exit', 'hr_recruitment'] as $module) {
            $this->assertContains('edit', $perms[$module] ?? [], "HR Admin should administer {$module}");
        }
        $this->assertContains('edit', $perms['hr_settings'] ?? [], 'HR Admin configures the module.');
    }

    /** @test */
    public function payroll_admin_runs_payroll_and_reads_what_it_is_calculated_from(): void
    {
        $perms = StaffRoleTemplate::find('payroll_admin')['permissions'];

        $this->assertContains('edit', $perms['hr_payroll'] ?? [], 'Payroll Admin runs payroll.');

        foreach (['hr_attendance', 'hr_leave', 'hr_employees'] as $module) {
            $this->assertContains('view_global', $perms[$module] ?? [], "Payroll is calculated from {$module}");
            $this->assertNotContains('edit', $perms[$module] ?? [],
                "Payroll Admin must not edit {$module} — correcting its own inputs is the HR queue's decision");
        }
    }

    /* ═══════════════ 3. NO PRIVILEGE ESCALATION ════════════════════ */

    /**
     * Administering HR and running payroll stay different jobs.
     *
     * A workspace that wants one person doing both assigns both roles; that is
     * not decided here.
     *
     * @test
     */
    public function hr_admin_does_not_inherit_payroll(): void
    {
        $perms = StaffRoleTemplate::find('hr_admin')['permissions'];

        $this->assertArrayNotHasKey('hr_payroll', $perms);
    }

    /** @test */
    public function payroll_admin_cannot_change_the_rules_it_is_measured_by(): void
    {
        $perms = StaffRoleTemplate::find('payroll_admin')['permissions'];

        $this->assertArrayNotHasKey('hr_settings', $perms);
        $this->assertArrayNotHasKey('staff_mgmt', $perms, 'Running payroll is not managing staff accounts.');
    }

    /**
     * POSH is never granted by inheritance.
     *
     * hr_posh_intake and hr_posh_reports are deliberately separate authorities
     * — the person who takes a complaint at the door is deliberately not
     * entitled to read the file afterwards.
     *
     * @test
     */
    public function neither_role_inherits_any_posh_authority(): void
    {
        foreach (['hr_admin', 'payroll_admin'] as $slug) {
            $perms = StaffRoleTemplate::find($slug)['permissions'];

            $this->assertArrayNotHasKey('hr_posh_intake', $perms, "{$slug} must not inherit POSH intake");
            $this->assertArrayNotHasKey('hr_posh_reports', $perms, "{$slug} must not inherit POSH reporting");
        }
    }

    /** Neither role picks up a manpower approval rung by inheritance. */
    public function test_neither_role_inherits_a_manpower_approval_rung(): void
    {
        foreach (['hr_admin', 'payroll_admin'] as $slug) {
            $perms = StaffRoleTemplate::find($slug)['permissions'];

            $this->assertArrayNotHasKey('hr_manpower_l1', $perms);
            $this->assertArrayNotHasKey('hr_manpower_l2', $perms);
        }
    }

    /* ═══════════════ 4. NOTHING ELSE MOVED ═════════════════════════ */

    /** @test */
    public function the_existing_roles_are_untouched(): void
    {
        foreach ([
            'employee', 'team_lead', 'senior_executive', 'project_manager', 'department_head',
            'hr_recruiter', 'hr_executive', 'hiring_manager', 'accounts', 'hr', 'manager', 'director',
        ] as $slug) {
            $this->assertNotNull(StaffRoleTemplate::find($slug), "{$slug} disappeared");
        }
    }

    /** The two vocabulary-only roles still grant nothing. */
    public function test_the_vocabulary_only_roles_still_grant_nothing(): void
    {
        foreach (['hr', 'manager'] as $slug) {
            $def = StaffRoleTemplate::find($slug);
            $this->assertTrue((bool) ($def['is_vocabulary_only'] ?? false), "{$slug} must stay vocabulary-only");
            $this->assertSame([], $def['permissions'], "{$slug} must grant nothing");
        }
    }

    /**
     * Interviewer, Reporting Manager and Super Admin are NOT roles.
     *
     * The first two are relationships — an interviewer is assigned per round on
     * hr_interview_rounds, a reporting manager is a column on the employee —
     * and Super Admin is not a cross-tenant concept here. Pinned so a future
     * reading of the product requirements does not add them as templates.
     *
     * @test
     */
    public function the_relationship_based_concepts_are_not_roles(): void
    {
        foreach (['interviewer', 'reporting_manager', 'super_admin', 'superadmin'] as $slug) {
            $this->assertNull(StaffRoleTemplate::find($slug),
                "{$slug} is a relationship or a account type, not a staff role");
        }
    }

    /** A custom role created by hand still behaves exactly as before. */
    public function test_custom_roles_are_unaffected(): void
    {
        $this->roles()->ensureSeeded($this->a->id);

        $custom = $this->roles()->create($this->a->id, [
            'name' => 'Regional Lead',
            'permissions' => ['hr_employees' => ['view_global']],
        ]);

        $this->assertFalse((bool) $custom->is_system);
        $this->assertSame(['hr_employees' => ['view_global']], $custom->permissions);

        $this->roles()->ensureSeeded($this->a->id);
        $this->assertNotNull(StaffRole::find($custom->id), 'Seeding must not disturb a custom role.');
    }
}
