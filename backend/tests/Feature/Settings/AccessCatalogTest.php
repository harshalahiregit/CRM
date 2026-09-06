<?php

namespace Tests\Feature\Settings;

use App\Models\Access\AccessRole;
use App\Models\Access\Department;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Access\AccessCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Roles and departments can be created from the UI, without breaking access.
 *
 * The brief asked for "direct creation of roles and departments through UI
 * settings pages to eliminate backend developer dependencies". The risk in
 * granting that is obvious: a settings screen that can rewrite what a route
 * guard checks can lock the whole company out of the system.
 *
 * So the guards are untouched, and these tests pin the three rules that keep it
 * safe — a slug is fixed once set, a system role cannot be deleted, and nothing
 * in use can be removed.
 */
class AccessCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant)->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function user(string $role = 'admin', array $extra = []): User
    {
        return User::create(array_merge([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ], $extra));
    }

    private function asAdmin(): User
    {
        $admin = $this->user('admin');
        Sanctum::actingAs($admin);

        return $admin;
    }

    /* ── Roles ──────────────────────────────────────────────────────────── */

    public function test_the_built_in_roles_appear_without_being_created(): void
    {
        // internal_role was an ad-hoc string with no catalogue anywhere: nothing
        // listed the roles the code already checks for, so an admin had no way
        // to know what to type. They are seeded on first read.
        $this->asAdmin();

        $slugs = collect($this->getJson('/api/settings/roles')->assertOk()->json('data'))->pluck('slug');

        foreach (array_keys(AccessCatalogService::SYSTEM_ROLES) as $expected) {
            $this->assertContains($expected, $slugs->all(), "{$expected} should be seeded");
        }
    }

    public function test_an_admin_can_create_a_role(): void
    {
        $this->asAdmin();

        $this->postJson('/api/settings/roles', ['name' => 'Site Supervisor'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'site_supervisor');

        $this->assertDatabaseHas('access_roles', ['slug' => 'site_supervisor', 'is_system' => false]);
    }

    public function test_a_role_created_here_is_what_a_route_guard_reads(): void
    {
        // The point of the whole exercise: a role created in Settings must be
        // usable by the guards that already exist, with no code change. The
        // guard compares users.internal_role as a plain string, and the slug is
        // exactly what goes in that column.
        $admin = $this->asAdmin();
        $slug = $this->postJson('/api/settings/roles', ['name' => 'Site Supervisor'])
            ->assertCreated()->json('data.slug');

        $staff = $this->user('staff', ['internal_role' => $slug]);

        $this->assertSame('site_supervisor', $staff->fresh()->internal_role);
        $this->assertSame(1, AccessRole::where('slug', $slug)->first()->user_count);
    }

    public function test_a_built_in_role_cannot_be_shadowed_before_it_is_seeded(): void
    {
        // The built-ins are created on first READ. Without seeding on create
        // too, a workspace that had never opened the roles screen could create
        // "Manager" as an ordinary role — and the real, code-referenced Manager
        // could then never be seeded beside it.
        $this->asAdmin();

        $this->postJson('/api/settings/roles', ['name' => 'Manager'])->assertStatus(422);
    }

    public function test_the_slug_cannot_be_changed_after_creation(): void
    {
        // The slug is the credential. Renaming it would strip access from
        // everyone holding it, silently, so only the label may be edited.
        $this->asAdmin();
        $id = $this->postJson('/api/settings/roles', ['name' => 'Site Supervisor'])->json('data.id');

        $this->putJson("/api/settings/roles/{$id}", ['name' => 'Area Supervisor', 'slug' => 'something_else'])
            ->assertOk()->assertJsonPath('data.name', 'Area Supervisor');

        $this->assertSame('site_supervisor', AccessRole::find($id)->slug, 'the slug is fixed once set');
    }

    public function test_a_system_role_cannot_be_deleted(): void
    {
        $this->asAdmin();
        $this->getJson('/api/settings/roles');   // seeds them

        $hr = AccessRole::where('slug', 'hr_executive')->firstOrFail();

        $this->deleteJson("/api/settings/roles/{$hr->id}")->assertStatus(422);
        $this->assertDatabaseHas('access_roles', ['id' => $hr->id]);
    }

    public function test_a_role_somebody_holds_cannot_be_deleted(): void
    {
        // Deleting it would leave those people with an internal_role matching
        // nothing — which reads as "no access", with no explanation anywhere.
        $this->asAdmin();
        $id = $this->postJson('/api/settings/roles', ['name' => 'Site Supervisor'])->json('data.id');
        $this->user('staff', ['internal_role' => 'site_supervisor']);

        $this->deleteJson("/api/settings/roles/{$id}")->assertStatus(422);
        $this->assertDatabaseHas('access_roles', ['id' => $id]);
    }

    public function test_an_unused_custom_role_can_be_deleted(): void
    {
        $this->asAdmin();
        $id = $this->postJson('/api/settings/roles', ['name' => 'Temporary'])->json('data.id');

        $this->deleteJson("/api/settings/roles/{$id}")->assertOk();
        $this->assertDatabaseMissing('access_roles', ['id' => $id]);
    }

    public function test_a_role_cannot_impersonate_an_account_type(): void
    {
        // A job role slugged "admin" would be read as the admin ACCOUNT TYPE by
        // the guard, handing out access nobody granted.
        $this->asAdmin();

        $this->postJson('/api/settings/roles', ['name' => 'Admin'])->assertStatus(422);
        $this->postJson('/api/settings/roles', ['name' => 'Doctor'])->assertStatus(422);
    }

    public function test_account_types_are_listed_but_not_editable(): void
    {
        // Shown so an admin sees the whole picture and understands why these
        // cannot be created here — each is a portal, not a settings row.
        $this->asAdmin();

        $types = $this->getJson('/api/settings/roles')->assertOk()->json('account_types');

        $this->assertArrayHasKey('admin', $types);
        $this->assertArrayHasKey('doctor', $types);
        $this->assertArrayHasKey('third_party_vendor', $types);
    }

    /* ── Departments ────────────────────────────────────────────────────── */

    public function test_an_admin_can_create_and_edit_a_department(): void
    {
        $this->asAdmin();

        $id = $this->postJson('/api/settings/departments', ['name' => 'Projects', 'code' => 'PRJ'])
            ->assertCreated()->json('data.id');

        $this->putJson("/api/settings/departments/{$id}", ['name' => 'Project Delivery'])
            ->assertOk()->assertJsonPath('data.name', 'Project Delivery');
    }

    public function test_a_duplicate_department_is_refused(): void
    {
        $this->asAdmin();
        $this->postJson('/api/settings/departments', ['name' => 'Projects'])->assertCreated();

        $this->postJson('/api/settings/departments', ['name' => 'Projects'])->assertStatus(422);
    }

    public function test_a_department_with_people_in_it_cannot_be_deleted(): void
    {
        $this->asAdmin();
        $id = $this->postJson('/api/settings/departments', ['name' => 'Projects'])->json('data.id');
        $this->user('staff', ['department' => 'Projects']);

        $this->deleteJson("/api/settings/departments/{$id}")->assertStatus(422);
        $this->assertDatabaseHas('access_departments', ['id' => $id]);
    }

    public function test_a_head_from_another_tenant_is_ignored(): void
    {
        (new Tenant)->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $outsider = User::create([
            'tenant_id' => 2, 'name' => 'Outsider', 'role' => 'admin',
            'email' => 'out@t2.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->asAdmin();
        $id = $this->postJson('/api/settings/departments', [
            'name' => 'Projects', 'head_user_id' => $outsider->id,
        ])->assertCreated()->json('data.id');

        $this->assertNull(Department::find($id)->head_user_id, 'a head must belong to this workspace');
    }

    /* ── Who may do any of this ─────────────────────────────────────────── */

    public function test_only_an_admin_may_maintain_the_catalogue(): void
    {
        Sanctum::actingAs($this->user('staff'));

        $this->getJson('/api/settings/roles')->assertForbidden();
        $this->postJson('/api/settings/roles', ['name' => 'Anything'])->assertForbidden();
        $this->postJson('/api/settings/departments', ['name' => 'Anything'])->assertForbidden();
    }

    public function test_another_tenants_role_is_not_reachable(): void
    {
        (new Tenant)->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $theirs = AccessRole::create([
            'tenant_id' => 2, 'name' => 'Theirs', 'slug' => 'theirs', 'is_active' => true, 'is_system' => false,
        ]);

        $this->asAdmin();
        $this->putJson("/api/settings/roles/{$theirs->id}", ['name' => 'Hijacked'])->assertStatus(404);
        $this->deleteJson("/api/settings/roles/{$theirs->id}")->assertStatus(404);
    }
}
