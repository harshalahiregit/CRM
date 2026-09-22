<?php

namespace Tests\Feature\Settings;

use App\Models\Access\Department;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Departments can be created from the UI, without breaking anything in use.
 *
 * ROLES WERE REMOVED FROM THIS FILE. This used to cover a second staff-role
 * catalogue (access_roles) alongside the live one in staff_roles. Both wrote
 * users.internal_role and only staff_roles carried permissions, so the second
 * could never be the authority. That path has been retired; the rules it
 * enforced — a slug is fixed once set, a system role cannot be deleted, nothing
 * in use can be removed — are enforced on staff_roles and covered by
 * StaffRoleArchitectureTest and the existing role/permission tests.
 *
 * The two slugs that existed only there, `hr` and `manager`, are now
 * vocabulary-only staff_roles entries so that routes/sangoetrack.php keeps
 * working unchanged. StaffRoleArchitectureTest covers that.
 *
 * What remains here is the departments half, which is the SAME duplication one
 * table over (access_departments versus hr_departments) and is deliberately
 * untouched for now — its own cleanup, with its own blast radius.
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

        $this->postJson('/api/settings/departments', ['name' => 'Anything'])->assertForbidden();
    }

    /**
     * The retired role endpoints must stay gone, not come back quietly as a
     * second place to create roles.
     *
     * GET answers 404 and the write verbs answer 405, because a catch-all GET
     * fallback matches any unrouted URI — so Laravel finds a route for the URI
     * but not for the method. Both mean "not routed"; what matters is that none
     * of them succeeds, so that is what is asserted rather than one code that
     * happens to be right for one verb.
     */
    public function test_the_retired_role_endpoints_are_no_longer_routed(): void
    {
        $this->asAdmin();

        $calls = [
            $this->getJson('/api/settings/roles'),
            $this->postJson('/api/settings/roles', ['name' => 'Anything']),
            $this->putJson('/api/settings/roles/1', ['name' => 'Anything']),
            $this->deleteJson('/api/settings/roles/1'),
        ];

        foreach ($calls as $response) {
            $this->assertContains($response->status(), [404, 405],
                'A retired role endpoint answered '.$response->status().' — it is routed again.');
        }
    }
}
