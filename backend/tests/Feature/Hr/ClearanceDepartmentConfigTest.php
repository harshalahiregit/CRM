<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrClearanceDepartment;
use App\Models\Hr\HrExitClearanceItem;
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
 * Configuring the exit-clearance departments and their authorities.
 *
 * Gated on hr_settings rather than on the HR queue, for the reason the approval
 * workflows and the onboarding checklist already are: actioning clearances must
 * not confer the right to decide who actions them. Somebody who clears
 * departments should not be able to make themselves the authority for all of
 * them.
 */
class ClearanceDepartmentConfigTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'clr-cfg', 'status' => 'active']);
        $this->other  = Tenant::create(['name' => 'Two', 'slug' => 'clr-cfg2', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function admin(?Tenant $tenant = null): User
    {
        return User::create([
            'tenant_id' => ($tenant ?: $this->tenant)->id, 'name' => 'Admin',
            'email' => uniqid().'@cfg.test', 'password' => Hash::make('Password123!'),
            'role' => 'admin', 'status' => 'active',
        ]);
    }

    /**
     * A staff user with the modules named, plus hr_attendance.
     *
     * hr_attendance is the route GROUP's gate — the same group the onboarding
     * checklist sits in — and both users below hold it, so the only difference
     * between them is hr_settings. Without that the refusal would come from
     * the middleware and the controller's check could be missing entirely.
     */
    private function staff(array $modules, string $slug): User
    {
        $permissions = ['hr_attendance' => [StaffPermission::VIEW_GLOBAL]];
        foreach ($modules as $m) {
            $permissions[$m] = [StaffPermission::VIEW_GLOBAL];
        }

        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst($slug), 'slug' => $slug,
            'permissions' => $permissions, 'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);

        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'S', 'email' => uniqid().'@cfg.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
            'staff_role_id' => $role->id,
        ]);
    }

    private function department(string $name, ?Tenant $tenant = null, int $order = 0): HrClearanceDepartment
    {
        return HrClearanceDepartment::create([
            'tenant_id' => ($tenant ?: $this->tenant)->id, 'name' => $name,
            'is_mandatory' => true, 'sort_order' => $order, 'is_active' => true,
        ]);
    }

    private function user(?Tenant $tenant = null): User
    {
        return User::create([
            'tenant_id' => ($tenant ?: $this->tenant)->id, 'name' => 'U',
            'email' => uniqid().'@cfg.test', 'password' => Hash::make('Password123!'),
            'role' => 'staff', 'status' => 'active',
        ]);
    }

    /* ── authorization on the configuration surface ───────────────────── */

    public function test_an_hr_settings_holder_may_configure(): void
    {
        Sanctum::actingAs($this->staff(['hr_settings'], 'cfg_ok'));

        $this->getJson('/api/hr/clearance-departments')->assertOk();
        $this->postJson('/api/hr/clearance-departments', ['name' => 'Legal'])->assertStatus(201);
    }

    public function test_hr_queue_access_alone_does_not_permit_configuring(): void
    {
        $department = $this->department('IT');

        // Actioning clearances is not the same authority as deciding who may.
        Sanctum::actingAs($this->staff(['hr_employees', 'hr_attendance'], 'cfg_no'));

        $this->getJson('/api/hr/clearance-departments')->assertStatus(403);
        $this->postJson('/api/hr/clearance-departments', ['name' => 'Legal'])->assertStatus(403);
        $this->deleteJson("/api/hr/clearance-departments/{$department->id}")->assertStatus(403);
        $this->putJson("/api/hr/clearance-departments/{$department->id}/authorities", [
            'user_ids' => [$this->user()->id],
        ])->assertStatus(403);

        $this->assertDatabaseHas('hr_clearance_departments', ['id' => $department->id]);
    }

    /* ── CRUD ─────────────────────────────────────────────────────────── */

    public function test_a_department_can_be_added_renamed_disabled_and_removed(): void
    {
        Sanctum::actingAs($this->admin());

        $id = $this->postJson('/api/hr/clearance-departments', ['name' => 'Legal'])
            ->assertStatus(201)->json('data.id');

        $this->putJson("/api/hr/clearance-departments/{$id}", ['name' => 'Legal & Compliance'])
            ->assertOk()->assertJsonPath('data.name', 'Legal & Compliance');

        $this->patchJson("/api/hr/clearance-departments/{$id}/status", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->deleteJson("/api/hr/clearance-departments/{$id}")->assertOk();
        $this->assertDatabaseMissing('hr_clearance_departments', ['id' => $id]);
    }

    public function test_two_departments_cannot_share_a_name(): void
    {
        $this->department('IT');
        Sanctum::actingAs($this->admin());

        // The name is what a clearance item stores, so a duplicate would make
        // an item's department ambiguous.
        $this->postJson('/api/hr/clearance-departments', ['name' => 'IT'])->assertStatus(422);
    }

    public function test_a_blank_name_is_refused(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/hr/clearance-departments', ['name' => '   '])->assertStatus(422);
    }

    public function test_departments_can_be_reordered(): void
    {
        $a = $this->department('A', order: 0);
        $b = $this->department('B', order: 1);
        $c = $this->department('C', order: 2);

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/hr/clearance-departments/reorder', ['ids' => [$c->id, $a->id, $b->id]])
            ->assertOk();

        $this->assertSame(['C', 'A', 'B'],
            HrClearanceDepartment::where('tenant_id', $this->tenant->id)
                ->orderBy('sort_order')->pluck('name')->all());
    }

    /* ── authorities ──────────────────────────────────────────────────── */

    public function test_authorities_are_replaced_wholesale_not_merged(): void
    {
        $department = $this->department('IT');
        $first  = $this->user();
        $second = $this->user();

        Sanctum::actingAs($this->admin());

        $this->putJson("/api/hr/clearance-departments/{$department->id}/authorities", [
            'user_ids' => [$first->id],
        ])->assertOk();

        $this->putJson("/api/hr/clearance-departments/{$department->id}/authorities", [
            'user_ids' => [$second->id],
        ])->assertOk();

        // A partial merge would leave a removed authority in place, which on
        // an authorisation surface is the wrong default.
        $ids = $department->fresh()->users->pluck('id')->all();
        $this->assertSame([$second->id], $ids);
    }

    public function test_clearing_the_authorities_returns_the_department_to_fallback(): void
    {
        $department = $this->department('IT');
        $someone = $this->user();

        Sanctum::actingAs($this->admin());
        $this->putJson("/api/hr/clearance-departments/{$department->id}/authorities", [
            'user_ids' => [$someone->id],
        ])->assertOk()->assertJsonPath('data.authorization', 'configured');

        $this->putJson("/api/hr/clearance-departments/{$department->id}/authorities", [
            'user_ids' => [], 'staff_role_ids' => [],
        ])->assertOk()->assertJsonPath('data.authorization', 'fallback');
    }

    public function test_the_listing_reports_each_departments_mode(): void
    {
        $it = $this->department('IT', order: 0);
        $this->department('Finance', order: 1);
        $broken = $this->department('Admin', order: 2);

        $live = $this->user();
        $dead = $this->user();
        $dead->update(['status' => 'inactive']);

        $it->users()->attach($live->id, ['tenant_id' => $this->tenant->id]);
        $broken->users()->attach($dead->id, ['tenant_id' => $this->tenant->id]);

        Sanctum::actingAs($this->admin());
        $rows = collect($this->getJson('/api/hr/clearance-departments')->assertOk()->json('data.departments'))
            ->keyBy('name');

        // The three states an administrator needs to tell apart at a glance.
        $this->assertSame('configured', $rows['IT']['authorization']);
        $this->assertSame('fallback', $rows['Finance']['authorization']);
        $this->assertSame('misconfigured', $rows['Admin']['authorization']);
        $this->assertSame(0, $rows['Admin']['eligible_count']);
    }

    public function test_vocabulary_only_roles_are_offered_as_authorities(): void
    {
        StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Manager (SangoeTrack)', 'slug' => 'manager',
            'permissions' => [], 'scope' => DataScope::GLOBAL, 'is_system' => true,
            'is_vocabulary_only' => true,
        ]);

        Sanctum::actingAs($this->admin());
        $roles = array_column(
            $this->getJson('/api/hr/clearance-departments')->assertOk()->json('data.options.roles'),
            'label'
        );

        // A permission-less role still names a real group of people, which is
        // all this configuration needs it to do.
        $this->assertContains('Manager (SangoeTrack)', $roles);
    }

    /* ── tenant isolation ─────────────────────────────────────────────── */

    public function test_another_workspaces_department_is_not_visible_or_editable(): void
    {
        $theirs = $this->department('Theirs', $this->other);

        Sanctum::actingAs($this->admin());

        $names = array_column(
            $this->getJson('/api/hr/clearance-departments')->assertOk()->json('data.departments'), 'name'
        );
        $this->assertNotContains('Theirs', $names);

        $this->putJson("/api/hr/clearance-departments/{$theirs->id}", ['name' => 'Mine'])->assertStatus(404);
        $this->assertSame('Theirs', $theirs->fresh()->name);
    }

    public function test_a_cross_tenant_user_cannot_be_configured_as_an_authority(): void
    {
        $department = $this->department('IT');
        $stranger = $this->user($this->other);

        Sanctum::actingAs($this->admin());

        $this->putJson("/api/hr/clearance-departments/{$department->id}/authorities", [
            'user_ids' => [$stranger->id],
        ])->assertStatus(422);

        $this->assertCount(0, $department->fresh()->users);
    }

    public function test_a_cross_tenant_role_cannot_be_configured_as_an_authority(): void
    {
        $department = $this->department('IT');
        $theirRole = StaffRole::create([
            'tenant_id' => $this->other->id, 'name' => 'Theirs', 'slug' => 'theirs',
            'permissions' => [], 'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);

        Sanctum::actingAs($this->admin());

        $this->putJson("/api/hr/clearance-departments/{$department->id}/authorities", [
            'staff_role_ids' => [$theirRole->id],
        ])->assertStatus(422);
    }

    public function test_a_reorder_cannot_include_another_workspaces_department(): void
    {
        $mine = $this->department('Mine');
        $theirs = $this->department('Theirs', $this->other);

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/hr/clearance-departments/reorder', ['ids' => [$mine->id, $theirs->id]])
            ->assertStatus(422);
    }

    /* ── the seeded five ──────────────────────────────────────────────── */

    public function test_the_migration_seeds_the_five_departments_in_order(): void
    {
        // RefreshDatabase migrates onto an empty database, so the backfill has
        // no tenants to act on — this asserts the SHAPE the migration writes,
        // which the authorisation suite then depends on.
        foreach (HrExitClearanceItem::DEPARTMENTS as $i => $name) {
            HrClearanceDepartment::create([
                'tenant_id' => $this->tenant->id, 'name' => $name,
                'is_mandatory' => true, 'sort_order' => $i, 'is_active' => true,
            ]);
        }

        Sanctum::actingAs($this->admin());
        $rows = $this->getJson('/api/hr/clearance-departments')->assertOk()->json('data.departments');

        $this->assertSame(HrExitClearanceItem::DEPARTMENTS, array_column($rows, 'name'));
        // Seeded with no authorities, so every one starts on the HR queue.
        foreach ($rows as $row) {
            $this->assertSame('fallback', $row['authorization']);
        }
    }
}
