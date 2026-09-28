<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Hr\DataScope;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The operational half of HR data scope.
 *
 * The report repositories were scoped in earlier phases; the lists those reports
 * summarise were not. So a department-scoped actor could not open one colleague's
 * record, and could still read every employee's payslips, salary history, exit
 * settlement, probation review and training result from the operational screens —
 * which made the report boundary decorative.
 *
 * These are repository-level tests on purpose. The controllers and services are
 * covered by their own suites; what needs holding here is that the restriction is
 * IN THE QUERY. A repository that fetched everything and filtered afterwards would
 * satisfy an endpoint test and still be wrong the moment anything else called it.
 *
 * The fixture is the same one the scope phases have used throughout: an actor in
 * Ops, a colleague in Ops, and an outsider in Sales whose rows are distinctive
 * enough to be recognised in any leak.
 */
class ScopedOperationalRepositoriesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private HrEmployee $me;

    private HrEmployee $mate;

    private HrEmployee $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'scoped-operational', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function actor(string $scope, string $email = 'actor@op.test'): User
    {
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'R'.substr(md5($email.$scope), 0, 6),
            'slug' => 'r_'.substr(md5($email.$scope), 0, 6),
            'permissions' => ['hr_employees' => [StaffPermission::VIEW_GLOBAL]],
            'scope' => $scope, 'is_system' => false,
        ]);

        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => 'staff',
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

    /** Actor in Ops, colleague in Ops, outsider in Sales. */
    private function cast(string $scope): User
    {
        $user = $this->actor($scope);
        $this->me       = $this->employee('O-1', $user, 'Ops');
        $this->mate     = $this->employee('O-2', null, 'Ops');
        $this->outsider = $this->employee('O-3', null, 'Sales');

        return $user;
    }

    private function now(): string
    {
        return now()->toDateTimeString();
    }

    /**
     * One row per employee in a table that carries employee_id.
     *
     * $extra may be a closure so a row can vary per employee — several of these
     * tables carry a per-employee unique key (a probation review is unique on
     * probation + review number), and three identical rows would collide.
     */
    private function seedPerEmployee(string $table, array|\Closure $extra = []): void
    {
        foreach ([$this->me, $this->mate, $this->outsider] as $e) {
            DB::table($table)->insert(array_merge([
                'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ], $extra instanceof \Closure ? $extra($e) : $extra));
        }
    }

    private function repo(string $class)
    {
        return app('App\\Repositories\\Hr\\'.$class);
    }

    /* ── payslips: the most disclosing employee-owned row ─────────────── */

    private function seedPayslips(): void
    {
        foreach ([$this->me, $this->mate, $this->outsider] as $i => $e) {
            DB::table('hr_payslips')->insert([
                'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
                'payroll_run_id' => 1, 'payroll_record_id' => $e->id,
                'payslip_number' => 'PS-'.$e->employee_code, 'payslip_month' => 6, 'payslip_year' => 2026,
                'gross_salary' => 1000 * ($i + 1), 'total_deductions' => 0, 'net_salary' => 1000 * ($i + 1),
                'status' => 'Generated', 'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]);
        }
    }

    public function test_payslip_list_is_department_scoped(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);
        $this->seedPayslips();

        $rows = $this->repo('PayslipRepository')->filtered($this->tenant->id, [], $user);

        $this->assertCount(2, $rows);
        $this->assertNotContains($this->outsider->id, $rows->pluck('employee_id')->all());
    }

    public function test_payslip_list_is_own_scoped(): void
    {
        $user = $this->cast(DataScope::OWN);
        $this->seedPayslips();

        $rows = $this->repo('PayslipRepository')->filtered($this->tenant->id, [], $user);

        $this->assertSame([$this->me->id], $rows->pluck('employee_id')->all());
    }

    public function test_payslip_list_is_unrestricted_for_global_and_for_no_actor(): void
    {
        $user = $this->cast(DataScope::GLOBAL);
        $this->seedPayslips();

        $this->assertCount(3, $this->repo('PayslipRepository')->filtered($this->tenant->id, [], $user));

        // No actor at all — a console command or a queued job — stays unscoped,
        // which is what makes adoption safe one caller at a time.
        $this->assertCount(3, $this->repo('PayslipRepository')->filtered($this->tenant->id, []));
    }

    public function test_an_out_of_scope_payslip_reads_as_absent_not_forbidden(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);
        $this->seedPayslips();

        $theirs = DB::table('hr_payslips')->where('employee_id', $this->outsider->id)->value('id');
        $mine   = DB::table('hr_payslips')->where('employee_id', $this->me->id)->value('id');

        $this->assertNotNull($this->repo('PayslipRepository')->findForTenant($mine, $this->tenant->id, $user));
        $this->assertNull(
            $this->repo('PayslipRepository')->findForTenant($theirs, $this->tenant->id, $user),
            'Null lets the caller 404 — saying "forbidden" would confirm whose payslip it is.'
        );
    }

    public function test_the_direct_employee_payslip_route_is_refused(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);
        $this->seedPayslips();

        $this->repo('PayslipRepository')->forEmployee($this->mate->id, $this->tenant->id, $user);   // in scope, fine

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->repo('PayslipRepository')->forEmployee($this->outsider->id, $this->tenant->id, $user);
    }

    /* ── salary: every method is a direct-id surface ──────────────────── */

    public function test_salary_history_refuses_an_out_of_scope_employee(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);

        $this->repo('EmployeeSalaryRepository')->historyFor($this->mate->id, $this->tenant->id, $user);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->repo('EmployeeSalaryRepository')->historyFor($this->outsider->id, $this->tenant->id, $user);
    }

    public function test_salary_mutation_lookup_refuses_an_out_of_scope_employee(): void
    {
        $user = $this->cast(DataScope::OWN);

        // findForTenant backs the WRITE paths — guarding the read is what stops
        // an edit being aimed at somebody outside the scope.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->repo('EmployeeSalaryRepository')->findForTenant(1, $this->mate->id, $this->tenant->id, $user);
    }

    /* ── aggregates must count the scoped population ──────────────────── */

    public function test_probation_stats_do_not_leak_a_global_total(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);
        // Each employee gets their own probation, since a review is unique on
        // (tenant, probation, review_no).
        $this->seedPerEmployee('hr_probation_reviews', fn ($e) => [
            'employee_probation_id' => $e->id, 'review_no' => 1, 'review_date' => '2026-06-01',
            'recommendation' => 'Confirm', 'status' => 'Completed', 'overall_rating' => 4,
        ]);

        $stats = $this->repo('ProbationReviewRepository')->stats($this->tenant->id, $user);

        $this->assertSame(2, $stats['total'], 'A total is a disclosure too — it must count the scoped set.');
    }

    public function test_training_stats_do_not_leak_a_global_total(): void
    {
        $user = $this->cast(DataScope::OWN);
        $this->seedPerEmployee('hr_employee_trainings', [
            'training_program_id' => 1, 'training_session_id' => 1, 'status' => 'Completed',
        ]);

        $stats = $this->repo('EmployeeTrainingRepository')->stats($this->tenant->id, $user);

        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['completed']);
        $this->assertSame(100.0, $stats['completion_pct'],
            'The percentage divides by the scoped total, so an unscoped denominator would show 33.3.');
    }

    /* ── team scope follows the reporting line ────────────────────────── */

    public function test_team_scope_follows_the_reporting_hierarchy(): void
    {
        $user  = $this->actor(DataScope::TEAM, 'lead@op.test');
        $lead  = $this->employee('T-1', $user, 'Ops');
        $mine  = $this->employee('T-2', null, 'Ops', $lead->id);
        $other = $this->employee('T-3', null, 'Ops');            // same dept, different manager

        foreach ([$lead, $mine, $other] as $e) {
            DB::table('hr_employee_trainings')->insert([
                'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
                'training_program_id' => 1, 'training_session_id' => 1, 'status' => 'Completed',
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]);
        }

        $ids = $this->repo('EmployeeTrainingRepository')
            ->assignments($this->tenant->id, [], $user)->pluck('employee_id')->all();

        $this->assertContains($lead->id, $ids);
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($other->id, $ids, 'Same department is not the same team.');
    }

    /* ── an actor with no employee record sees nothing ────────────────── */

    public function test_an_actor_without_an_employee_record_gets_no_rows(): void
    {
        $this->cast(DataScope::DEPARTMENT);
        $this->seedPayslips();

        $stranger = $this->actor(DataScope::DEPARTMENT, 'stranger@op.test');   // no HrEmployee

        $this->assertCount(0, $this->repo('PayslipRepository')->filtered($this->tenant->id, [], $stranger),
            'No place in the org chart means nothing, never everything.');
    }

    /* ── rows that reach their employee through a join ────────────────── */

    public function test_assessments_scope_through_the_assignment(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);

        foreach ([$this->me, $this->mate, $this->outsider] as $e) {
            $etId = DB::table('hr_employee_trainings')->insertGetId([
                'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
                'training_program_id' => 1, 'training_session_id' => 1, 'status' => 'Completed',
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]);
            DB::table('hr_training_assessments')->insert([
                'tenant_id' => $this->tenant->id, 'employee_training_id' => $etId,
                'assessment_name' => 'Final', 'result' => 'Pass',
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]);
        }

        // hr_training_assessments has no employee_id of its own.
        $rows = $this->repo('TrainingRecordRepository')->assessments($this->tenant->id, [], $user);

        $this->assertCount(2, $rows, 'A row without employee_id still belongs to somebody.');
    }

    /* ── the holiday calendar is NOT employee-scoped ──────────────────── */

    public function test_the_holiday_calendar_stays_whole_for_every_scope(): void
    {
        $user = $this->cast(DataScope::OWN);

        foreach (['Republic Day', 'Holi', 'Diwali'] as $i => $title) {
            DB::table('hr_holidays')->insert([
                'tenant_id' => $this->tenant->id, 'title' => $title,
                'holiday_date' => '2026-0'.($i + 1).'-15', 'holiday_type' => 'Public',
                'applicable_for' => 'Organization', 'is_active' => true,
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]);
        }

        // A public holiday is the same fact for everybody. Narrowing it would
        // hide real company holidays rather than protect anyone.
        $this->assertCount(3, $this->repo('HolidayRepository')->list($this->tenant->id, [], $user));
    }

    public function test_the_holiday_employee_filter_is_still_guarded(): void
    {
        $user = $this->cast(DataScope::OWN);

        // The calendar is open, but "which holidays apply to THIS person" reads
        // their department and designation, so the lookup is guarded.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->repo('HolidayRepository')->list($this->tenant->id, ['employee_id' => $this->outsider->id], $user);
    }

    /* ── leave balances: guarded at the service, not the repository ───── */

    public function test_the_leave_balance_view_refuses_an_out_of_scope_employee(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);
        $service = app(\App\Services\Hr\EmployeeLeaveBalanceService::class);

        $service->forEmployee($this->mate->id, $this->tenant->id, $user);      // in scope

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->forEmployee($this->outsider->id, $this->tenant->id, $user);
    }

    public function test_the_leave_ledger_refuses_an_out_of_scope_balance(): void
    {
        $user = $this->cast(DataScope::OWN);

        $balanceId = DB::table('hr_employee_leave_balances')->insertGetId([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->outsider->id,
            'leave_type_id' => 1, 'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Services\Hr\EmployeeLeaveBalanceService::class)->history($balanceId, $this->tenant->id, $user);
    }

    public function test_the_deduct_path_still_works_for_anyone(): void
    {
        $this->cast(DataScope::OWN);

        DB::table('hr_employee_leave_balances')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->outsider->id,
            'leave_type_id' => 1, 'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);

        // activeByType backs leave apply/deduct, including the Attendance App's.
        // It is deliberately unscoped: a balance has to be debited whoever
        // triggered the leave, or approving somebody's leave silently fails.
        $this->assertNotNull(
            app(\App\Repositories\Hr\EmployeeLeaveBalanceRepository::class)
                ->activeByType($this->outsider->id, 1, $this->tenant->id)
        );
    }

    /* ── aggregates I had missed on the first pass ────────────────────── */

    public function test_exit_request_tiles_count_only_the_scoped_population(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);

        foreach ([$this->me, $this->mate, $this->outsider] as $e) {
            DB::table('hr_exit_requests')->insert([
                'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
                'exit_type_id' => 1, 'request_date' => '2026-06-01', 'status' => 'Submitted',
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]);
        }

        $stats = app(\App\Repositories\Hr\ExitRepository::class)->requestStats($this->tenant->id, $user);

        $this->assertSame(2, $stats['submitted'], 'A tile is a disclosure: it must count what the list shows.');
    }

    public function test_the_performance_dashboard_average_is_scoped(): void
    {
        $user = $this->cast(DataScope::OWN);

        foreach ([[$this->me, 1], [$this->mate, 5], [$this->outsider, 5]] as [$e, $rating]) {
            DB::table('hr_performance_reviews')->insert([
                'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
                'review_type' => 'Annual', 'status' => 'Approved', 'overall_rating' => $rating,
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]);
        }

        $d = app(\App\Repositories\Hr\PerformanceRepository::class)->dashboard($this->tenant->id, $user);

        $this->assertSame(1, $d['total_employees']);
        $this->assertSame(1, $d['reviews_completed']);
        // An unscoped average would be 3.67 and would leak the other two ratings.
        $this->assertSame(1.0, $d['avg_rating']);
    }

    /* ── tenant isolation is unaffected ───────────────────────────────── */

    public function test_scope_never_widens_across_a_tenant(): void
    {
        $user = $this->cast(DataScope::GLOBAL);
        $this->seedPayslips();

        $other = Tenant::create(['name' => 'Other', 'slug' => 'op-other', 'status' => 'active']);
        $theirEmployee = HrEmployee::create([
            'tenant_id' => $other->id, 'employee_code' => 'X-1', 'name' => 'Theirs',
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2024-01-01', 'status' => 'Active',
        ]);
        DB::table('hr_payslips')->insert([
            'tenant_id' => $other->id, 'employee_id' => $theirEmployee->id,
            'payroll_run_id' => 9, 'payroll_record_id' => 99,
            'payslip_number' => 'PS-X', 'payslip_month' => 6, 'payslip_year' => 2026,
            'gross_salary' => 9999, 'total_deductions' => 0, 'net_salary' => 9999,
            'status' => 'Generated', 'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);

        // Global scope is the WIDEST scope, and it still stops at the tenant.
        $rows = $this->repo('PayslipRepository')->filtered($this->tenant->id, [], $user);

        $this->assertCount(3, $rows);
        $this->assertNotContains($theirEmployee->id, $rows->pluck('employee_id')->all());
    }
}
