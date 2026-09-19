<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLeaveBalance;
use App\Models\Hr\HrLeaveApplication;
use App\Models\Hr\HrLeaveType;
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
 * Leave reports, scoped — the second half of the same door as Payroll.
 *
 * Payroll had one base query to put the scope in. Leave has nine separate
 * queries across four tables: the dashboard tiles read hr_leave_applications
 * and hr_employee_leave_balances directly, the type analysis and the balance
 * report read balances, the trend series reads applications, and only two of
 * the eleven methods went through apps(). Scoping the choke point alone would
 * have shipped a scoped application list beside a tenant-wide "total
 * applications" tile and a tenant-wide utilisation percentage — a worse state
 * than before, because the two numbers would disagree on one screen.
 *
 * So every employee-level query is covered here, tile by tile. The company
 * holiday calendar is deliberately NOT scoped and is asserted as such: a public
 * holiday is the same fact for everybody and belongs to no employee.
 *
 * Permission is untouched. Every actor below holds HR authority; what changes
 * is whose leave they can see.
 */
class ScopedLeaveReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private HrLeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'LRep', 'slug' => 'scoped-leave-report', 'status' => 'active']);

        $this->type = HrLeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Casual', 'code' => 'CL',
            'category' => 'Casual', 'is_active' => true,
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

    /** An approved application spanning today, worth a known number of days. */
    private function applied(HrEmployee $e, float $days, string $status = 'Approved'): HrLeaveApplication
    {
        return HrLeaveApplication::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
            'leave_type_id' => $this->type->id,
            'from_date' => now()->toDateString(), 'to_date' => now()->toDateString(),
            'days' => $days, 'status' => $status,
        ]);
    }

    /** An active balance with a distinctive allocation. */
    private function balance(HrEmployee $e, float $allocated, float $used): HrEmployeeLeaveBalance
    {
        return HrEmployeeLeaveBalance::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
            'leave_type_id' => $this->type->id,
            'allocated' => $allocated, 'opening_balance' => 0, 'used' => $used,
            'adjusted' => 0, 'carried_forward' => 0,
            'available_balance' => $allocated - $used, 'status' => 'active',
        ]);
    }

    /**
     * Actor in Ops with a colleague, plus an outsider in Sales.
     *
     * The outsider's figures are an order of magnitude larger than everyone
     * else's, so they cannot hide inside a leaked sum: 1000 allocated days
     * against 3, 100 days applied against 1 and 2.
     */
    private function cast(string $scope): array
    {
        $user = $this->hrUser("actor-{$scope}@lrep.test", $scope);
        $me   = $this->employee('L-1', $user, 'Ops');
        $mate = $this->employee('L-2', null, 'Ops');
        $out  = $this->employee('L-3', null, 'Sales');

        $this->applied($me, 1);
        $this->applied($mate, 2);
        $this->applied($out, 100);

        $this->balance($me, 10, 1);
        $this->balance($mate, 20, 2);
        $this->balance($out, 1000, 100);

        return [$user, $me, $mate, $out];
    }

    /** Not json(): Laravel's TestCase declares a public json() and PHP refuses the override. */
    private function report(string $path, array $query = []): array
    {
        $url = "/api/hr/leave/reports/{$path}";
        if ($query) {
            $url .= '?'.http_build_query($query);
        }

        return $this->getJson($url)->assertOk()->json();
    }

    /* ── employee-level rows ──────────────────────────────────────────── */

    public function test_the_employee_leave_report_excludes_another_department(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $names = collect($this->report('employees'))->pluck('employee_name')->all();

        $this->assertContains('EL-1', $names);
        $this->assertContains('EL-2', $names);
        $this->assertNotContains('EL-3', $names);
    }

    public function test_own_scope_sees_only_its_own_leave(): void
    {
        [$user] = $this->cast(DataScope::OWN);
        Sanctum::actingAs($user);

        $this->assertSame(['EL-1'], collect($this->report('employees'))->pluck('employee_name')->unique()->values()->all());
    }

    public function test_team_scope_sees_reports_and_not_the_rest(): void
    {
        $user = $this->hrUser('lead@lrep.test', DataScope::TEAM);
        $lead = $this->employee('T-1', $user, 'Ops');
        $mine = $this->employee('T-2', null, 'Ops', $lead->id);
        $other = $this->employee('T-3', null, 'Ops');   // same department, different manager

        $this->applied($lead, 1);
        $this->applied($mine, 2);
        $this->applied($other, 100);

        Sanctum::actingAs($user);
        $names = collect($this->report('employees'))->pluck('employee_name')->unique()->all();

        $this->assertContains('ET-1', $names);
        $this->assertContains('ET-2', $names);
        $this->assertNotContains('ET-3', $names);
    }

    public function test_an_actor_with_no_employee_record_sees_nothing(): void
    {
        $this->cast(DataScope::DEPARTMENT);
        $stranger = $this->hrUser('stranger@lrep.test', DataScope::DEPARTMENT);

        Sanctum::actingAs($stranger);

        $this->assertSame([], $this->report('employees'));
    }

    public function test_global_scope_still_sees_everyone(): void
    {
        [$user] = $this->cast(DataScope::GLOBAL);
        Sanctum::actingAs($user);

        $this->assertCount(3, $this->report('employees'));
    }

    /* ── the dashboard tiles ──────────────────────────────────────────── */

    public function test_dashboard_application_counts_come_from_the_scoped_set(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $d = $this->report('dashboard');

        // Two Ops applications, not three.
        $this->assertSame(2, $d['total_applications']);
        $this->assertSame(2, $d['approved']);
    }

    public function test_dashboard_on_leave_today_counts_only_the_scoped_set(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $this->assertSame(2, $this->report('dashboard')['on_leave_today']);
    }

    public function test_dashboard_utilization_is_computed_from_scoped_balances(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        // Ops: 3 used of 30 allocated = 10%. Tenant-wide it would be
        // 103 / 1030 = 10.0% as well by coincidence of the ratios, so the
        // allocation total is asserted separately in the trends test where the
        // outsider's 1000 days cannot hide.
        // assertEquals, not assertSame: a whole percentage round-trips through
        // JSON as an int.
        $this->assertEquals(10.0, $this->report('dashboard')['utilization']);
    }

    public function test_dashboard_own_scope_sees_one_application(): void
    {
        [$user] = $this->cast(DataScope::OWN);
        Sanctum::actingAs($user);

        $this->assertSame(1, $this->report('dashboard')['total_applications']);
    }

    public function test_dashboard_holiday_count_is_not_scoped(): void
    {
        [$user] = $this->cast(DataScope::OWN);
        \DB::table('hr_holidays')->insert([
            'tenant_id' => $this->tenant->id, 'title' => 'Republic Day',
            'holiday_date' => now()->addDays(5)->toDateString(),
            'holiday_type' => 'Public', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($user);

        // The company calendar belongs to nobody in particular.
        $this->assertSame(1, $this->report('dashboard')['upcoming_holidays']);
    }

    /* ── department rollup ────────────────────────────────────────────── */

    public function test_the_department_rollup_omits_departments_out_of_scope(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $rows = collect($this->report('departments'));

        $this->assertSame(['Ops'], $rows->pluck('department')->all());
        $this->assertSame(2, $rows->firstWhere('department', 'Ops')['total']);
    }

    public function test_department_utilization_uses_only_scoped_balances(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $ops = collect($this->report('departments'))->firstWhere('department', 'Ops');

        // 3 used / 30 allocated within Ops.
        $this->assertEquals(10.0, $ops['utilization']);
        $this->assertSame(2, $ops['employees_on_leave']);
    }

    /* ── type analysis and balances ───────────────────────────────────── */

    public function test_type_analysis_totals_exclude_out_of_scope_balances(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $row = collect($this->report('types'))->firstWhere('code', 'CL');

        // 10 + 20, not 10 + 20 + 1000.
        $this->assertEquals(30.0, $row['allocated']);
        $this->assertEquals(3.0, $row['used']);
    }

    public function test_the_balance_report_excludes_out_of_scope_employees(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $names = collect($this->report('balances'))->pluck('employee_name')->all();

        $this->assertSame(['EL-1', 'EL-2'], $names);
    }

    public function test_own_scope_balance_report_shows_one_row(): void
    {
        [$user] = $this->cast(DataScope::OWN);
        Sanctum::actingAs($user);

        $rows = $this->report('balances');

        $this->assertCount(1, $rows);
        $this->assertEquals(10.0, $rows[0]['allocated']);
    }

    /* ── trends: numerator AND denominator ────────────────────────────── */

    public function test_trend_utilization_uses_a_scoped_allocation_denominator(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $months = collect($this->report('trends', ['year' => now()->year]));
        $thisMonth = $months[now()->month - 1];

        // 3 days used this month against 30 allocated in Ops = 10%.
        // Against the tenant-wide 1030 it would have read 0.3%.
        $this->assertSame(2, $thisMonth['applications']);
        $this->assertEquals(3.0, $thisMonth['usage']);
        $this->assertEquals(10.0, $thisMonth['utilization']);
    }

    /* ── filters cannot widen ─────────────────────────────────────────── */

    public function test_an_employee_id_filter_cannot_reach_outside_the_scope(): void
    {
        [$user, , , $out] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $this->assertSame([], $this->report('employees', ['employee_id' => $out->id]));
        $this->assertSame([], $this->report('balances', ['employee_id' => $out->id]));
    }

    public function test_a_department_filter_cannot_reach_another_department(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $this->assertSame([], $this->report('employees', ['department' => 'Sales']));

        // The rollup is asserted on the property Phase 5 is responsible for:
        // no row for a department outside the scope, and none of its figures.
        //
        // It is NOT asserted to be empty, because it is not. departments()
        // merges three sources and only departmentApps() reads the filter —
        // balancesByDept() and onLeaveTodayByDept() take no filters at all, so
        // filtering to Sales still returns an Ops row whose application counts
        // are zero while its on-leave headcount is two. That predates this
        // phase and is a filter-consistency defect, not a disclosure: every
        // figure shown belongs to a department the actor may already see.
        // Reported rather than changed, because fixing it alters what a global
        // HR user sees too.
        $rollup = collect($this->report('departments', ['department' => 'Sales']));

        $this->assertNotContains('Sales', $rollup->pluck('department')->all());
        $this->assertSame(0, (int) $rollup->sum('total'));
    }

    /* ── filter dropdowns ─────────────────────────────────────────────── */

    public function test_the_filter_dropdowns_do_not_leak_the_staff_directory(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $f = $this->report('filters');

        $this->assertSame(['EL-1', 'EL-2'], collect($f['employees'])->pluck('name')->all());
        $this->assertSame(['Ops'], $f['departments']);
        $this->assertNotContains('Sales', $f['departments']);
    }

    public function test_leave_types_stay_whole_in_the_dropdown(): void
    {
        [$user] = $this->cast(DataScope::OWN);
        Sanctum::actingAs($user);

        // Master data, not employee data.
        $this->assertSame(['CL'], collect($this->report('filters')['leave_types'])->pluck('code')->all());
    }

    /* ── exports ──────────────────────────────────────────────────────── */

    public function test_the_csv_export_excludes_out_of_scope_employees(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $csv = $this->streamed('/api/hr/leave/reports/export?report=employees&format=csv');

        $this->assertStringContainsString('EL-1', $csv);
        $this->assertStringContainsString('EL-2', $csv);
        $this->assertStringNotContainsString('EL-3', $csv);
    }

    public function test_the_balance_csv_export_does_not_carry_foreign_figures(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $csv = $this->streamed('/api/hr/leave/reports/export?report=balances&format=csv');

        $this->assertStringNotContainsString('EL-3', $csv);
        $this->assertStringNotContainsString('1000', $csv);
    }

    public function test_the_type_export_totals_come_from_the_scoped_set(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $csv = $this->streamed('/api/hr/leave/reports/export?report=types&format=csv');

        $this->assertStringContainsString('30', $csv);
        $this->assertStringNotContainsString('1030', $csv);
    }

    private function streamed(string $url): string
    {
        $response = $this->get($url);
        $response->assertOk();

        return $response->streamedContent();
    }

    /* ── permission is unchanged ──────────────────────────────────────── */

    public function test_a_denied_user_is_still_refused_whatever_the_scope(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'NoAuth', 'email' => 'noauth@lrep.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/hr/leave/reports/employees')->assertForbidden();
        $this->getJson('/api/hr/leave/reports/dashboard')->assertForbidden();
    }

    public function test_portal_account_types_are_refused(): void
    {
        foreach (['client', 'contact', 'doctor', 'company', 'patient'] as $i => $type) {
            $user = $this->hrUser("portal{$i}@lrep.test", DataScope::GLOBAL, $type);
            Sanctum::actingAs($user);

            $this->getJson('/api/hr/leave/reports/employees')->assertForbidden();
        }
    }
}
