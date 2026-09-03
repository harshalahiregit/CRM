<?php

namespace Tests\Feature\Hrm;

use App\Models\Hr\HrAdvance;
use App\Models\Hr\HrAdvanceSettlement;
use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrAttendanceCorrection;
use App\Models\Hr\HrDemoRequest;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrLeaveApplication;
use App\Models\Hr\HrLeaveType;
use App\Models\Hr\HrReimbursement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\AdvanceService;
use App\Services\Hr\ReimbursementService;
use App\Support\Hr\AdvanceStage;
use App\Support\Hr\ReimbursementStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The admin half of the app.
 *
 * The rule pinned hardest: a permission refusal must be 200 with status 0, NEVER
 * 401. The app treats 401 as a dead session and wipes local storage — including
 * cached clock-in state — so answering "you cannot approve this" that way signs
 * somebody out mid-shift.
 */
class HrmAdminTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $t = null;

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'S', 'slug' => 'hrmad-t', 'status' => 'active']);
    }

    private function user(string $email, string $role = 'staff', ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => $this->tenant()->id, 'name' => explode('@', $email)[0], 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $role, 'status' => 'active',
            'internal_role' => $internal,
        ]);
    }

    private function employee(string $code, ?User $u = null, ?int $mgr = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'employee_code' => $code, 'name' => 'E'.$code,
            'department' => 'Operations', 'designation' => 'Analyst', 'joining_date' => '2020-01-01',
            'status' => 'Active', 'user_id' => $u?->id, 'reporting_manager_id' => $mgr,
        ]);
    }

    private function admin(): User
    {
        $a = $this->user('admin@example.test', 'admin');
        Sanctum::actingAs($a);

        return $a;
    }

    /* ── the rule that matters most ──────────────────────────────────── */

    public function test_a_permission_refusal_is_200_with_status_zero_never_401(): void
    {
        $nobody = $this->user('nobody@example.test');
        Sanctum::actingAs($nobody);

        foreach ([
            ['get',  '/api/Hrm/admin/dashboard'],
            ['get',  '/api/Hrm/admin/attendance-details'],
            ['post', '/api/Hrm/admin/pending-approvals'],
            ['get',  '/api/Hrm/admin/employees-list'],
            ['get',  '/api/Hrm/admin/payroll-overview'],
        ] as [$verb, $url]) {
            $r = $verb === 'get' ? $this->getJson($url) : $this->postJson($url, []);

            // 401 would wipe the app's local storage and sign them out.
            $r->assertOk();
            $this->assertSame(0, $r->json('status'), "{$url} must refuse with status 0.");
        }
    }

    /* ── dashboard ───────────────────────────────────────────────────── */

    public function test_the_dashboard_carries_every_stat_the_screen_reads(): void
    {
        $this->admin();
        $e = $this->employee('SNE-1');
        $this->employee('SNE-2');

        HrAttendance::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $e->id, 'date' => now()->toDateString(),
            'check_in' => now()->setTime(9, 0), 'status' => 'Present',
        ]);

        $d = $this->getJson('/api/Hrm/admin/dashboard')->assertOk()->json('data');

        foreach (['total_employees', 'present', 'absent', 'late', 'on_leave', 'tracked', 'total_monthly_expense'] as $k) {
            $this->assertArrayHasKey($k, $d, "stat.{$k} is missing.");
        }

        $this->assertSame(2, $d['total_employees']);
        $this->assertSame(1, $d['present']);
        $this->assertSame(1, $d['absent'], 'Nobody recorded today counts as absent.');
    }

    public function test_attendance_details_lists_everybody_including_the_absent(): void
    {
        $this->admin();
        $e = $this->employee('SNE-1');
        $this->employee('SNE-2');

        HrAttendance::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $e->id, 'date' => now()->toDateString(),
            'check_in' => now()->setTime(9, 0), 'status' => 'Present',
        ]);

        $rows = $this->getJson('/api/Hrm/admin/attendance-details')->assertOk()->json('data');

        $this->assertCount(2, $rows, 'Somebody who never clocked in must still appear.');
        foreach (['id', 'user_id', 'employee_name', 'name', 'employee_code', 'department',
                  'designation', 'avatar', 'status', 'clock_in', 'clock_out'] as $k) {
            $this->assertArrayHasKey($k, $rows[0], "row.{$k} is missing.");
        }
        $this->assertSame('Absent', collect($rows)->firstWhere('employee_code', 'SNE-2')['status']);
    }

    /* ── the four queues ─────────────────────────────────────────────── */

    public function test_pending_approvals_returns_all_four_queues(): void
    {
        $admin = $this->admin();
        $u = $this->user('priya@example.test');
        $e = $this->employee('SNE-1', $u);

        $type = HrLeaveType::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Casual', 'code' => 'CL',
            'category' => 'Casual', 'paid' => true, 'yearly_limit' => 12,
            'requires_approval' => true, 'is_active' => true,
        ]);
        HrLeaveApplication::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $e->id, 'leave_type_id' => $type->id,
            'from_date' => '2026-03-02', 'to_date' => '2026-03-03', 'days' => 2,
            'reason' => 'Family', 'status' => 'Submitted',
        ]);
        HrAttendanceCorrection::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $e->id, 'attendance_date' => '2026-03-02',
            'requested_check_in' => '09:00', 'reason' => 'Forgot', 'status' => 'pending',
        ]);
        app(ReimbursementService::class)->submit($e, [
            'title' => 'Dinner', 'expense_date' => '2026-03-02', 'amount_claimed' => 5000,
        ], $u);
        app(AdvanceService::class)->request($e, ['purpose' => 'Site', 'amount_requested' => 20000], $u);

        $d = $this->postJson('/api/Hrm/admin/pending-approvals', [])->assertOk()->json('data');

        foreach (['leaves', 'raises', 'reimbursements', 'advances'] as $k) {
            $this->assertArrayHasKey($k, $d, "queue.{$k} is missing.");
            $this->assertCount(1, $d[$k], "queue.{$k} should have one item.");
        }

        foreach (['id', 'leave_id', 'employee_name', 'leave_type', 'start_date', 'end_date', 'total_leave_days', 'status'] as $k) {
            $this->assertArrayHasKey($k, $d['leaves'][0], "leave.{$k} is missing.");
        }
        foreach (['id', 'raise_id', 'employee_name', 'attendance_date', 'login_time', 'logout_time', 'reason', 'status'] as $k) {
            $this->assertArrayHasKey($k, $d['raises'][0], "raise.{$k} is missing.");
        }
        foreach (['id', 'reimbursement_id', 'employee_name', 'title', 'amount', 'expense_date', 'status', 'receipt'] as $k) {
            $this->assertArrayHasKey($k, $d['reimbursements'][0], "claim.{$k} is missing.");
        }
        foreach (['id', 'advance_id', 'employee_name', 'advance_type', 'purpose', 'amount',
                  'amount_requested', 'amount_approved', 'status', 'awaiting_disbursement'] as $k) {
            $this->assertArrayHasKey($k, $d['advances'][0], "advance.{$k} is missing.");
        }
    }

    /* ── decisions ───────────────────────────────────────────────────── */

    public function test_deciding_a_leave(): void
    {
        $this->admin();
        $u = $this->user('priya@example.test');
        $e = $this->employee('SNE-1', $u);
        $type = HrLeaveType::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Casual', 'code' => 'CL', 'category' => 'Casual',
            'paid' => true, 'yearly_limit' => 12, 'requires_approval' => true, 'is_active' => true,
        ]);
        $l = HrLeaveApplication::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $e->id, 'leave_type_id' => $type->id,
            'from_date' => '2026-03-02', 'to_date' => '2026-03-02', 'days' => 1, 'status' => 'Submitted',
        ]);

        $this->postJson('/api/Hrm/admin/approve-reject-leave', [
            'leave_id' => $l->id, 'status' => 'approved', 'remark' => 'Fine',
        ])->assertOk()->assertJsonPath('status', 1);

        $this->assertSame('Approved', $l->fresh()->status);

        // Deciding twice is refused without an HTTP error.
        $r = $this->postJson('/api/Hrm/admin/approve-reject-leave', [
            'leave_id' => $l->id, 'status' => 'rejected',
        ])->assertOk();
        $this->assertSame(0, $r->json('status'));
    }

    /** Approving a raise from the phone must write the day, as the CRM does. */
    public function test_approving_a_raise_updates_the_timesheet(): void
    {
        $this->admin();
        $u = $this->user('priya@example.test');
        $e = $this->employee('SNE-1', $u);

        $c = HrAttendanceCorrection::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $e->id, 'attendance_date' => '2026-03-02',
            'requested_check_in' => '09:00', 'requested_check_out' => '18:00',
            'reason' => 'Forgot to clock out', 'status' => 'pending',
        ]);

        $this->postJson('/api/Hrm/admin/approve-reject-raise', [
            'raise_id' => $c->id, 'status' => 'approved',
        ])->assertOk()->assertJsonPath('status', 1);

        $day = HrAttendance::where('employee_id', $e->id)->firstOrFail();
        $this->assertSame('18:00:00', $day->check_out->format('H:i:s'));
        $this->assertTrue($c->fresh()->applied);
    }

    public function test_deciding_a_reimbursement(): void
    {
        $this->admin();
        $u = $this->user('priya@example.test');
        $e = $this->employee('SNE-1', $u);
        $claim = app(ReimbursementService::class)->submit($e, [
            'title' => 'Dinner', 'expense_date' => '2026-03-02', 'amount_claimed' => 5000,
        ], $u);

        $this->postJson('/api/Hrm/admin/approve-reject-reimbursement', [
            'reimbursement_id' => $claim->id, 'status' => 'approved',
        ])->assertOk()->assertJsonPath('status', 1);

        $this->assertSame(ReimbursementStatus::APPROVED, $claim->fresh()->status);
    }

    /**
     * The ladder applies to a phone approval exactly as it does in the browser,
     * including that one person cannot approve at two rungs.
     */
    public function test_an_advance_from_the_app_climbs_the_same_ladder(): void
    {
        $mgrUser = $this->user('mgr@example.test');
        $mgr     = $this->employee('SNE-M', $mgrUser);
        $u       = $this->user('priya@example.test');
        $e       = $this->employee('SNE-1', $u, $mgr->id);

        $advance = app(AdvanceService::class)->request($e, ['purpose' => 'Site', 'amount_requested' => 20000], $u);

        // An ADMIN approves from the app. The app's admin screens are for
        // type: company — a line manager is an ordinary employee there and
        // approves from the CRM instead. An admin may stand in on any rung.
        $admin = $this->user('admin@example.test', 'admin');
        Sanctum::actingAs($admin);
        $this->postJson('/api/Hrm/admin/approve-reject-advance', [
            'advance_id' => $advance->id, 'status' => 'approved',
        ])->assertOk()->assertJsonPath('status', 1);

        $this->assertSame(AdvanceStage::MANAGER_APPROVED, $advance->fresh()->status);

        // The same person at the next rung is refused — with a readable reason,
        // not an HTTP error.
        $r = $this->postJson('/api/Hrm/admin/approve-reject-advance', [
            'advance_id' => $advance->id, 'status' => 'approved',
        ])->assertOk();

        $this->assertSame(0, $r->json('status'));
        $this->assertStringContainsString('already approved', (string) $r->json('message'));
    }

    public function test_disbursing_and_reviewing_a_settlement(): void
    {
        $mgrUser = $this->user('mgr@example.test');
        $mgr     = $this->employee('SNE-M', $mgrUser);
        $u       = $this->user('priya@example.test');
        $e       = $this->employee('SNE-1', $u, $mgr->id);
        $acc     = $this->user('acc@example.test', 'staff', 'accounts');
        $dir     = $this->user('dir@example.test', 'staff', 'director');

        $svc = app(AdvanceService::class);
        $a = $svc->request($e, ['purpose' => 'Site', 'amount_requested' => 20000], $u);
        $svc->approve($a, $mgrUser);
        $svc->approve($a->fresh(), $acc);
        $svc->approve($a->fresh(), $dir);

        Sanctum::actingAs($acc);
        // A transfer with no reference is refused — as a message, not a 500.
        $r = $this->postJson('/api/Hrm/admin/disburse-advance', [
            'advance_id' => $a->id, 'payment_mode' => 'bank_transfer',
        ])->assertOk();
        $this->assertSame(0, $r->json('status'));

        $this->postJson('/api/Hrm/admin/disburse-advance', [
            'advance_id' => $a->id, 'payment_mode' => 'cash',
        ])->assertOk()->assertJsonPath('status', 1);

        $svc->submitSettlement($a->fresh(), $u, ['actual_expense' => 14000]);

        $rows = $this->getJson('/api/Hrm/admin/pending-settlements')->assertOk()->json('data');
        foreach (['id', 'settlement_id', 'advance_id', 'employee_name', 'disbursed_amount',
                  'actual_expense', 'balance_return', 'extra_due', 'case_label', 'status', 'bills'] as $k) {
            $this->assertArrayHasKey($k, $rows[0], "settlement.{$k} is missing.");
        }
        $this->assertIsArray($rows[0]['bills']);

        $this->postJson('/api/Hrm/admin/review-settlement', [
            'settlement_id' => $rows[0]['id'], 'status' => 'approved',
        ])->assertOk()->assertJsonPath('status', 1);

        $this->assertSame(AdvanceStage::SETTLED, $a->fresh()->status);
    }

    /* ── people ──────────────────────────────────────────────────────── */

    public function test_creating_an_employee_returns_a_temp_password_and_never_an_admin(): void
    {
        $this->admin();

        $d = $this->postJson('/api/Hrm/admin/create-employee', [
            'name' => 'Raj Verma', 'email' => 'raj@example.test', 'mobile_no' => '9000000000',
            'role' => 'employee',
        ])->assertOk()->json('data');

        foreach (['id', 'user_id', 'employee_code', 'temp_password'] as $k) {
            $this->assertArrayHasKey($k, $d, "create.{$k} is missing.");
        }
        $this->assertNotEmpty($d['temp_password']);

        $created = User::where('email', 'raj@example.test')->firstOrFail();
        // The app must not be able to mint administrators.
        $this->assertSame('staff', $created->role);
        // And the person is an employee, not just a login.
        $this->assertNotNull(HrEmployee::where('user_id', $created->id)->first());
    }

    public function test_creating_an_employee_cannot_escalate_via_the_payload(): void
    {
        $this->admin();

        $this->postJson('/api/Hrm/admin/create-employee', [
            'name' => 'Sneaky', 'email' => 'sneaky@example.test', 'role' => 'admin',
        ])->assertOk();

        $this->assertSame('staff', User::where('email', 'sneaky@example.test')->firstOrFail()->role);
    }

    public function test_resetting_a_password_ends_that_persons_sessions(): void
    {
        $this->admin();
        $victim = $this->user('priya@example.test');
        $victim->createToken('phone');

        $this->assertSame(1, $victim->tokens()->count());

        $d = $this->postJson('/api/Hrm/admin/reset-employee-password', [
            'employee_user_id' => $victim->id,
        ])->assertOk()->json('data');

        $this->assertNotEmpty($d['temp_password']);
        $this->assertTrue(Hash::check($d['temp_password'], $victim->fresh()->password));
        // A reset nobody is signed out of is not a reset.
        $this->assertSame(0, $victim->fresh()->tokens()->count());
    }

    public function test_assignable_roles_use_their_label_value_shape(): void
    {
        $this->admin();

        $rows = $this->getJson('/api/Hrm/admin/assignable-roles')->assertOk()->json('data');

        $this->assertNotEmpty($rows);
        $this->assertArrayHasKey('label', $rows[0]);
        $this->assertArrayHasKey('value', $rows[0]);
    }

    public function test_the_employee_list_shape(): void
    {
        $this->admin();
        $this->employee('SNE-1', $this->user('priya@example.test'));

        $row = $this->getJson('/api/Hrm/admin/employees-list')->assertOk()->json('data.0');

        foreach (['id', 'user_id', 'name', 'employee_code', 'email', 'phone',
                  'department', 'designation', 'status', 'avatar'] as $k) {
            $this->assertArrayHasKey($k, $row, "employee.{$k} is missing.");
        }
    }

    /* ── demo requests ───────────────────────────────────────────────── */

    public function test_demo_requests_list_and_update_including_their_closed_status(): void
    {
        $this->admin();
        HrDemoRequest::create(['name' => 'Priya', 'company_name' => 'Acme', 'status' => 'new']);

        $row = $this->getJson('/api/Hrm/admin/demo-requests')->assertOk()->json('data.0');
        foreach (['id', 'name', 'company_name', 'email', 'phone', 'address',
                  'num_employees', 'message', 'notes', 'status', 'created_at'] as $k) {
            $this->assertArrayHasKey($k, $row, "demo.{$k} is missing.");
        }

        // 'closed' is their word; the CRM stores it as declined.
        $this->postJson('/api/Hrm/admin/update-demo-request', [
            'id' => $row['id'], 'status' => 'closed', 'notes' => 'Not a fit',
        ])->assertOk()->assertJsonPath('status', 1);

        $this->assertSame('declined', HrDemoRequest::firstOrFail()->status);
    }

    /* ── payroll and reports ─────────────────────────────────────────── */

    public function test_payroll_overview_reports_what_is_set_and_what_is_missing(): void
    {
        $this->admin();
        $a = $this->employee('SNE-1');
        $this->employee('SNE-2');

        DB::table('hr_employee_salaries')->insert([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $a->id, 'effective_from' => '2026-01-01',
            'monthly_ctc' => 50000, 'annual_ctc' => 600000, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $d = $this->getJson('/api/Hrm/admin/payroll-overview')->assertOk()->json('data');

        foreach (['summary', 'employees', 'payslip_types'] as $k) {
            $this->assertArrayHasKey($k, $d, "payroll.{$k} is missing.");
        }
        foreach (['total_employees', 'salary_set', 'salary_missing', 'monthly_payroll_cost'] as $k) {
            $this->assertArrayHasKey($k, $d['summary'], "summary.{$k} is missing.");
        }
        foreach (['id', 'name', 'employee_code', 'department', 'designation',
                  'salary', 'annual_salary', 'salary_type', 'salary_type_name', 'salary_set'] as $k) {
            $this->assertArrayHasKey($k, $d['employees'][0], "employee.{$k} is missing.");
        }

        $this->assertSame(1, $d['summary']['salary_set']);
        $this->assertSame(1, $d['summary']['salary_missing']);
        $this->assertSame('50000', $d['summary']['monthly_payroll_cost']);
    }

    /** A raise must not rewrite what somebody was paid last month. */
    public function test_setting_a_salary_supersedes_rather_than_overwrites(): void
    {
        $this->admin();
        $e = $this->employee('SNE-1');

        $this->postJson('/api/Hrm/admin/set-employee-salary', [
            'employee_id' => $e->id, 'salary' => 50000, 'salary_type' => 'monthly',
        ])->assertOk()->assertJsonPath('status', 1);

        $this->postJson('/api/Hrm/admin/set-employee-salary', [
            'employee_id' => $e->id, 'salary' => 60000,
        ])->assertOk();

        $rows = DB::table('hr_employee_salaries')->where('employee_id', $e->id)->get();

        $this->assertCount(2, $rows, 'The old figure is a fact and must survive.');
        $this->assertSame(1, $rows->whereNull('effective_to')->count(), 'Exactly one current salary.');
    }

    public function test_reports_use_their_generic_table_shape(): void
    {
        $this->admin();
        $e = $this->employee('SNE-1');

        HrAttendance::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $e->id, 'date' => '2026-03-02',
            'check_in' => '2026-03-02 09:00:00', 'check_out' => '2026-03-02 18:00:00',
            'working_hours' => 9, 'status' => 'Present',
        ]);

        $d = $this->getJson('/api/Hrm/admin/reports?month=2026-03')->assertOk()->json('data');

        $this->assertArrayHasKey('reports', $d);
        $report = $d['reports'][0];

        foreach (['title', 'subtitle', 'columns', 'rows', 'totals'] as $k) {
            $this->assertArrayHasKey($k, $report, "report.{$k} is missing.");
        }
        foreach (['key', 'label', 'align'] as $k) {
            $this->assertArrayHasKey($k, $report['columns'][0], "column.{$k} is missing.");
        }
        $this->assertNotEmpty($report['rows']);
    }

    public function test_reports_summary_shape(): void
    {
        $this->admin();

        $d = $this->getJson('/api/Hrm/admin/reports-summary')->assertOk()->json('data');

        $this->assertArrayHasKey('summary', $d);
        $this->assertArrayHasKey('month', $d);
    }

    /* ── tenancy ─────────────────────────────────────────────────────── */

    public function test_an_admin_never_sees_another_tenants_people(): void
    {
        $this->admin();
        $this->employee('SNE-1');

        $other = Tenant::create(['name' => 'O', 'slug' => 'hrmad-o', 'status' => 'active']);
        HrEmployee::create([
            'tenant_id' => $other->id, 'employee_code' => 'X-1', 'name' => 'Theirs',
            'department' => 'Ops', 'designation' => 'A', 'joining_date' => '2020-01-01', 'status' => 'Active',
        ]);

        $this->getJson('/api/Hrm/admin/employees-list')->assertOk()->assertJsonCount(1, 'data');
    }
}
