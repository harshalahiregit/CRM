<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /hr/master-data — the one payload behind every HR dropdown.
 *
 * It had no test, which is how a reported bug turned out to be a label. An
 * administrator looking for the designation "Manager" found the Employees
 * FILTER, which is derived from the values employees actually hold, and
 * concluded the catalogue could not be extended. The master had it all along
 * and this endpoint was returning it.
 *
 * So these tests pin the contract the dropdowns depend on: that the lists are
 * the tenant's masters rather than a projection of existing rows, that a
 * designation created a moment ago is in the next response, that managers are
 * EMPLOYEES and not designations, and that none of it crosses a tenant.
 *
 * The self/circular reporting rules are not re-tested here — they live in
 * ReportingManagerAtHireTest and are enforced in EmployeeService. What this
 * file adds is the half nobody was checking: whether the pickers are offered
 * the right things in the first place.
 */
class MasterDataDropdownsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'MD', 'slug' => 'master-data-dropdowns', 'status' => 'active']);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'md-admin@md.test',
            'password' => Hash::make('Password123!'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function department(string $name, ?int $tenantId = null): int
    {
        return DB::table('hr_departments')->insertGetId([
            'tenant_id' => $tenantId ?? $this->tenant->id, 'name' => $name, 'is_active' => true,
            'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        ]);
    }

    private function designation(string $name, ?int $tenantId = null): int
    {
        return DB::table('hr_designations')->insertGetId([
            'tenant_id' => $tenantId ?? $this->tenant->id, 'name' => $name, 'is_active' => true,
            'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        ]);
    }

    private function employee(string $code, string $name, ?int $tenantId = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $tenantId ?? $this->tenant->id, 'employee_code' => $code, 'name' => $name,
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2024-01-01',
            'status' => 'Active',
        ]);
    }

    private function masters(): array
    {
        return $this->getJson('/api/hr/master-data')->assertOk()->json();
    }

    /* ── departments ──────────────────────────────────────────────────── */

    public function test_the_department_list_is_the_tenant_master(): void
    {
        $this->department('Engineering');
        $this->department('Operations');
        Sanctum::actingAs($this->user);

        $names = array_column($this->masters()['departments'], 'name');

        $this->assertContains('Engineering', $names);
        $this->assertContains('Operations', $names);
    }

    /**
     * The master is NOT a projection of the employees table.
     *
     * A department with nobody in it must still be offered, or a new one could
     * never be used — the first employee to join it could not be created.
     */
    public function test_a_department_with_no_employees_is_still_offered(): void
    {
        $this->department('Newly Formed');
        Sanctum::actingAs($this->user);

        $this->assertContains('Newly Formed', array_column($this->masters()['departments'], 'name'));
    }

    /* ── designations ─────────────────────────────────────────────────── */

    public function test_the_designation_list_is_the_tenant_master(): void
    {
        $this->designation('Analyst');
        $this->designation('Technician');
        Sanctum::actingAs($this->user);

        $names = array_column($this->masters()['designations'], 'name');

        $this->assertContains('Analyst', $names);
        $this->assertContains('Technician', $names);
    }

    /**
     * The reported case, end to end: create "Manager", see it offered.
     *
     * This is what the administrator believed was impossible.
     */
    public function test_a_newly_created_designation_appears_in_the_next_response(): void
    {
        $this->designation('Analyst');
        Sanctum::actingAs($this->user);

        $this->assertNotContains('Manager', array_column($this->masters()['designations'], 'name'));

        $this->postJson('/api/hr/designations', ['name' => 'Manager'])->assertSuccessful();

        $this->assertContains('Manager', array_column($this->masters()['designations'], 'name'),
            'A designation created through Organization Setup must be offered by the employee form.');
    }

    public function test_a_designation_nobody_holds_is_still_offered(): void
    {
        $this->designation('Chief Of Staff');
        Sanctum::actingAs($this->user);

        $this->assertContains('Chief Of Staff', array_column($this->masters()['designations'], 'name'));
    }

    /* ── managers are people ──────────────────────────────────────────── */

    /**
     * The Reporting Manager picker is fed EMPLOYEES, never designations.
     *
     * The two lists sit next to each other on the form and one of them holds
     * job titles; picking a title as somebody's manager would be a reporting
     * line to nobody.
     */
    public function test_managers_are_employee_records_not_designations(): void
    {
        $this->designation('Manager');                  // a title, not a person
        $alice = $this->employee('M-1', 'Alice Rao');
        Sanctum::actingAs($this->user);

        $managers = $this->masters()['managers'];
        $names    = array_column($managers, 'name');

        $this->assertContains('Alice Rao', $names);
        $this->assertNotContains('Manager', $names,
            'A designation must never appear in the reporting-manager list.');

        // Picked by id, because a name is not an identity.
        $this->assertSame($alice->id, collect($managers)->firstWhere('name', 'Alice Rao')['id']);
    }

    public function test_a_selected_reporting_manager_persists_and_resolves_to_an_employee(): void
    {
        $manager = $this->employee('M-9', 'Manager Person');
        $report  = $this->employee('R-9', 'Report Person');
        Sanctum::actingAs($this->user);

        $this->putJson("/api/hr/employees/{$report->id}", ['reporting_manager_id' => $manager->id])
            ->assertSuccessful();

        $this->assertSame($manager->id, (int) $report->fresh()->reporting_manager_id);
        $this->assertDatabaseHas('hr_employees', ['id' => $manager->id, 'name' => 'Manager Person']);
    }

    /* ── tenant isolation ─────────────────────────────────────────────── */

    public function test_masters_never_cross_a_tenant(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'md-other', 'status' => 'active']);

        $this->department('Mine');
        $this->designation('Mine Designation');
        $this->employee('ME-1', 'My Person');

        $this->department('Theirs', $other->id);
        $this->designation('Their Designation', $other->id);
        $this->employee('TH-1', 'Their Person', $other->id);

        Sanctum::actingAs($this->user);
        $m = $this->masters();

        $this->assertContains('Mine', array_column($m['departments'], 'name'));
        $this->assertNotContains('Theirs', array_column($m['departments'], 'name'));

        $this->assertContains('Mine Designation', array_column($m['designations'], 'name'));
        $this->assertNotContains('Their Designation', array_column($m['designations'], 'name'));

        $this->assertContains('My Person', array_column($m['managers'], 'name'));
        $this->assertNotContains('Their Person', array_column($m['managers'], 'name'));
    }

    /* ── the payload shape the dropdowns rely on ──────────────────────── */

    /**
     * Every key the shared cache declares must be present, because a missing
     * one renders as an empty <select> rather than an error — the failure mode
     * that made this look like a backend bug.
     */
    public function test_the_payload_carries_every_key_the_frontend_cache_expects(): void
    {
        Sanctum::actingAs($this->user);
        $m = $this->masters();

        foreach ([
            'departments', 'designations', 'grades', 'roles', 'shifts',
            'business_units', 'employee_levels', 'managers', 'locations', 'projects',
        ] as $key) {
            $this->assertArrayHasKey($key, $m, "useMasterData() declares {$key}; the payload must carry it.");
            $this->assertIsArray($m[$key]);
        }
    }

    /**
     * Business units and locations are DERIVED, not masters.
     *
     * There is no table behind either — they are distinct values read back off
     * manpower requests and employee rows. They are asserted here so that if a
     * real master is ever added, this test fails and the HR Configuration page
     * gets updated to stop calling them unconfigurable.
     */
    public function test_business_units_and_locations_are_derived_and_start_empty(): void
    {
        $this->employee('D-1', 'Derived Person');
        Sanctum::actingAs($this->user);
        $m = $this->masters();

        $this->assertSame([], $m['business_units']);
        $this->assertSame([], $m['locations']);
    }
}
