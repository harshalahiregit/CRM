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
 * The six operational surfaces the previous scope pass left behind.
 *
 * These are the records that say what somebody owes, what they earn on top of
 * salary, what they told the company about their taxes, when they are expected
 * at work, and how their joining went. The earlier phases scoped the payslip and
 * the probation review; leaving these unscoped meant the same actor who could
 * not open a colleague's payslip could still read their loan balance and their
 * rent receipts.
 *
 * Two of the six have a shape the others do not, and both are pinned here:
 *
 *   Leave is shared with the attendance app, so the boundary sits on the CRM's
 *   own controllers. The app's apply/approve path and the employee's own
 *   self-service must keep working untouched.
 *
 *   Onboarding has a legitimately NULL employee_id before somebody joins.
 *   Scoping those rows away would strand the candidates the flow exists to
 *   move along, so they stay visible.
 */
class ScopedRemainingOperationalSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private HrEmployee $me;

    private HrEmployee $mate;

    private HrEmployee $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'scoped-remaining', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function actor(string $scope, string $email = 'actor@rem.test'): User
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

    private function cast(string $scope): User
    {
        $user = $this->actor($scope);
        $this->me       = $this->employee('R-1', $user, 'Ops');
        $this->mate     = $this->employee('R-2', null, 'Ops');
        $this->outsider = $this->employee('R-3', null, 'Sales');

        return $user;
    }

    private function now(): string
    {
        return now()->toDateTimeString();
    }

    private function stamps(): array
    {
        return ['created_at' => $this->now(), 'updated_at' => $this->now()];
    }

    private function each(callable $fn): void
    {
        foreach ([$this->me, $this->mate, $this->outsider] as $e) {
            $fn($e);
        }
    }

    private function httpException(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    }

    /* ────────────────────────────────────────────────────────────────────
     | 1. Leave applications and approvals
     ──────────────────────────────────────────────────────────────────── */

    private function seedLeave(): void
    {
        $this->each(fn ($e) => DB::table('hr_leave_applications')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'leave_type_id' => 1,
            'from_date' => '2026-06-01', 'to_date' => '2026-06-02', 'status' => 'Submitted',
        ] + $this->stamps()));
    }

    public function test_the_leave_queue_counters_match_the_rows_beneath_them(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);
        $this->seedLeave();

        $queue = app(\App\Services\Hr\LeaveApprovalService::class)
            ->queue($this->tenant->id, [], $user);

        // The tiles used to count every application in the tenant while the
        // rows under them were already scoped, so the header said 3 over 2.
        $this->assertCount(2, $queue['data']);
        $this->assertSame(2, $queue['stats']['pending']);
    }

    public function test_the_leave_queue_is_whole_for_a_global_actor(): void
    {
        $user = $this->cast(DataScope::GLOBAL);
        $this->seedLeave();

        $queue = app(\App\Services\Hr\LeaveApprovalService::class)->queue($this->tenant->id, [], $user);

        $this->assertCount(3, $queue['data']);
        $this->assertSame(3, $queue['stats']['pending']);
    }

    public function test_leave_counters_are_empty_for_an_actor_with_no_employee_record(): void
    {
        $this->cast(DataScope::DEPARTMENT);
        $this->seedLeave();
        $stranger = $this->actor(DataScope::DEPARTMENT, 'stranger@rem.test');

        $queue = app(\App\Services\Hr\LeaveApprovalService::class)->queue($this->tenant->id, [], $stranger);

        $this->assertCount(0, $queue['data']);
        $this->assertSame(0, $queue['stats']['pending']);
    }

    public function test_the_attendance_apps_leave_paths_are_untouched(): void
    {
        $this->cast(DataScope::OWN);
        $this->seedLeave();

        // The app reaches leave through the repository with NO actor — its own
        // authorisation is denyDecisionFor() on HrmAdminController, which this
        // task does not modify. An unscoped call must still see everything, or
        // approving from the phone would start failing.
        $repo = app(\App\Repositories\Hr\LeaveApplicationRepository::class);

        $this->assertCount(3, $repo->filtered($this->tenant->id, []));
        $this->assertSame(3, $repo->statusCounts($this->tenant->id)['pending']);
    }

    public function test_employee_self_service_still_reads_its_own_leave(): void
    {
        $this->cast(DataScope::OWN);
        $this->seedLeave();

        // MyLeaveController resolves the employee from the signed-in user and
        // calls forEmployee(). That stays unscoped on purpose: it is already
        // the caller's own record by construction.
        $rows = app(\App\Repositories\Hr\LeaveApplicationRepository::class)
            ->forEmployee($this->me->id, $this->tenant->id);

        $this->assertCount(1, $rows);
        $this->assertSame($this->me->id, $rows->first()->employee_id);
    }

    /* ────────────────────────────────────────────────────────────────────
     | 2. Employee loans
     ──────────────────────────────────────────────────────────────────── */

    private function seedLoans(): int
    {
        $typeId = DB::table('hr_loan_types')->insertGetId([
            'tenant_id' => $this->tenant->id, 'name' => 'Personal', 'is_advance' => false, 'is_active' => true,
        ] + $this->stamps());

        $this->each(fn ($e) => DB::table('hr_employee_loans')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'loan_type_id' => $typeId,
            'principal' => 10000, 'outstanding' => 10000, 'status' => 'Disbursed',
        ] + $this->stamps()));

        return $typeId;
    }

    public function test_the_loan_list_is_department_scoped(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);
        $this->seedLoans();

        $rows = app(\App\Services\Hr\LoanService::class)->list($this->tenant->id, [], $user);

        $this->assertCount(2, $rows);
        $this->assertNotContains($this->outsider->id, array_column($rows, 'employee_id'));
    }

    public function test_loan_totals_are_money_and_are_scoped(): void
    {
        $user = $this->cast(DataScope::OWN);
        $this->seedLoans();

        $stats = app(\App\Services\Hr\LoanService::class)->stats($this->tenant->id, $user);

        // Unscoped this read 30000 — how much the people you cannot see owe.
        $this->assertSame(1, $stats['active']);
        $this->assertSame(10000.0, $stats['total_outstanding']);
    }

    public function test_an_out_of_scope_loan_is_not_found_rather_than_forbidden(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);
        $this->seedLoans();
        $theirs = DB::table('hr_employee_loans')->where('employee_id', $this->outsider->id)->value('id');

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->expectExceptionMessage('Loan not found');
        app(\App\Services\Hr\LoanService::class)->show($theirs, $this->tenant->id, $user);
    }

    public function test_an_out_of_scope_loan_cannot_be_approved(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);
        $this->seedLoans();

        // Submitted, so approve() would genuinely succeed if the scope allowed
        // it. Left as Disbursed this passed for the wrong reason — the refusal
        // came from the status check, not the boundary.
        DB::table('hr_employee_loans')->update(['status' => 'Submitted']);

        $mine   = DB::table('hr_employee_loans')->where('employee_id', $this->mate->id)->value('id');
        $theirs = DB::table('hr_employee_loans')->where('employee_id', $this->outsider->id)->value('id');

        $service = app(\App\Services\Hr\LoanService::class);

        $approved = $service->approve($mine, $this->tenant->id, $user);
        $this->assertSame('Approved', $approved['status'], 'An in-scope loan must still be approvable.');

        // Every loan action funnels through the same lookup, so blocking the
        // read blocks approve, disburse, close and cancel with it.
        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->expectExceptionMessage('Loan not found');
        $service->approve($theirs, $this->tenant->id, $user);
    }

    public function test_a_loan_cannot_be_booked_against_an_out_of_scope_employee(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);
        $typeId = $this->seedLoans();

        $this->httpException();
        app(\App\Services\Hr\LoanService::class)->save(null, [
            'employee_id' => $this->outsider->id, 'loan_type_id' => $typeId,
            'principal' => 5000, 'tenure_months' => 5,
        ], $this->tenant->id, $user);
    }

    public function test_the_loan_recovery_twin_is_scoped_too(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);
        $this->seedLoans();

        $recovery = app(\App\Services\Hr\LoanRecoveryService::class);

        // Same rows, reached from the employee profile instead of the queue.
        $this->assertCount(2, $recovery->outstanding($this->tenant->id, [], $user));

        $this->httpException();
        $recovery->forEmployee($this->outsider->id, $this->tenant->id, $user);
    }

    public function test_payroll_still_sees_every_loan(): void
    {
        $this->cast(DataScope::OWN);
        $this->seedLoans();

        // LoanDeductionService runs with no actor. A run that skipped employees
        // because of who started it would under-recover.
        $this->assertCount(3, app(\App\Services\Hr\LoanService::class)->list($this->tenant->id, []));
        $this->assertSame(30000.0, app(\App\Services\Hr\LoanService::class)->stats($this->tenant->id)['total_outstanding']);
    }

    /* ────────────────────────────────────────────────────────────────────
     | 3. Variable earnings
     ──────────────────────────────────────────────────────────────────── */

    private function seedEarnings(): int
    {
        $componentId = DB::table('hr_salary_components')->insertGetId([
            'tenant_id' => $this->tenant->id, 'name' => 'Incentive', 'code' => 'INC',
            'type' => 'Earning', 'calculation_type' => 'Fixed',
        ] + $this->stamps());

        $this->each(fn ($e) => DB::table('hr_employee_variable_earnings')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'component_id' => $componentId,
            // Lowercase: HrEmployeeVariableEarning uses a different status
            // vocabulary from the loan and leave models.
            'period' => '2026-06', 'amount' => 5000, 'status' => 'approved',
        ] + $this->stamps()));

        return $componentId;
    }

    public function test_variable_earnings_are_team_scoped(): void
    {
        $user  = $this->actor(DataScope::TEAM, 'lead@rem.test');
        $lead  = $this->employee('V-1', $user, 'Ops');
        $mine  = $this->employee('V-2', null, 'Ops', $lead->id);
        $other = $this->employee('V-3', null, 'Ops');          // same dept, different manager

        $componentId = DB::table('hr_salary_components')->insertGetId([
            'tenant_id' => $this->tenant->id, 'name' => 'Incentive', 'code' => 'INC',
            'type' => 'Earning', 'calculation_type' => 'Fixed',
        ] + $this->stamps());

        foreach ([$lead, $mine, $other] as $e) {
            DB::table('hr_employee_variable_earnings')->insert([
                'tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'component_id' => $componentId,
                'period' => '2026-06', 'amount' => 5000, 'status' => 'approved',
            ] + $this->stamps());
        }

        $ids = array_column(
            app(\App\Services\Hr\VariableEarningService::class)->list($this->tenant->id, [], $user),
            'employee_id'
        );

        $this->assertContains($lead->id, $ids);
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($other->id, $ids, 'Same department is not the same team.');
    }

    public function test_an_earning_cannot_be_raised_for_an_out_of_scope_employee(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);
        $componentId = $this->seedEarnings();

        $this->httpException();
        app(\App\Services\Hr\VariableEarningService::class)->save([
            'employee_id' => $this->outsider->id, 'component_id' => $componentId,
            'period' => '2026-07', 'amount' => 9000,
        ], $this->tenant->id, $user);
    }

    public function test_an_out_of_scope_earning_cannot_be_approved(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);
        $this->seedEarnings();
        $theirs = DB::table('hr_employee_variable_earnings')->where('employee_id', $this->outsider->id)->value('id');

        $this->expectException(\App\Exceptions\BusinessException::class);
        app(\App\Services\Hr\VariableEarningService::class)->approve($theirs, $this->tenant->id, $user);
    }

    public function test_payroll_reads_every_earning_line(): void
    {
        $this->cast(DataScope::OWN);
        $this->seedEarnings();

        // linesFor() is the payroll run reading its own inputs — deliberately
        // unscoped, or an employee's incentive would silently not be paid.
        $lines = app(\App\Services\Hr\VariableEarningService::class)
            ->linesFor($this->outsider->id, $this->tenant->id, '2026-06');

        $this->assertNotEmpty($lines);
    }

    /* ────────────────────────────────────────────────────────────────────
     | 4. Investment declarations
     ──────────────────────────────────────────────────────────────────── */

    private function seedDeclarations(): void
    {
        $this->each(fn ($e) => DB::table('hr_investment_declarations')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
            'financial_year' => '2026-27', 'regime' => 'new', 'status' => 'Submitted',
        ] + $this->stamps()));
    }

    public function test_declarations_are_department_scoped(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);
        $this->seedDeclarations();

        $rows = app(\App\Services\Hr\InvestmentDeclarationService::class)->list($this->tenant->id, [], $user);

        $this->assertCount(2, $rows);
    }

    public function test_an_out_of_scope_declaration_cannot_be_verified(): void
    {
        $user = $this->cast(DataScope::OWN);
        $this->seedDeclarations();
        $theirs = DB::table('hr_investment_declarations')->where('employee_id', $this->outsider->id)->value('id');

        // Verifying decides what reduces somebody's tax.
        $this->expectException(\App\Exceptions\BusinessException::class);
        app(\App\Services\Hr\InvestmentDeclarationService::class)
            ->verify($theirs, ['items' => []], $this->tenant->id, $user);
    }

    public function test_reading_an_out_of_scope_declaration_does_not_create_one(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);

        $before = DB::table('hr_investment_declarations')->count();

        try {
            app(\App\Services\Hr\InvestmentDeclarationService::class)
                ->forEmployee($this->outsider->id, $this->tenant->id, '2026-27', $user);
            $this->fail('An out-of-scope employee declaration should not be reachable.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            // expected
        }

        // forEmployee() creates a Draft when none exists, so an unchecked call
        // would not just read somebody else's record — it would make one.
        $this->assertSame($before, DB::table('hr_investment_declarations')->count());
    }

    /* ────────────────────────────────────────────────────────────────────
     | 5. Employee shifts and rosters
     ──────────────────────────────────────────────────────────────────── */

    private function seedShifts(): int
    {
        $shiftId = DB::table('hr_shifts')->insertGetId([
            'tenant_id' => $this->tenant->id, 'name' => 'General', 'code' => 'GEN', 'is_active' => true,
        ] + $this->stamps());

        $this->each(fn ($e) => DB::table('hr_employee_shifts')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'shift_id' => $shiftId,
            'effective_from' => '2026-01-01', 'effective_to' => null,
        ] + $this->stamps()));

        return $shiftId;
    }

    public function test_the_roster_is_scoped(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);
        $this->seedShifts();

        $rows = app(\App\Services\Hr\ShiftService::class)->roster($this->tenant->id, [], $user);

        $this->assertCount(2, $rows);
        $this->assertNotContains($this->outsider->id, array_column($rows, 'employee_id'));
    }

    public function test_shift_history_refuses_an_out_of_scope_employee(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);
        $this->seedShifts();

        app(\App\Services\Hr\ShiftService::class)->history($this->mate->id, $this->tenant->id, $user);

        $this->httpException();
        app(\App\Services\Hr\ShiftService::class)->history($this->outsider->id, $this->tenant->id, $user);
    }

    public function test_a_shift_cannot_be_assigned_to_an_out_of_scope_employee(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);
        $shiftId = $this->seedShifts();

        $this->httpException();
        app(\App\Services\Hr\ShiftService::class)->assign([
            'employee_id' => $this->outsider->id, 'shift_id' => $shiftId,
            'effective_from' => '2026-07-01',
        ], $this->tenant->id, $user);
    }

    public function test_the_punch_resolver_stays_unscoped(): void
    {
        $this->cast(DataScope::OWN);
        $this->seedShifts();

        // AttendanceService calls shiftForDate() inside a punch, where there is
        // no viewer at all. Scoping it would make attendance depend on who
        // triggered the sync.
        $result = app(\App\Services\Hr\ShiftService::class)
            ->shiftForDate($this->outsider->id, $this->tenant->id, '2026-06-15');

        $this->assertNotNull($result['shift']);
        $this->assertIsBool(app(\App\Services\Hr\ShiftService::class)
            ->isWeekOff($this->outsider->id, $this->tenant->id, '2026-06-15'));
    }

    /* ────────────────────────────────────────────────────────────────────
     | 6. Employee onboardings — the nullable employee_id case
     ──────────────────────────────────────────────────────────────────── */

    private function seedOnboardings(): void
    {
        $this->each(fn ($e) => DB::table('hr_employee_onboardings')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
            'status' => 'in_progress', 'current_stage' => 'employee_created', 'progress_percent' => 10,
        ] + $this->stamps()));
    }

    public function test_onboardings_are_department_scoped(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);
        $this->seedOnboardings();

        $page = app(\App\Services\Hr\EmployeeOnboardingService::class)->list($this->tenant->id, [], $user);

        $this->assertSame(2, $page->total());
    }

    public function test_a_candidate_onboarding_without_an_employee_stays_visible(): void
    {
        $user = $this->cast(DataScope::OWN);
        $this->seedOnboardings();

        // employee_id is nullable on purpose: the migration that made it so says
        // a record "must be able to exist against a candidate only; employee_id
        // is populated later, on joining".
        DB::table('hr_employee_onboardings')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => null, 'candidate_id' => 77,
            'status' => 'pending', 'current_stage' => 'offer_accepted', 'progress_percent' => 0,
        ] + $this->stamps());

        $page = app(\App\Services\Hr\EmployeeOnboardingService::class)->list($this->tenant->id, [], $user);

        // own scope: the actor's own row, plus the candidate who belongs to
        // nobody. Dropping the candidate would strand them before joining.
        $this->assertSame(2, $page->total());
        $ids = collect($page->items())->pluck('employee_id');
        $this->assertTrue($ids->contains(null), 'The unjoined candidate must remain reachable.');
        $this->assertFalse($ids->contains($this->outsider->id));
    }

    public function test_the_onboarding_dashboard_counts_the_scoped_population(): void
    {
        $user = $this->cast(DataScope::DEPARTMENT);
        $this->seedOnboardings();

        $cards = app(\App\Services\Hr\EmployeeOnboardingService::class)
            ->dashboard($this->tenant->id, $user)['cards'];

        $this->assertSame(2, $cards['total']);
        $this->assertSame(2, $cards['total_employees']);
    }

    public function test_onboarding_creation_and_the_candidate_portal_are_unaffected(): void
    {
        $this->cast(DataScope::OWN);

        // No actor — the offer/joining path and the candidate portal both reach
        // onboarding without one.
        $page = app(\App\Services\Hr\EmployeeOnboardingService::class)->list($this->tenant->id, []);
        $this->assertSame(0, $page->total());

        $onboarding = app(\App\Services\Hr\EmployeeOnboardingService::class)
            ->createFromEmployee($this->outsider->id, $this->actor(DataScope::GLOBAL, 'hr@rem.test'));

        $this->assertSame($this->outsider->id, $onboarding->employee_id);
    }

    /* ────────────────────────────────────────────────────────────────────
     | Tenant isolation
     ──────────────────────────────────────────────────────────────────── */

    public function test_scope_never_widens_across_a_tenant(): void
    {
        $user = $this->cast(DataScope::GLOBAL);
        $this->seedLoans();

        $other = Tenant::create(['name' => 'Other', 'slug' => 'rem-other', 'status' => 'active']);
        $theirEmployee = HrEmployee::create([
            'tenant_id' => $other->id, 'employee_code' => 'X-1', 'name' => 'Theirs',
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2024-01-01', 'status' => 'Active',
        ]);
        $theirType = DB::table('hr_loan_types')->insertGetId([
            'tenant_id' => $other->id, 'name' => 'Personal', 'is_advance' => false, 'is_active' => true,
        ] + $this->stamps());
        DB::table('hr_employee_loans')->insert([
            'tenant_id' => $other->id, 'employee_id' => $theirEmployee->id, 'loan_type_id' => $theirType,
            'principal' => 99999, 'outstanding' => 99999, 'status' => 'Disbursed',
        ] + $this->stamps());

        // Global is the widest scope and still stops at the tenant.
        $rows = app(\App\Services\Hr\LoanService::class)->list($this->tenant->id, [], $user);

        $this->assertCount(3, $rows);
        $this->assertNotContains($theirEmployee->id, array_column($rows, 'employee_id'));
    }
}
