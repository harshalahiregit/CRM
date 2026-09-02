<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\StaffPermissionService;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Reading the permission grid.
 *
 * The grid has been stored in users.meta.permissions since Staff Management
 * shipped, and nothing has ever read it — no Gates, no Policies, 91 hardcoded
 * canManageHrQueue() calls that ignore it entirely. This covers the reader, so
 * modules can be moved onto it one at a time.
 */
class StaffPermissionTest extends TestCase
{
    use RefreshDatabase;

    private StaffPermissionService $svc;
    private ?Tenant $tenant = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(StaffPermissionService::class);
    }

    private function tenant(): Tenant
    {
        return $this->tenant ??= Tenant::create(['name' => 'T', 'slug' => 'perm-t', 'status' => 'active']);
    }

    private function staff(array $permissions = [], string $role = 'staff'): User
    {
        return User::create([
            'tenant_id' => $this->tenant()->id,
            'name' => 'S', 'email' => $role . '-' . uniqid() . '@example.test',
            'password' => Hash::make('Password123!'), 'role' => $role, 'status' => 'active',
            'meta' => ['permissions' => $permissions],
        ]);
    }

    public function test_a_granted_capability_is_allowed(): void
    {
        $u = $this->staff(['invoices' => ['create', 'edit']]);

        $this->assertTrue($this->svc->can($u, 'create', 'invoices'));
        $this->assertTrue($this->svc->can($u, 'edit', 'invoices'));
        $this->assertFalse($this->svc->can($u, 'delete', 'invoices'));
        $this->assertFalse($this->svc->can($u, 'create', 'customers'));
    }

    public function test_nothing_granted_means_nothing_allowed(): void
    {
        $u = $this->staff();

        foreach (StaffPermission::MODULES as $m) {
            foreach (StaffPermission::CAPABILITIES as $c) {
                $this->assertFalse($this->svc->can($u, $c, $m), "$c on $m should be denied");
            }
        }
    }

    /** An admin bypasses the grid, as tblstaff.admin does in the old CRM. */
    public function test_an_admin_bypasses_the_grid(): void
    {
        $admin = $this->staff([], 'admin');

        $this->assertTrue($this->svc->bypasses($admin));
        $this->assertTrue($this->svc->can($admin, 'delete', 'invoices'));
        $this->assertSame('global', $this->svc->scope($admin, 'reports'));
    }

    public function test_view_global_implies_view_own_but_not_the_reverse(): void
    {
        $global = $this->staff(['reports' => ['view_global']]);
        $own    = $this->staff(['reports' => ['view_own']]);

        $this->assertTrue($this->svc->can($global, 'view_own', 'reports'));
        $this->assertTrue($this->svc->can($global, 'view_global', 'reports'));

        $this->assertTrue($this->svc->can($own, 'view_own', 'reports'));
        $this->assertFalse($this->svc->can($own, 'view_global', 'reports'));
    }

    /** This is what a department-scoped report branches on. */
    public function test_scope_reports_how_wide_the_view_is(): void
    {
        $this->assertSame('global', $this->svc->scope($this->staff(['reports' => ['view_global']]), 'reports'));
        $this->assertSame('own', $this->svc->scope($this->staff(['reports' => ['view_own']]), 'reports'));
        $this->assertNull($this->svc->scope($this->staff(['reports' => ['create']]), 'reports'));
        $this->assertNull($this->svc->scope($this->staff(), 'reports'));
    }

    /** An unknown module must never answer yes, whatever is stored against it. */
    public function test_unknown_modules_and_capabilities_are_denied(): void
    {
        $u = $this->staff(['nonexistent_module' => ['view_global', 'delete']]);

        $this->assertFalse($this->svc->can($u, 'view_global', 'nonexistent_module'));
        $this->assertFalse($this->svc->can($u, 'invent_capability', 'invoices'));
        $this->assertNull($this->svc->scope($u, 'nonexistent_module'));
    }

    /** A stale key stored earlier must not survive into the grid we read. */
    public function test_stored_grants_are_sanitised_on_read(): void
    {
        $u = $this->staff([
            'invoices'          => ['create', 'not_a_capability'],
            'nonexistent_module' => ['view_global'],
            'customers'         => 'not-an-array',
        ]);

        $this->assertSame(['invoices' => ['create']], $this->svc->grantsFor($u));
    }

    public function test_the_two_new_modules_exist(): void
    {
        $this->assertTrue(StaffPermission::isModule('hr_attendance'));
        $this->assertTrue(StaffPermission::isModule('self'));

        $u = $this->staff(['self' => ['view_own', 'create']]);
        $this->assertTrue($this->svc->can($u, 'create', 'self'));
        $this->assertFalse($this->svc->can($u, 'create', 'hr_attendance'));
    }

    /**
     * The vocabulary must match the screen exactly. A module the grid can render
     * but the backend does not know is a box that silently grants nothing.
     */
    public function test_the_module_list_matches_the_staff_modal(): void
    {
        $jsx = file_get_contents(base_path('../frontend/src/components/admin/StaffModal.jsx'));
        preg_match_all("/key:'([a-z_]+)'/", $jsx, $m);

        $onScreen = array_values(array_unique($m[1]));
        $missing  = array_diff($onScreen, StaffPermission::MODULES);

        $this->assertSame([], array_values($missing),
            'These modules can be ticked on screen but the backend does not recognise them: ' . implode(', ', $missing));
    }
}
