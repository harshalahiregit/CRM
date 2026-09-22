<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrPayrollRun;
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
 * A report is the widest door in the module, and it was still open.
 *
 * Phases 1 and 4 narrowed the Employees and Leave lists and their direct-id
 * surface. Payroll reports were untouched, so a department-scoped actor who
 * could no longer open one employee's record could still pull the whole
 * company's salaries out of /payroll/reports/employees, or export them to CSV.
 *
 * Scope goes into PayrollReportRepository::base(), which every figure is built
 * from — the KPI tiles, the per-employee rows, the department rollup, the CSV
 * and the PDF. That matters more than covering each endpoint separately: a
 * total computed from an unscoped set beside a scoped list is two numbers on
 * one screen that cannot both be right.
 *
 * Permission is untouched. Every actor here holds HR authority; what changes is
 * whose salaries appear.
 */
class ScopedPayrollReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private HrPayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Rep', 'slug' => 'scoped-payroll-report', 'status' => 'active']);

        $this->run = HrPayrollRun::create([
            'tenant_id' => $this->tenant->id, 'payroll_month' => 6, 'payroll_year' => 2026,
            'status' => 'Completed',
        ]);
    }

    private function hrUser(string $email, string $scope, string $accountType = 'staff'): User
    {
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'R '.substr(md5($email), 0, 6),
            'slug' => 'r_'.substr(md5($email), 0, 6),
            'permissions' => ['hr_employees' => [StaffPermission::VIEW_GLOBAL]],
            'scope' => $scope, 'is_system' => false,
        ]);

        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $accountType,
            'status' => 'active', 'staff_role_id' => $role->id,
        ]);
    }

    private function employee(string $code, ?User $user = null, string $dept = 'Ops', ?int $managerId = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'employee_code' => $code, 'name' => 'E'.$code,
            'department' => $dept, 'designation' => 'Analyst', 'joining_date' => '2024-01-01',
            'status' => 'Active', 'user_id' => $user?->id, 'reporting_manager_id' => $managerId,
        ]);
    }

    /** One payroll record worth a known, distinctive amount. */
    private function paid(HrEmployee $e, float $gross): HrPayrollRecord
    {
        return HrPayrollRecord::create([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $this->run->id,
            'employee_id' => $e->id, 'gross_salary' => $gross, 'total_benefits' => 0,
            'total_deductions' => 0, 'net_salary' => $gross, 'statutory_deductions' => 0,
            'status' => 'Processed',
        ]);
    }

    /** Actor in Ops with a colleague, plus an outsider in Sales. */
    private function cast(string $scope): array
    {
        $user = $this->hrUser("actor-{$scope}@rep.test", $scope);
        $me   = $this->employee('R-1', $user, 'Ops');
        $mate = $this->employee('R-2', null, 'Ops');
        $out  = $this->employee('R-3', null, 'Sales');

        $this->paid($me, 100);
        $this->paid($mate, 200);
        $this->paid($out, 1000);   // distinctive: it shows up in any leaked total

        return [$user, $me, $mate, $out];
    }

    /** Not json(): Laravel's TestCase declares a public json() and PHP refuses the override. */
    private function report(string $path): array
    {
        return $this->getJson("/api/hr/payroll/reports/{$path}")->assertOk()->json();
    }

    /* ── employee-level rows ──────────────────────────────────────────── */

    public function test_the_employee_report_excludes_another_department(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $names = collect($this->report('employees'))->pluck('employee_name')->all();

        $this->assertContains('ER-1', $names);
        $this->assertContains('ER-2', $names);
        $this->assertNotContains('ER-3', $names, 'Another department\'s salary must not appear.');
    }

    public function test_own_scope_shows_only_the_actors_own_row(): void
    {
        [$user] = $this->cast(DataScope::OWN);
        Sanctum::actingAs($user);

        $this->assertSame(['ER-1'], collect($this->report('employees'))->pluck('employee_name')->all());
    }

    public function test_team_scope_follows_the_reporting_line(): void
    {
        $user = $this->hrUser('team@rep.test', DataScope::TEAM);
        $me   = $this->employee('T-1', $user);
        $sub  = $this->employee('T-2', null, 'Ops', $me->id);
        $away = $this->employee('T-3');

        $this->paid($me, 100);
        $this->paid($sub, 200);
        $this->paid($away, 1000);

        Sanctum::actingAs($user);
        $names = collect($this->report('employees'))->pluck('employee_name')->all();

        $this->assertContains('ET-2', $names);
        $this->assertNotContains('ET-3', $names);
    }

    /* ── aggregates come from the scoped set ──────────────────────────── */

    /**
     * The point of scoping base() rather than each endpoint. A total that still
     * counts the whole company, shown above a list that does not, is worse than
     * either on its own.
     */
    public function test_summary_totals_are_computed_from_the_scoped_set_only(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $summary = $this->report('summary');

        $this->assertSame(2, $summary['employees_paid'], 'Only the two in Ops were paid, as far as this actor sees.');
        $this->assertEquals(300.0, $summary['total_earnings'], '100 + 200, without the outsider\'s 1000.');
    }

    public function test_the_department_rollup_excludes_other_departments(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $departments = collect($this->report('departments'))->pluck('department')->all();

        $this->assertContains('Ops', $departments);
        $this->assertNotContains('Sales', $departments);
    }

    /* ── filters cannot widen ─────────────────────────────────────────── */

    /**
     * The obvious attack: ask for the person you are not allowed to see.
     *
     * Scope is applied in the same builder as the filters, so the two AND
     * together — a filter narrows within the scope and can never reach outside
     * it.
     */
    public function test_an_employee_filter_cannot_reach_outside_the_scope(): void
    {
        [$user, , , $out] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $rows = $this->getJson("/api/hr/payroll/reports/employees?employee_id={$out->id}")->assertOk()->json();
        $this->assertSame([], $rows, 'Filtering TO an out-of-scope employee must return nothing, not that employee.');

        $summary = $this->getJson("/api/hr/payroll/reports/summary?employee_id={$out->id}")->assertOk()->json();
        $this->assertSame(0, $summary['employees_paid']);
        $this->assertEquals(0.0, $summary['total_earnings'], 'And no total may be obtained for them.');
    }

    public function test_a_department_filter_cannot_reach_outside_the_scope(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $rows = $this->getJson('/api/hr/payroll/reports/employees?department=Sales')->assertOk()->json();

        $this->assertSame([], $rows);
    }

    /* ── exports ──────────────────────────────────────────────────────── */

    public function test_the_csv_export_contains_no_out_of_scope_employee(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $csv = $this->get('/api/hr/payroll/reports/export?report=employees&format=csv')
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('ER-1', $csv);
        $this->assertStringNotContainsString('ER-3', $csv, 'The export is the same data with a filename.');
        $this->assertStringNotContainsString('1000', $csv, 'Nor may their figures appear.');
    }

    public function test_the_department_export_aggregates_only_scoped_rows(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $csv = $this->get('/api/hr/payroll/reports/export?report=departments&format=csv')
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('Ops', $csv);
        $this->assertStringNotContainsString('Sales', $csv);
    }

    /* ── the dropdowns are data too ───────────────────────────────────── */

    public function test_the_filter_dropdowns_do_not_leak_the_staff_directory(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $options = $this->getJson('/api/hr/payroll/reports/filters')->assertOk()->json();

        $names = collect($options['employees'])->pluck('name')->all();
        $this->assertContains('ER-1', $names);
        $this->assertNotContains('ER-3', $names, 'A <select> is a disclosure with extra steps.');
        $this->assertNotContains('Sales', $options['departments']);
    }

    /* ── the failure direction ────────────────────────────────────────── */

    public function test_a_scoped_actor_with_no_employee_record_gets_an_empty_report(): void
    {
        $user = $this->hrUser('noemp@rep.test', DataScope::DEPARTMENT);
        $this->paid($this->employee('N-1'), 500);

        Sanctum::actingAs($user);

        $this->assertSame([], $this->report('employees'));
        $this->assertSame(0, $this->report('summary')['employees_paid']);
        $this->assertEquals(0.0, $this->report('summary')['total_earnings'],
            'Empty, never everything.');
    }

    /* ── what must not change ─────────────────────────────────────────── */

    public function test_a_global_actor_sees_the_whole_tenant_as_before(): void
    {
        $user = $this->hrUser('global@rep.test', DataScope::GLOBAL);
        $this->employee('G-0', $user);
        $this->paid($this->employee('G-1', null, 'Sales'), 1000);

        Sanctum::actingAs($user);

        $this->assertSame(1, $this->report('summary')['employees_paid']);
        $this->assertEquals(1000.0, $this->report('summary')['total_earnings']);
    }

    public function test_an_admin_is_never_restricted(): void
    {
        $admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'A', 'email' => 'admin@rep.test',
            'password' => Hash::make('Password123!'), 'role' => 'admin', 'status' => 'active',
        ]);
        $this->paid($this->employee('AD-1', null, 'Sales'), 777);

        Sanctum::actingAs($admin);

        $this->assertEquals(777.0, $this->report('summary')['total_earnings']);
    }

    /** Scope is a data boundary, never a grant — permission still answers first. */
    public function test_permission_denial_is_still_403_even_with_a_global_scope(): void
    {
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Wide No Perm', 'slug' => 'wide_no_perm_rep',
            'permissions' => [], 'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'email' => 'wide@rep.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
            'staff_role_id' => $role->id,
        ]);

        $this->assertFalse($user->canManageHrQueue());

        Sanctum::actingAs($user);

        $this->getJson('/api/hr/payroll/reports/summary')->assertForbidden();
        $this->get('/api/hr/payroll/reports/export?report=employees&format=csv')->assertForbidden();
    }

    /** @dataProvider portalRoles */
    public function test_a_portal_account_cannot_reach_the_reports(string $accountType): void
    {
        Sanctum::actingAs($this->hrUser("portal-{$accountType}@rep.test", DataScope::GLOBAL, $accountType));

        $this->getJson('/api/hr/payroll/reports/summary')->assertForbidden();
    }

    public static function portalRoles(): array
    {
        return [
            'client' => ['client'], 'vendor' => ['vendor'],
            'third party vendor' => ['third_party_vendor'],
            'company' => ['company'], 'doctor' => ['doctor'],
        ];
    }
}
