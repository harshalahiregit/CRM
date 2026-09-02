<?php

namespace Tests\Feature\Admin;

use App\Exceptions\BusinessException;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\StaffPermissionService;
use App\Services\Auth\StaffRoleService;
use App\Support\Hr\StaffRoleTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Roles as records, following the old CRM's tblroles.
 *
 * The permission templates used to live in a JavaScript map inside StaffModal
 * while the role dropdown was generated separately on the server — so the two
 * disagreed, adding a role meant a deploy, and changing what a role meant never
 * reached anybody who already had it.
 *
 * The most important tests here are the ones proving that NOTHING CHANGES for an
 * account that has no role. Every user who existed before this has a grid and no
 * role, and a permissions change that silently moved any of them would be the
 * worst possible outcome of a refactor like this.
 */
class StaffRoleTest extends TestCase
{
    use RefreshDatabase;

    private StaffRoleService $roles;
    private StaffPermissionService $perms;
    private ?Tenant $t = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roles = app(StaffRoleService::class);
        $this->perms = app(StaffPermissionService::class);
    }

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'T', 'slug' => 'role-t', 'status' => 'active']);
    }

    private function user(string $role, string $email, array $grid = []): User
    {
        return User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'U', 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $role, 'status' => 'active',
            'internal_role' => 'general',
            'meta' => $grid ? ['permissions' => $grid] : null,
        ]);
    }

    /* ── seeding ─────────────────────────────────────────────────────── */

    public function test_a_tenant_gets_the_standard_roles_on_first_read(): void
    {
        $roles = $this->roles->forTenant($this->tenant()->id);

        $this->assertCount(count(StaffRoleTemplate::slugs()), $roles);
        $this->assertTrue($roles->every(fn ($r) => $r->is_system));
    }

    /**
     * The two the advance ladder needs. They existed in no list anywhere, which
     * is exactly why it had no approvers.
     */
    public function test_accounts_and_director_are_among_them(): void
    {
        $slugs = $this->roles->forTenant($this->tenant()->id)->pluck('slug');

        $this->assertContains('accounts', $slugs);
        $this->assertContains('director', $slugs);
    }

    public function test_seeding_twice_does_not_duplicate_or_overwrite(): void
    {
        $this->roles->forTenant($this->tenant()->id);

        $employee = StaffRole::where('tenant_id', $this->tenant()->id)->where('slug', 'employee')->firstOrFail();
        $employee->update(['name' => 'Team Member']);

        $added = $this->roles->ensureSeeded($this->tenant()->id);

        $this->assertSame(0, $added);
        $this->assertSame('Team Member', $employee->fresh()->name, 'A rename must survive re-seeding.');
        $this->assertSame(count(StaffRoleTemplate::slugs()), StaffRole::where('tenant_id', $this->tenant()->id)->count());
    }

    public function test_roles_do_not_leak_between_tenants(): void
    {
        $this->roles->forTenant($this->tenant()->id);
        $other = Tenant::create(['name' => 'O', 'slug' => 'role-o', 'status' => 'active']);
        $this->roles->forTenant($other->id);

        $this->assertSame(
            count(StaffRoleTemplate::slugs()),
            StaffRole::where('tenant_id', $this->tenant()->id)->count()
        );
    }

    /* ── nothing changes for existing accounts ───────────────────────── */

    public function test_a_user_with_no_role_keeps_exactly_the_grid_they_had(): void
    {
        $u = $this->user('staff', 'old@example.test', ['invoices' => ['view_own', 'create']]);

        $this->assertSame(['invoices' => ['view_own', 'create']], $this->perms->grantsFor($u));
        $this->assertTrue($this->perms->can($u, 'create', 'invoices'));
        $this->assertFalse($this->perms->can($u, 'delete', 'invoices'));
    }

    public function test_a_user_with_neither_role_nor_grid_has_nothing(): void
    {
        $u = $this->user('staff', 'bare@example.test');

        $this->assertSame([], $this->perms->grantsFor($u));
        $this->assertFalse($this->perms->can($u, 'view_global', 'invoices'));
    }

    /* ── what a role actually does ───────────────────────────────────── */

    public function test_a_role_grants_what_it_says(): void
    {
        $u = $this->user('staff', 'acc@example.test');
        $accounts = $this->roles->forTenant($this->tenant()->id)->firstWhere('slug', 'accounts');

        $this->roles->assign($u, $accounts);

        $this->assertTrue($this->perms->can($u->fresh(), 'edit', 'invoices'));
    }

    /** The whole point of records over copy-on-create templates. */
    public function test_changing_a_role_reaches_everybody_who_holds_it(): void
    {
        $u = $this->user('staff', 'acc@example.test');
        $accounts = $this->roles->forTenant($this->tenant()->id)->firstWhere('slug', 'accounts');
        $this->roles->assign($u, $accounts);

        $this->assertFalse($this->perms->can($u->fresh(), 'view_global', 'tickets'));

        $this->roles->update($accounts, [
            'permissions' => array_merge($accounts->permissions, ['tickets' => ['view_global']]),
        ]);

        $this->assertTrue($this->perms->can($u->fresh(), 'view_global', 'tickets'),
            'A role change must reach people who already have it.');
    }

    /**
     * A grid entry is somebody's decision about that module, so it wins whole —
     * merging the role's capabilities back in would re-grant what was just taken.
     */
    public function test_a_personal_grid_overrides_the_role_for_that_module(): void
    {
        $accounts = $this->roles->forTenant($this->tenant()->id)->firstWhere('slug', 'accounts');
        $u = $this->user('staff', 'acc@example.test', ['invoices' => ['view_own']]);

        $this->roles->assign($u, $accounts);
        $u = $u->fresh();

        $this->assertTrue($this->perms->can($u, 'view_own', 'invoices'));
        $this->assertFalse($this->perms->can($u, 'edit', 'invoices'), 'The override must not be re-widened by the role.');
        // Modules the grid says nothing about still come from the role.
        $this->assertTrue($this->perms->can($u, 'view_global', 'reports'));
    }

    /* ── the slug is load-bearing ────────────────────────────────────── */

    public function test_assigning_writes_the_slug_to_internal_role(): void
    {
        $u = $this->user('staff', 'acc@example.test');
        $accounts = $this->roles->forTenant($this->tenant()->id)->firstWhere('slug', 'accounts');

        $this->roles->assign($u, $accounts);

        // This is what makes every hardcoded internal_role check keep working —
        // AdvanceTierService among them, which is how the ladder gets approvers.
        $this->assertSame('accounts', $u->fresh()->internal_role);
    }

    public function test_renaming_a_role_does_not_move_its_slug(): void
    {
        $accounts = $this->roles->forTenant($this->tenant()->id)->firstWhere('slug', 'accounts');

        $this->roles->update($accounts, ['name' => 'Finance Team']);

        $this->assertSame('accounts', $accounts->fresh()->slug,
            'Moving the slug would silently revoke access from everybody on the role.');
    }

    public function test_clearing_a_role_does_not_blank_the_job_title(): void
    {
        $u = $this->user('staff', 'acc@example.test');
        $accounts = $this->roles->forTenant($this->tenant()->id)->firstWhere('slug', 'accounts');

        $this->roles->assign($u, $accounts);
        $this->roles->assign($u->fresh(), null);

        $u = $u->fresh();
        $this->assertNull($u->staff_role_id);
        $this->assertSame('accounts', $u->internal_role, 'Losing the title would be a silent demotion.');
    }

    /* ── custom roles ────────────────────────────────────────────────── */

    public function test_a_custom_role_can_be_created_and_deleted(): void
    {
        $role = $this->roles->create($this->tenant()->id, [
            'name' => 'Site Supervisor', 'permissions' => ['projects' => ['view_global']],
        ]);

        $this->assertSame('site_supervisor', $role->slug);
        $this->assertFalse($role->is_system);

        $this->roles->delete($role);
        $this->assertDatabaseMissing('staff_roles', ['id' => $role->id]);
    }

    public function test_a_standard_role_cannot_be_deleted(): void
    {
        $employee = $this->roles->forTenant($this->tenant()->id)->firstWhere('slug', 'employee');

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('edited but not deleted');

        $this->roles->delete($employee);
    }

    public function test_a_role_in_use_cannot_be_deleted(): void
    {
        $role = $this->roles->create($this->tenant()->id, ['name' => 'Site Supervisor']);
        $this->roles->assign($this->user('staff', 'sup@example.test'), $role);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('still has this role');

        $this->roles->delete($role->fresh());
    }

    public function test_two_roles_cannot_share_a_name(): void
    {
        $this->roles->create($this->tenant()->id, ['name' => 'Site Supervisor']);

        $this->expectException(BusinessException::class);
        $this->roles->create($this->tenant()->id, ['name' => 'Site  Supervisor']);
    }

    public function test_an_unknown_module_in_a_role_grants_nothing(): void
    {
        $role = $this->roles->create($this->tenant()->id, [
            'name' => 'Odd', 'permissions' => ['not_a_module' => ['edit'], 'invoices' => ['not_a_capability', 'edit']],
        ]);

        $this->assertArrayNotHasKey('not_a_module', $role->grants());
        $this->assertSame(['edit'], $role->grants()['invoices']);
    }

    /* ── over HTTP ───────────────────────────────────────────────────── */

    public function test_the_endpoints_work_and_are_gated(): void
    {
        $admin = $this->user('admin', 'admin@example.test');
        $staff = $this->user('staff', 'nobody@example.test');

        Sanctum::actingAs($staff);
        $this->getJson('/api/admin/roles')->assertStatus(403);

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/roles')
            ->assertOk()
            ->assertJsonPath('data.roles.0.is_system', true)
            ->assertJsonStructure(['data' => ['roles', 'modules', 'capabilities']]);

        // The dropdown and the roles are now the SAME list — the mismatch that
        // left 'junior_executive' with no template cannot recur.
        $designations = $this->getJson('/api/admin/staff/designations')->assertOk()->json('data');
        $this->assertSame(
            collect($designations)->pluck('value')->sort()->values()->all(),
            StaffRole::where('tenant_id', $this->tenant()->id)->pluck('slug')->sort()->values()->all()
        );
    }

    public function test_a_staff_member_can_be_created_on_a_role(): void
    {
        $admin = $this->user('admin', 'admin@example.test');
        $accounts = $this->roles->forTenant($this->tenant()->id)->firstWhere('slug', 'accounts');

        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/staff', [
            'name' => 'Priya', 'email' => 'priya@example.test', 'password' => 'Password123!',
            'internal_role' => 'employee', 'status' => 'active',
            'staff_role_id' => $accounts->id,
        ])->assertCreated();

        $created = User::where('email', 'priya@example.test')->firstOrFail();

        $this->assertSame($accounts->id, $created->staff_role_id);
        $this->assertSame('accounts', $created->internal_role, 'The slug must win over the posted internal_role.');
        $this->assertTrue($this->perms->can($created, 'edit', 'invoices'));
    }

    public function test_a_role_can_be_changed_on_an_existing_staff_member(): void
    {
        $admin = $this->user('admin', 'admin@example.test');
        $staff = $this->user('staff', 'priya@example.test');
        $director = $this->roles->forTenant($this->tenant()->id)->firstWhere('slug', 'director');

        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/staff/{$staff->id}", [
            'name' => 'Priya', 'staff_role_id' => $director->id,
        ])->assertOk();

        $this->assertSame('director', $staff->fresh()->internal_role);
    }
}
