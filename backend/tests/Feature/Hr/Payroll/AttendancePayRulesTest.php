<?php

namespace Tests\Feature\Hr\Payroll;

use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeDetail;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrPayrollRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Settings\SettingsService;
use App\Support\Hr\HrSetting;
use App\Support\Hr\WorkStates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The three rules that were captured and never applied.
 *
 * Late marks, overtime and the probation leave gate were each described by HR,
 * each given somewhere to live, and each read by nothing:
 *
 *   - `late_marks_*` sat in the settings registry with a comment admitting
 *     "Nothing enforces these yet"
 *   - `hr_attendance.overtime_hours` was stamped on every day and never reached
 *     a payslip
 *   - `probation_allowed` was stored on the leave policy, validated, cast, and
 *     consulted by no code in the leave path
 *
 * A control that claims to do something and does not is worse than no control:
 * it is trusted. So what is asserted here is that switching each one ON changes
 * money or refuses an application, and that leaving it OFF changes nothing —
 * because these ship into workspaces that have been recording late marks and
 * overtime for months with no consequence, and turning them on silently would
 * take pay off people who were never told the rule had started.
 */
class AttendancePayRulesTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;

    private HrPayrollRun $run;

    private const PERIOD = ['year' => 2026, 'month' => 7];

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'pay-rules', 'status' => 'active']);
        $this->tenantId = $tenant->id;

        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'HR', 'email' => 'hr@rules.test',
            'password' => Hash::make('x'), 'role' => 'admin', 'status' => 'active',
        ]);

        $this->run = HrPayrollRun::create([
            'tenant_id' => $tenant->id, 'payroll_month' => 7, 'payroll_year' => 2026,
            'status' => HrPayrollRun::DRAFT,
        ]);

        Sanctum::actingAs($admin);
    }

    private function hr(array $values): void
    {
        app(SettingsService::class)->setGroup($this->tenantId, HrSetting::GROUP, $values);
    }

    /** Gross 31,000 over 31 payable days = a clean ₹1,000 a day. */
    private function person(string $code, float $gross = 31000): HrEmployee
    {
        $e = HrEmployee::create([
            'tenant_id' => $this->tenantId, 'employee_code' => $code, 'name' => "Person {$code}",
            'department' => 'Ops', 'designation' => 'Staff',
            'joining_date' => '2020-01-01', 'status' => 'Active',
        ]);

        HrEmployeeDetail::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $e->id,
            'bank_account_number' => '0012345678', 'bank_ifsc' => 'HDFC0001234',
            'pay_mode' => 'Transfer', 'pan_number' => 'ABCDE1234F', 'aadhaar_number' => '111122223333',
        ]);

        HrEmployeeSalary::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $e->id,
            'effective_from' => '2020-01-01',
            'annual_ctc' => $gross * 12, 'monthly_ctc' => $gross,
            'gross_salary' => $gross, 'total_benefits' => 0, 'total_deductions' => 0,
            'net_salary' => $gross, 'status' => HrEmployeeSalary::ACTIVE,
        ]);

        return $e;
    }

    /**
     * A FULL month of attendance, with the first `$days` carrying `$status`.
     *
     * Writing only the interesting days would leave payable_days at 3 instead of
     * 31, and a day's pay is gross ÷ payable days — so a three-row fixture makes
     * a "half day" worth ₹5,166 and every expectation below meaningless. The
     * fixture has to look like a month because the arithmetic depends on it.
     */
    private function attendance(HrEmployee $e, int $days, string $status, float $overtime = 0): void
    {
        for ($d = 1; $d <= 31; $d++) {
            HrAttendance::create([
                'tenant_id' => $this->tenantId, 'employee_id' => $e->id,
                'date' => sprintf('2026-07-%02d', $d),
                'status' => $d <= $days ? $status : 'Present',
                'overtime_hours' => $d <= $days ? $overtime : 0,
            ]);
        }
    }

    private function process(): void
    {
        $this->postJson("/api/hr/payroll/runs/{$this->run->id}/process")->assertOk();
    }

    private function record(HrEmployee $e): HrPayrollRecord
    {
        return HrPayrollRecord::where('payroll_run_id', $this->run->id)
            ->where('employee_id', $e->id)->firstOrFail();
    }

    /* ── Late marks ───────────────────────────────────────────────────── */

    public function test_late_marks_cost_nothing_while_the_policy_is_off(): void
    {
        $e = $this->person('LM1');
        $this->attendance($e, 6, 'Late');

        $this->process();
        $r = $this->record($e);

        $this->assertEquals(0, $r->late_mark_deduction, 'a workspace that never opted in must not lose pay');
        $this->assertEquals(31000, $r->netPayable());
    }

    /** Three late marks reaches the first threshold: half a day. */
    public function test_the_third_late_mark_costs_half_a_day(): void
    {
        $this->hr(['late_marks_enabled' => true]);

        $e = $this->person('LM2');
        $this->attendance($e, 3, 'Late');

        $this->process();
        $r = $this->record($e);

        $this->assertSame(3, (int) $r->late_marks);
        $this->assertEquals(500, $r->late_mark_deduction, '31000 / 31 days = 1000 a day, half of it');
        $this->assertEquals(30500, $r->netPayable());
    }

    /**
     * The thresholds are cumulative, not exclusive.
     *
     * Five late marks crosses BOTH, so it costs a full day — "थ्री लेट आफ्टर
     * थ्री हाफ डे कट, फिफ्थ लेट मार्क्स के लिए सेकंड हाफ डे" describes a further
     * deduction, not a replacement for the first.
     */
    public function test_the_fifth_late_mark_adds_to_the_third_rather_than_replacing_it(): void
    {
        $this->hr(['late_marks_enabled' => true]);

        $e = $this->person('LM3');
        $this->attendance($e, 5, 'Late');

        $this->process();

        $this->assertEquals(1000, $this->record($e)->late_mark_deduction, '0.5 + 0.5 = one full day');
    }

    public function test_two_late_marks_is_under_the_threshold_and_costs_nothing(): void
    {
        $this->hr(['late_marks_enabled' => true]);

        $e = $this->person('LM4');
        $this->attendance($e, 2, 'Late');

        $this->process();

        $this->assertEquals(0, $this->record($e)->late_mark_deduction);
    }

    /** HR can move the thresholds without a release — that is why they are settings. */
    public function test_the_thresholds_are_the_workspaces_to_change(): void
    {
        $this->hr([
            'late_marks_enabled' => true,
            'late_marks_first_penalty_at' => 2,
            'late_marks_first_penalty_days' => 1,
            'late_marks_second_penalty_at' => 0,   // help text: 0 turns it off
        ]);

        $e = $this->person('LM5');
        $this->attendance($e, 6, 'Late');

        $this->process();

        $this->assertEquals(1000, $this->record($e)->late_mark_deduction,
            'one full day at the second late mark, and no second threshold');
    }

    /** The reason travels with the money so a payslip can explain itself. */
    public function test_the_deduction_carries_its_reason(): void
    {
        $this->hr(['late_marks_enabled' => true]);

        $e = $this->person('LM6');
        $this->attendance($e, 3, 'Late');
        $this->process();

        $this->assertStringContainsString('3 late marks', $this->record($e)->late_mark_reason);
    }

    /* ── Overtime ─────────────────────────────────────────────────────── */

    public function test_overtime_hours_are_recorded_but_unpaid_while_the_policy_is_off(): void
    {
        $e = $this->person('OT1');
        $this->attendance($e, 4, 'Present', 2);

        $this->process();

        $this->assertEquals(0, $this->record($e)->overtime_amount);
    }

    public function test_overtime_pays_at_the_configured_multiple(): void
    {
        $this->hr(['overtime_enabled' => true, 'overtime_multiplier' => 2, 'standard_day_hours' => 8]);

        $e = $this->person('OT2');
        $this->attendance($e, 4, 'Present', 2);   // 8 hours

        $this->process();
        $r = $this->record($e);

        // 31000 / (31 days × 8 h) = 125/h; doubled = 250/h; × 8 h = 2000
        $this->assertEquals(8, $r->overtime_hours);
        $this->assertEquals(2000, $r->overtime_amount);
        $this->assertEquals(33000, $r->netPayable(), 'overtime is earned, so it goes ON the transfer');
    }

    /**
     * The cap applies per day, not to the month.
     *
     * A monthly cap lets one twelve-hour Sunday through untouched while trimming
     * a month of ordinary half-hours.
     */
    public function test_the_overtime_cap_is_applied_to_each_day(): void
    {
        $this->hr([
            'overtime_enabled' => true, 'overtime_multiplier' => 1,
            'standard_day_hours' => 8, 'overtime_daily_cap_hours' => 3,
        ]);

        $e = $this->person('OT3');
        $this->attendance($e, 2, 'Present', 6);   // 12 raw hours, 6 payable

        $this->process();

        $this->assertEquals(6, $this->record($e)->overtime_hours, 'capped at 3 a day, twice');
    }

    /* ── Probation gate on leave ──────────────────────────────────────── */

    /**
     * A leave type, policy and balance for one employee.
     *
     * `probation_allowed` defaults to false here, matching the policy screen.
     */
    private function leaveSetup(HrEmployee $e, bool $probationAllowed = false): int
    {
        $type = \App\Models\Hr\HrLeaveType::create([
            'tenant_id' => $this->tenantId, 'name' => 'Casual '.$e->employee_code,
            'code' => 'CL'.$e->id, 'category' => 'Paid', 'paid' => true,
            'yearly_limit' => 24, 'requires_approval' => true, 'is_active' => true,
        ]);
        $policy = \App\Models\Hr\HrLeavePolicy::create([
            'tenant_id' => $this->tenantId, 'name' => 'Standard '.$e->employee_code,
            'applies_to' => 'All', 'weekends_count' => true, 'holidays_count' => false,
            'half_day_allowed' => true, 'negative_balance_allowed' => false,
            'probation_allowed' => $probationAllowed, 'is_active' => true,
        ]);
        \App\Models\Hr\HrEmployeeLeaveBalance::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $e->id,
            'leave_policy_id' => $policy->id, 'leave_type_id' => $type->id,
            'allocated' => 24, 'opening_balance' => 24, 'used' => 0, 'adjusted' => 0,
            'carried_forward' => 0, 'available_balance' => 24,
            'effective_from' => '2026-01-01', 'status' => \App\Models\Hr\HrEmployeeLeaveBalance::ACTIVE,
        ]);

        return $type->id;
    }

    private function probationer(string $code, ?string $probationEnd = '2026-12-01'): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenantId, 'employee_code' => $code, 'name' => 'Joiner '.$code,
            'department' => 'Ops', 'designation' => 'Staff', 'status' => 'Active',
            'joining_date' => '2026-06-01', 'probation_end_date' => $probationEnd,
        ]);
    }

    private function applyLeave(HrEmployee $e, int $typeId, string $from, string $to)
    {
        return $this->postJson('/api/hr/leave/applications', [
            'employee_id' => $e->id, 'leave_type_id' => $typeId,
            'from_date' => $from, 'to_date' => $to,
        ]);
    }

    public function test_a_probationer_cannot_take_leave(): void
    {
        $e = $this->probationer('PB1');
        $typeId = $this->leaveSetup($e);

        $this->applyLeave($e, $typeId, '2026-07-10', '2026-07-11')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Leave is available after probation ends on 01 Dec 2026. Leave taken before then is unpaid and comes off the salary.']);
    }

    /** After the probation end date the same application goes through. */
    public function test_leave_after_probation_is_allowed(): void
    {
        $e = $this->probationer('PB2');
        $typeId = $this->leaveSetup($e);

        $this->applyLeave($e, $typeId, '2026-12-10', '2026-12-11')->assertSuccessful();
    }

    /**
     * Applying NOW for leave that falls after confirmation is allowed.
     *
     * The gate reads the leave's own dates, not today's — somebody two days from
     * confirmation is asking for something they will be entitled to, and making
     * them wait and reapply serves nobody.
     */
    public function test_the_gate_reads_the_leave_dates_not_todays_date(): void
    {
        $e = $this->probationer('PB3', '2026-07-15');
        $typeId = $this->leaveSetup($e);

        $this->applyLeave($e, $typeId, '2026-07-20', '2026-07-21')->assertSuccessful();
    }

    /** Confirmed early beats the original probation end date. */
    public function test_early_confirmation_lifts_the_gate(): void
    {
        $e = $this->probationer('PB4');
        $e->update(['confirmation_date' => '2026-07-01']);
        $typeId = $this->leaveSetup($e);

        $this->applyLeave($e, $typeId, '2026-07-10', '2026-07-11')->assertSuccessful();
    }

    /** A policy that permits probation leave still permits it. */
    public function test_a_policy_can_allow_leave_during_probation(): void
    {
        $e = $this->probationer('PB5');
        $typeId = $this->leaveSetup($e, probationAllowed: true);

        $this->applyLeave($e, $typeId, '2026-07-10', '2026-07-11')->assertSuccessful();
    }

    /** And the workspace can switch the whole rule off. */
    public function test_the_probation_gate_can_be_switched_off_entirely(): void
    {
        app(SettingsService::class)->set($this->tenantId, 'payroll', 'probation_blocks_leave', false);

        $e = $this->probationer('PB6');
        $typeId = $this->leaveSetup($e);

        $this->applyLeave($e, $typeId, '2026-07-10', '2026-07-11')->assertSuccessful();
    }

    /** Nobody recorded a probation period — that is not an endless probation. */
    public function test_an_employee_with_no_probation_date_is_not_blocked(): void
    {
        $e = $this->probationer('PB7', probationEnd: null);
        $typeId = $this->leaveSetup($e);

        $this->applyLeave($e, $typeId, '2026-07-10', '2026-07-11')->assertSuccessful();
    }

    /* ── PT state list ────────────────────────────────────────────────── */

    public function test_only_pt_levying_states_are_offered(): void
    {
        $meta = $this->getJson('/api/hr/payroll/statutory/meta')->assertOk()->json();

        $pt = array_column($meta['pt_states'], 'name');

        $this->assertContains('Maharashtra', $pt);
        $this->assertContains('Karnataka', $pt);
        // Called out on screen during the 3 Sep review.
        $this->assertNotContains('Delhi', $pt);
        $this->assertNotContains('Haryana', $pt);
        // The unfiltered vocabulary is still served for the rules that are not
        // state-levied, so nothing else loses its options.
        $this->assertContains('Delhi', array_column($meta['work_states'], 'name'));
    }

    /** The list is the workspace's, not ours — a budget can change it. */
    public function test_the_pt_state_list_can_be_changed_from_settings(): void
    {
        app(SettingsService::class)->set($this->tenantId, 'payroll', 'pt_states', ['MH', 'KA']);

        $pt = array_column(
            $this->getJson('/api/hr/payroll/statutory/meta')->assertOk()->json('pt_states'),
            'name'
        );

        $this->assertSame(['Karnataka', 'Maharashtra'], $pt);
    }

    public function test_the_seeded_default_excludes_the_states_that_levy_nothing(): void
    {
        $names = array_column(WorkStates::ptOptions(), 'name');

        $this->assertCount(22, $names);
        foreach (['Delhi', 'Haryana', 'Uttar Pradesh', 'Rajasthan', 'Goa'] as $none) {
            $this->assertNotContains($none, $names);
        }
    }
}
