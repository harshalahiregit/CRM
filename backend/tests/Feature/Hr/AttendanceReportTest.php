<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrAdvance;
use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrReimbursement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\AttendanceReportService;
use App\Support\Hr\AdvanceStage;
use App\Support\Hr\ReimbursementStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Attendance reports for payroll.
 *
 * The rule worth pinning hardest is that LEAVE IS NEVER FOLDED INTO PAYABLE
 * DAYS. Whether a given leave is paid is a company's own policy, and a report
 * that quietly assumes one produces a number that looks authoritative and is
 * wrong for half its readers.
 */
class AttendanceReportTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceReportService $svc;
    private ?Tenant $t = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(AttendanceReportService::class);
    }

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'T', 'slug' => 'rep-t', 'status' => 'active']);
    }

    private function employee(string $code, string $dept = 'Ops'): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'employee_code' => $code,
            'name' => 'E' . $code, 'department' => $dept, 'designation' => 'Analyst',
            'joining_date' => '2026-01-01', 'status' => 'Active',
        ]);
    }

    private function day(HrEmployee $e, string $date, string $status, float $hours = 8, float $ot = 0): void
    {
        HrAttendance::create([
            'tenant_id' => $e->tenant_id, 'employee_id' => $e->id, 'date' => $date,
            'status' => $status, 'working_hours' => $hours, 'overtime_hours' => $ot,
        ]);
    }

    /* ── the counting ────────────────────────────────────────────────── */

    public function test_it_counts_days_and_hours_for_a_month(): void
    {
        $e = $this->employee('SNE-1');

        $this->day($e, '2026-03-02', 'Present', 8);
        $this->day($e, '2026-03-03', 'Present', 8, 2);
        $this->day($e, '2026-03-04', 'Late', 7);
        $this->day($e, '2026-03-05', 'Half Day', 4);
        $this->day($e, '2026-03-06', 'Absent', 0);

        $row = $this->svc->monthly($this->tenant()->id, '2026-03')['rows'][0];

        $this->assertSame(3, $row['present_days'], 'Present and Late are both full days worked.');
        $this->assertSame(1, $row['half_days']);
        $this->assertSame(1, $row['absent_days']);
        $this->assertSame(1, $row['late_days']);
        $this->assertSame(3.5, $row['payable_days']);
        $this->assertSame(27.0, $row['working_hours']);
        $this->assertSame(2.0, $row['overtime_hours']);
    }

    /** The rule this report exists to keep honest. */
    public function test_leave_is_reported_but_never_counted_as_payable(): void
    {
        $e = $this->employee('SNE-1');

        $this->day($e, '2026-03-02', 'Present', 8);
        $this->day($e, '2026-03-03', 'Leave', 0);
        $this->day($e, '2026-03-04', 'Leave', 0);

        $row = $this->svc->monthly($this->tenant()->id, '2026-03')['rows'][0];

        $this->assertSame(2, $row['leave_days'], 'Leave must be visible.');
        $this->assertSame(1.0, $row['payable_days'], 'Leave must NOT be folded into pay — that is a policy call.');
    }

    public function test_holidays_and_weekends_are_neither_earned_nor_lost(): void
    {
        $e = $this->employee('SNE-1');

        $this->day($e, '2026-03-02', 'Present', 8);
        $this->day($e, '2026-03-07', 'Weekend', 0);
        $this->day($e, '2026-03-08', 'Holiday', 0);

        $row = $this->svc->monthly($this->tenant()->id, '2026-03')['rows'][0];

        $this->assertSame(2, $row['non_working']);
        $this->assertSame(1.0, $row['payable_days']);
    }

    public function test_another_month_is_not_included(): void
    {
        $e = $this->employee('SNE-1');

        $this->day($e, '2026-03-31', 'Present', 8);
        $this->day($e, '2026-04-01', 'Present', 8);

        $march = $this->svc->monthly($this->tenant()->id, '2026-03')['rows'][0];

        $this->assertSame(1, $march['present_days'], 'April must not leak into March.');
    }

    public function test_another_tenants_attendance_is_not_counted(): void
    {
        $e = $this->employee('SNE-1');
        $this->day($e, '2026-03-02', 'Present', 8);

        $other = Tenant::create(['name' => 'O', 'slug' => 'rep-o', 'status' => 'active']);
        $theirs = HrEmployee::create([
            'tenant_id' => $other->id, 'employee_code' => 'X-1', 'name' => 'X',
            'department' => 'Ops', 'designation' => 'A', 'joining_date' => '2026-01-01', 'status' => 'Active',
        ]);
        HrAttendance::create([
            'tenant_id' => $other->id, 'employee_id' => $theirs->id, 'date' => '2026-03-02',
            'status' => 'Present', 'working_hours' => 8, 'overtime_hours' => 0,
        ]);

        $out = $this->svc->monthly($this->tenant()->id, '2026-03');

        $this->assertCount(1, $out['rows']);
        $this->assertSame(8.0, $out['totals']['working_hours']);
    }

    /* ── the money that changes what somebody is paid ────────────────── */

    public function test_it_carries_approved_claims_and_outstanding_advances(): void
    {
        $e = $this->employee('SNE-1');
        $this->day($e, '2026-03-02', 'Present', 8);

        HrReimbursement::create([
            'tenant_id' => $e->tenant_id, 'employee_id' => $e->id, 'title' => 'Dinner',
            'expense_date' => '2026-03-01', 'amount_claimed' => 5000, 'amount_approved' => 4000,
            'status' => ReimbursementStatus::APPROVED, 'decided_at' => '2026-03-20 10:00:00',
        ]);

        HrAdvance::create([
            'tenant_id' => $e->tenant_id, 'employee_id' => $e->id, 'reference' => 'ADV-1',
            'purpose' => 'Site', 'amount_requested' => 20000, 'amount_approved' => 20000,
            'status' => AdvanceStage::DISBURSED, 'disbursed_amount' => 20000,
        ]);

        $row = $this->svc->monthly($this->tenant()->id, '2026-03')['rows'][0];

        $this->assertSame(4000.0, $row['reimbursements_approved']);
        $this->assertSame(20000.0, $row['advance_outstanding']);
    }

    /**
     * Payroll pays what was signed off in the period, so a claim is counted by
     * when it was DECIDED, not when the money was spent.
     */
    public function test_a_claim_decided_in_another_month_is_not_this_months_money(): void
    {
        $e = $this->employee('SNE-1');

        HrReimbursement::create([
            'tenant_id' => $e->tenant_id, 'employee_id' => $e->id, 'title' => 'March dinner',
            'expense_date' => '2026-03-01', 'amount_claimed' => 5000, 'amount_approved' => 5000,
            'status' => ReimbursementStatus::APPROVED, 'decided_at' => '2026-05-02 10:00:00',
        ]);

        $march = $this->svc->monthly($this->tenant()->id, '2026-03')['rows'][0];
        $may   = $this->svc->monthly($this->tenant()->id, '2026-05')['rows'][0];

        $this->assertSame(0.0, $march['reimbursements_approved']);
        $this->assertSame(5000.0, $may['reimbursements_approved'], 'It is May\'s money.');
    }

    public function test_a_settled_advance_is_no_longer_outstanding(): void
    {
        $e = $this->employee('SNE-1');

        HrAdvance::create([
            'tenant_id' => $e->tenant_id, 'employee_id' => $e->id, 'reference' => 'ADV-1',
            'purpose' => 'Site', 'amount_requested' => 20000, 'status' => AdvanceStage::SETTLED,
            'disbursed_amount' => 20000,
        ]);

        $row = $this->svc->monthly($this->tenant()->id, '2026-03')['rows'][0];

        $this->assertSame(0.0, $row['advance_outstanding']);
    }

    /* ── shapes ──────────────────────────────────────────────────────── */

    public function test_departments_roll_up(): void
    {
        $ops   = $this->employee('SNE-1', 'Ops');
        $sales = $this->employee('SNE-2', 'Sales');

        $this->day($ops, '2026-03-02', 'Present', 8);
        $this->day($sales, '2026-03-02', 'Present', 8);
        $this->day($sales, '2026-03-03', 'Present', 8);

        $rows = collect($this->svc->byDepartment($this->tenant()->id, '2026-03')['rows'])
            ->keyBy('department');

        $this->assertSame(1, $rows['Ops']['headcount']);
        $this->assertSame(2, $rows['Sales']['present_days']);
    }

    public function test_one_employee_returns_the_days_behind_the_figure(): void
    {
        $e = $this->employee('SNE-1');
        $this->day($e, '2026-03-02', 'Present', 8);
        $this->day($e, '2026-03-03', 'Absent', 0);

        $out = $this->svc->forEmployee($this->tenant()->id, $e->id, '2026-03');

        $this->assertCount(2, $out['days'], 'Every recorded day, so a wrong total can be traced.');
        $this->assertSame(1.0, $out['summary']['payable_days']);
    }

    public function test_an_employee_with_no_attendance_still_appears(): void
    {
        $this->employee('SNE-1');

        $rows = $this->svc->monthly($this->tenant()->id, '2026-03')['rows'];

        // Somebody with nothing recorded is exactly who payroll needs to notice.
        $this->assertCount(1, $rows);
        $this->assertSame(0, $rows[0]['days_recorded']);
        $this->assertSame(0.0, $rows[0]['payable_days']);
    }

    /* ── over HTTP ───────────────────────────────────────────────────── */

    public function test_the_endpoints_are_gated_and_work(): void
    {
        $e = $this->employee('SNE-1');
        $this->day($e, '2026-03-02', 'Present', 8);

        $staff = User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'S', 'email' => 's@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);
        $admin = User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'A', 'email' => 'a@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'admin', 'status' => 'active',
        ]);

        Sanctum::actingAs($staff);
        $this->getJson('/api/hr/reports/attendance?month=2026-03')->assertStatus(403);

        Sanctum::actingAs($admin);
        $this->getJson('/api/hr/reports/attendance?month=2026-03')
            ->assertOk()
            ->assertJsonPath('data.rows.0.present_days', 1);

        // 'departments' must not be read as an employee id.
        $this->getJson('/api/hr/reports/attendance/departments?month=2026-03')
            ->assertOk()
            ->assertJsonPath('data.rows.0.department', 'Ops');

        $this->getJson("/api/hr/reports/attendance/{$e->id}?month=2026-03")
            ->assertOk()
            ->assertJsonCount(1, 'data.days');

        $this->getJson('/api/hr/reports/attendance?month=nonsense')->assertStatus(422);
    }
}
