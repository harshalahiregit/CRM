<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\AttendanceReportService;
use App\Support\Hr\DataScope;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The CRM attendance report, scoped (Phase 7, Part B).
 *
 * AttendanceReportService is SHARED with the mobile Attendance App, which is
 * why Phase 6 stopped rather than changing it. The change made here is the
 * smallest one that can work: a trailing `?User $actor = null` on monthly(),
 * byDepartment() and forEmployee().
 *
 * The first test in this file is the one that matters most. It calls monthly()
 * exactly as HrmAdminController does — two positional arguments, no actor — and
 * asserts the result is the whole tenant. If a later change ever makes the
 * scope apply by default, that test fails before anybody ships it to a phone.
 *
 * Scoping monthly() scopes the entire report, because it is the only place
 * employees are selected: the id list it produces feeds the attendance, the
 * reimbursements and the advances, and its rows feed the totals.
 *
 * Permission is NOT tested here beyond its presence: these routes sit behind
 * `permission:hr_attendance,view_global` route middleware rather than a
 * controller gate, and that middleware is untouched.
 */
class ScopedAttendanceReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $month;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Att', 'slug' => 'scoped-attendance', 'status' => 'active']);
        $this->month = now()->format('Y-m');
    }

    private function hrUser(string $email, string $scope, string $accountType = 'staff'): User
    {
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'R '.substr(md5($email), 0, 6),
            'slug' => 'r_'.substr(md5($email), 0, 6),
            // hr_attendance view_global: the route middleware's requirement.
            'permissions' => [
                'hr_employees' => [StaffPermission::VIEW_GLOBAL],
                'hr_attendance' => [StaffPermission::VIEW_GLOBAL],
            ],
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

    /** `$days` present days of `$hours` each, from the 1st of the month. */
    private function present(HrEmployee $e, int $days, float $hours): void
    {
        $now = now()->toDateTimeString();
        $start = now()->startOfMonth();

        for ($i = 0; $i < $days; $i++) {
            DB::table('hr_attendance')->insert([
                'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
                'date' => $start->copy()->addDays($i)->toDateString(),
                'status' => 'Present', 'working_hours' => $hours, 'overtime_hours' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /**
     * Ops actor, Ops colleague, Sales outsider.
     *
     * The outsider works an order of magnitude more hours, so a leaked total
     * cannot be mistaken for a rounding difference.
     */
    private function cast(string $scope): array
    {
        $user = $this->hrUser("att-{$scope}@att.test", $scope);
        $me   = $this->employee('A-1', $user, 'Ops');
        $mate = $this->employee('A-2', null, 'Ops');
        $out  = $this->employee('A-3', null, 'Sales');

        $this->present($me, 2, 8);      // 2 days, 16 hours
        $this->present($mate, 3, 8);    // 3 days, 24 hours
        $this->present($out, 10, 9);    // 10 days, 90 hours — distinctive

        return [$user, $me, $mate, $out];
    }

    private function monthly(array $query = []): array
    {
        $url = '/api/hr/reports/attendance?'.http_build_query($query + ['month' => $this->month]);

        return $this->getJson($url)->assertOk()->json('data');
    }

    /* ── the mobile contract ──────────────────────────────────────────── */

    public function test_a_mobile_style_call_with_no_actor_remains_unscoped(): void
    {
        $this->cast(DataScope::DEPARTMENT);

        // Exactly how HrmAdminController calls it: two positional arguments.
        // If the scope ever becomes the default, this fails here rather than
        // on somebody's phone.
        $summary = app(AttendanceReportService::class)->monthly($this->tenant->id, $this->month);

        $this->assertCount(3, $summary['rows']);
        $this->assertSame(3, $summary['totals']['employees']);
        $this->assertSame(15, $summary['totals']['present_days']);        // 2 + 3 + 10
        $this->assertEquals(130.0, $summary['totals']['working_hours']);  // 16 + 24 + 90
    }

    public function test_an_explicit_null_actor_is_the_same_as_omitting_it(): void
    {
        $this->cast(DataScope::DEPARTMENT);
        $service = app(AttendanceReportService::class);

        $omitted = $service->monthly($this->tenant->id, $this->month);
        $explicit = $service->monthly($this->tenant->id, $this->month, null, null, null);

        $this->assertSame($omitted, $explicit);
    }

    public function test_the_other_two_entry_points_are_also_unscoped_without_an_actor(): void
    {
        $this->cast(DataScope::DEPARTMENT);
        $service = app(AttendanceReportService::class);

        $byDept = $service->byDepartment($this->tenant->id, $this->month);
        $this->assertEqualsCanonicalizing(['Ops', 'Sales'], array_column($byDept['rows'], 'department'));
        $this->assertSame(3, $byDept['totals']['employees']);
    }

    public function test_the_mobile_call_sites_still_pass_their_existing_arguments(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/Hrm/HrmAdminController.php'));

        // Both call sites, unchanged and still two-argument. Asserted against
        // the source because the point is the SIGNATURE the app relies on, not
        // the behaviour of any one request.
        $this->assertStringContainsString("->monthly(\$tenantId, \$from->format('Y-m'))", $source);
        $this->assertStringContainsString("->monthly(\$tenantId, now()->format('Y-m'))", $source);

        // And the parameter is genuinely optional, so those calls compile.
        $param = (new \ReflectionMethod(AttendanceReportService::class, 'monthly'))->getParameters()[4];
        $this->assertSame('actor', $param->getName());
        $this->assertTrue($param->isOptional());
        $this->assertTrue($param->allowsNull());
    }

    /* ── the CRM report ───────────────────────────────────────────────── */

    public function test_a_global_crm_actor_receives_the_existing_full_report(): void
    {
        [$user] = $this->cast(DataScope::GLOBAL);
        Sanctum::actingAs($user);

        $d = $this->monthly();

        $this->assertCount(3, $d['rows']);
        $this->assertSame(15, $d['totals']['present_days']);
        $this->assertEquals(130.0, $d['totals']['working_hours']);
    }

    public function test_department_scope_limits_rows_and_aggregates(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $d = $this->monthly();

        $this->assertEqualsCanonicalizing(['EA-1', 'EA-2'], array_column($d['rows'], 'name'));
        $this->assertSame(2, $d['totals']['employees']);
        // 2 + 3 days and 16 + 24 hours. The outsider's 10 days and 90 hours
        // would more than double both.
        $this->assertSame(5, $d['totals']['present_days']);
        $this->assertEquals(40.0, $d['totals']['working_hours']);
        $this->assertEquals(5.0, $d['totals']['payable_days']);
    }

    public function test_own_scope_limits_rows_and_aggregates(): void
    {
        [$user] = $this->cast(DataScope::OWN);
        Sanctum::actingAs($user);

        $d = $this->monthly();

        $this->assertSame(['EA-1'], array_column($d['rows'], 'name'));
        $this->assertSame(1, $d['totals']['employees']);
        $this->assertSame(2, $d['totals']['present_days']);
        $this->assertEquals(16.0, $d['totals']['working_hours']);
    }

    public function test_team_scope_follows_the_existing_hierarchy(): void
    {
        $user = $this->hrUser('attteam@att.test', DataScope::TEAM);
        $lead = $this->employee('AT-1', $user, 'Ops');
        $mine = $this->employee('AT-2', null, 'Ops', $lead->id);
        $other = $this->employee('AT-3', null, 'Ops');   // same dept, different manager

        $this->present($lead, 1, 8);
        $this->present($mine, 1, 8);
        $this->present($other, 10, 9);

        Sanctum::actingAs($user);
        $d = $this->monthly();

        $names = array_column($d['rows'], 'name');
        $this->assertContains('EAT-1', $names);
        $this->assertContains('EAT-2', $names);
        $this->assertNotContains('EAT-3', $names);
        $this->assertEquals(16.0, $d['totals']['working_hours']);
    }

    public function test_a_restricted_actor_without_an_employee_record_gets_empty_results(): void
    {
        $this->cast(DataScope::DEPARTMENT);
        $stranger = $this->hrUser('attstranger@att.test', DataScope::DEPARTMENT);

        Sanctum::actingAs($stranger);
        $d = $this->monthly();

        // Nothing is the safe answer, never everything.
        $this->assertSame([], $d['rows']);
        $this->assertSame(0, $d['totals']['employees']);
        $this->assertSame(0, $d['totals']['present_days']);
        $this->assertEquals(0.0, $d['totals']['working_hours']);
    }

    /* ── filters cannot widen ─────────────────────────────────────────── */

    public function test_an_out_of_scope_employee_cannot_be_introduced_by_filter(): void
    {
        [$user, , , $out] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $d = $this->monthly(['employee_id' => $out->id]);

        $this->assertSame([], $d['rows']);
        $this->assertSame(0, $d['totals']['employees']);
        $this->assertEquals(0.0, $d['totals']['working_hours']);
    }

    public function test_a_department_filter_cannot_reach_another_department(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $d = $this->monthly(['department' => 'Sales']);

        $this->assertSame([], $d['rows']);
        $this->assertSame(0, $d['totals']['employees']);
    }

    public function test_the_direct_employee_route_answers_404_when_out_of_scope(): void
    {
        [$user, $me, , $out] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $this->getJson("/api/hr/reports/attendance/{$me->id}?month={$this->month}")->assertOk();

        // 404, not 403 — the same answer as an employee who does not exist.
        $this->getJson("/api/hr/reports/attendance/{$out->id}?month={$this->month}")->assertNotFound();
    }

    /* ── department rollup ────────────────────────────────────────────── */

    public function test_the_department_rollup_and_its_totals_are_scoped(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $d = $this->getJson("/api/hr/reports/attendance/departments?month={$this->month}")
            ->assertOk()->json('data');

        $this->assertSame(['Ops'], array_column($d['rows'], 'department'));
        $this->assertSame(2, $d['rows'][0]['headcount']);
        $this->assertSame(5, $d['rows'][0]['present_days']);
        // The grand totals come from the same scoped call as the rows.
        $this->assertSame(2, $d['totals']['employees']);
        $this->assertEquals(40.0, $d['totals']['working_hours']);
    }

    public function test_an_admin_is_never_restricted(): void
    {
        $this->cast(DataScope::DEPARTMENT);

        $admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'attadmin@att.test',
            'password' => Hash::make('Password123!'), 'role' => 'admin', 'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        $this->assertCount(3, $this->monthly()['rows']);
    }
}
