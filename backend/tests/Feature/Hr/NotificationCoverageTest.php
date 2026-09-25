<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLoan;
use App\Models\Hr\HrLoanType;
use App\Models\Notifications\HrNotification;
use App\Models\Notifications\HrNotificationTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\EmployeeMovementService;
use App\Services\Hr\LoanService;
use App\Services\Notifications\Reminders\LoanApprovalPendingSource;
use App\Services\Notifications\Reminders\PayrollApprovalPendingSource;
use App\Services\Notifications\Reminders\ReminderSourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The four modules the notification engine never covered.
 *
 * The engine itself was complete — templates, rules, queue, reminders,
 * escalation, five channels and a full UI — and config/hr_notifications.php
 * registered twelve modules. Payroll, Loans, Onboarding and employee lifecycle
 * were not among them, which meant:
 *
 *   a payroll run was approved, disbursed and its payslips published without
 *   telling a single person, so an employee learned they had been paid by
 *   opening the app and looking;
 *
 *   somebody borrowed money from the company and heard nothing when it was
 *   approved, refused or paid out;
 *
 *   onboarding notified only by raw e-mail, outside the engine entirely — no
 *   bell, no per-tenant template, no rule, no channel preference;
 *
 *   a promotion was recorded and the person promoted was not told.
 *
 * The rule for what got registered: somebody is blocked, owed money, or
 * personally affected. Status changes nobody acts on were left alone, and one
 * of the tests below pins that so the list does not creep.
 */
class NotificationCoverageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'notif-cov', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function user(string $email = null, string $role = 'admin'): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'U',
            'email' => $email ?: uniqid().'@notif.test',
            'password' => Hash::make('Password123!'), 'role' => $role, 'status' => 'active',
        ]);
    }

    private function employee(?User $user = null, string $dept = 'Ops'): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'E'.substr(uniqid(), -5),
            'employee_code' => 'E'.substr(uniqid(), -6),
            'department' => $dept, 'designation' => 'Analyst',
            'joining_date' => '2024-01-01', 'status' => 'Active', 'user_id' => $user?->id,
        ]);
    }

    private function loanType(bool $requiresApproval = true): HrLoanType
    {
        return HrLoanType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Personal', 'code' => 'PER'.rand(10, 99),
            'interest_rate' => 0, 'max_tenure_months' => 12, 'is_active' => true,
            'requires_approval' => $requiresApproval,
        ]);
    }

    private function loan(HrEmployee $employee, string $status = HrEmployeeLoan::DRAFT, ?HrLoanType $type = null): HrEmployeeLoan
    {
        return HrEmployeeLoan::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'loan_type_id' => ($type ?: $this->loanType())->id,
            'principal' => 50000, 'interest_rate' => 0, 'tenure_months' => 10,
            'emi' => 5000, 'status' => $status,
        ]);
    }

    private function loans(): LoanService
    {
        return app(LoanService::class);
    }

    /** Notifications raised for one module/event, whatever the recipient. */
    private function raised(string $module, string $event)
    {
        return HrNotification::where('module', $module)->where('event', $event)->get();
    }

    /* ── 1. Loans — the module that notified nobody at all ────────────── */

    public function test_submitting_a_loan_tells_the_hr_queue(): void
    {
        $actor = $this->user();
        $employee = $this->employee($this->user());
        $loan = $this->loan($employee);

        $this->loans()->submit($loan->id, $this->tenant->id, $actor);

        $rows = $this->raised('Loan', 'Applied');
        $this->assertCount(1, $rows);
        // A queue item names the role, not a person — there is no single
        // address behind "whoever is on the HR queue".
        $this->assertSame('hr', $rows->first()->recipient_role);
        $this->assertNull($rows->first()->recipient_user_id);
    }

    public function test_approving_a_loan_tells_the_borrower_by_id(): void
    {
        $actor = $this->user();
        $borrower = $this->user();
        $employee = $this->employee($borrower);
        $loan = $this->loan($employee, HrEmployeeLoan::SUBMITTED);

        $this->loans()->approve($loan->id, $this->tenant->id, $actor);

        $rows = $this->raised('Loan', 'Approved');
        $this->assertCount(1, $rows);
        // The actual recipient id, not merely "a notification happened".
        $this->assertSame($borrower->id, $rows->first()->recipient_user_id);
    }

    public function test_rejecting_a_loan_tells_the_borrower_with_the_reason(): void
    {
        $actor = $this->user();
        $borrower = $this->user();
        $employee = $this->employee($borrower);
        $loan = $this->loan($employee, HrEmployeeLoan::SUBMITTED);

        $this->loans()->reject($loan->id, 'Outstanding balance too high.', $this->tenant->id, $actor);

        $rows = $this->raised('Loan', 'Rejected');
        $this->assertCount(1, $rows);
        $this->assertSame($borrower->id, $rows->first()->recipient_user_id);
        $this->assertStringContainsString('Outstanding balance too high.', $rows->first()->message);
    }

    public function test_disbursing_a_loan_tells_the_borrower(): void
    {
        $actor = $this->user();
        $borrower = $this->user();
        $employee = $this->employee($borrower);
        $loan = $this->loan($employee, HrEmployeeLoan::APPROVED);

        $this->loans()->disburse($loan->id, ['disbursed_on' => '2026-09-01'], $this->tenant->id, $actor);

        $rows = $this->raised('Loan', 'Disbursed');
        $this->assertCount(1, $rows);
        $this->assertSame($borrower->id, $rows->first()->recipient_user_id);
    }

    public function test_a_loan_needing_no_approval_tells_the_borrower_it_is_approved(): void
    {
        $actor = $this->user();
        $borrower = $this->user();
        $employee = $this->employee($borrower);
        $loan = $this->loan($employee, HrEmployeeLoan::DRAFT, $this->loanType(requiresApproval: false));

        $this->loans()->submit($loan->id, $this->tenant->id, $actor);

        // It went straight to Approved, so saying "applied, awaiting approval"
        // would be untrue — nobody is going to approve it.
        $this->assertCount(0, $this->raised('Loan', 'Applied'));
        $this->assertCount(1, $this->raised('Loan', 'Approved'));
    }

    public function test_closing_a_loan_stays_silent(): void
    {
        $actor = $this->user();
        $employee = $this->employee($this->user());
        $loan = $this->loan($employee, HrEmployeeLoan::APPROVED);
        $this->loans()->disburse($loan->id, ['disbursed_on' => '2026-09-01'], $this->tenant->id, $actor);

        $before = HrNotification::count();
        $this->loans()->close($loan->id, 'Repaid early.', $this->tenant->id, $actor);

        // Closure is bookkeeping the employee reads on a statement, not a
        // moment they are waiting on. Pinned so the event list cannot creep.
        $this->assertSame($before, HrNotification::count());
    }

    /* ── 1b. Payroll — the module that moved the most money in silence ── */

    private function payrollRun(string $stage = 'Approve'): \App\Models\Hr\HrPayrollRun
    {
        return \App\Models\Hr\HrPayrollRun::create([
            'tenant_id' => $this->tenant->id, 'payroll_month' => 9, 'payroll_year' => 2026,
            'status' => \App\Models\Hr\HrPayrollRun::COMPLETED, 'stage' => $stage,
            'total_employees' => 1, 'total_payable' => 50000, 'total_net' => 50000,
        ]);
    }

    private function payrollRecord(\App\Models\Hr\HrPayrollRun $run, HrEmployee $employee): \App\Models\Hr\HrPayrollRecord
    {
        return \App\Models\Hr\HrPayrollRecord::create([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'gross_salary' => 50000, 'total_deductions' => 0, 'net_salary' => 50000,
            'status' => \App\Models\Hr\HrPayrollRecord::PROCESSED,
        ]);
    }

    public function test_approving_a_payroll_run_tells_the_hr_queue(): void
    {
        $admin = $this->user();
        $run = $this->payrollRun();

        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $this->postJson("/api/hr/payroll/runs/{$run->id}/approve", ['note' => 'signed'])->assertOk();

        $rows = $this->raised('Payroll', 'Run Approved');
        $this->assertCount(1, $rows);
        $this->assertSame('hr', $rows->first()->recipient_role);
        // The period is what makes the message readable at a glance.
        $this->assertStringContainsString('September 2026', $rows->first()->title);
    }

    public function test_rejecting_a_payroll_run_tells_the_hr_queue_with_the_reason(): void
    {
        $admin = $this->user();
        $run = $this->payrollRun();

        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $this->postJson("/api/hr/payroll/runs/{$run->id}/reject", ['note' => 'recheck the overtime'])->assertOk();

        $rows = $this->raised('Payroll', 'Run Rejected');
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('recheck the overtime', $rows->first()->message);
    }

    public function test_releasing_payslips_tells_each_employee_by_id(): void
    {
        $admin = $this->user();
        $owner = $this->user();
        $employee = $this->employee($owner);
        $run = $this->payrollRun('Disburse');
        $this->payrollRecord($run, $employee);

        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $this->postJson("/api/hr/payroll/runs/{$run->id}/release-payslips", ['visible' => true])->assertOk();

        $rows = $this->raised('Payroll', 'Payslip Released');
        $this->assertCount(1, $rows);
        $this->assertSame($owner->id, $rows->first()->recipient_user_id);
    }

    public function test_hiding_payslips_tells_nobody(): void
    {
        $admin = $this->user();
        $employee = $this->employee($this->user());
        $run = $this->payrollRun('Disburse');
        $this->payrollRecord($run, $employee);

        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $this->postJson("/api/hr/payroll/runs/{$run->id}/release-payslips", ['visible' => false])->assertOk();

        // Withdrawing access is not news anybody wants in their bell.
        $this->assertCount(0, $this->raised('Payroll', 'Payslip Released'));
    }

    public function test_marking_a_payment_paid_tells_that_employee(): void
    {
        $admin = $this->user();
        $owner = $this->user();
        $employee = $this->employee($owner);
        $run = $this->payrollRun('Disburse');
        $record = $this->payrollRecord($run, $employee);

        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $this->postJson("/api/hr/payroll/records/{$record->id}/payment", ['payment_status' => 'Paid'])->assertOk();

        $rows = $this->raised('Payroll', 'Salary Paid');
        $this->assertCount(1, $rows);
        $this->assertSame($owner->id, $rows->first()->recipient_user_id);
    }

    public function test_marking_a_payment_failed_tells_nobody(): void
    {
        $admin = $this->user();
        $employee = $this->employee($this->user());
        $run = $this->payrollRun('Disburse');
        $record = $this->payrollRecord($run, $employee);

        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $this->postJson("/api/hr/payroll/records/{$record->id}/payment", ['payment_status' => 'Failed'])->assertOk();

        // Only money that actually landed is announced — a failed transfer is
        // for the operator to fix, not for the employee to be alarmed by.
        $this->assertCount(0, $this->raised('Payroll', 'Salary Paid'));
    }

    /* ── 2. Lifecycle — a promotion nobody was told about ─────────────── */

    public function test_a_movement_tells_the_employee_it_happened_to(): void
    {
        $actor = $this->user();
        $subject = $this->user();
        $employee = $this->employee($subject);

        // Something must actually move, or the service refuses the record.
        $to = \App\Models\Hr\HrDesignation::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Senior Analyst', 'is_active' => true,
        ]);

        $movement = app(EmployeeMovementService::class)->move([
            'employee_id'       => $employee->id,
            'to_designation_id' => $to->id,
            'effective_date'    => '2026-10-01',
            'reason'            => 'Strong year.',
        ], $this->tenant->id, $actor);

        $rows = $this->raised('Lifecycle', 'Movement Recorded');
        $this->assertCount(1, $rows);
        $this->assertSame($subject->id, $rows->first()->recipient_user_id);

        // One event covers all four movement types, and {{type}} carries the
        // word the SERVICE derived — not one the caller asked for. A caller
        // claiming 'Promotion' on a designation-only change would otherwise
        // have the notification say something the record does not.
        $this->assertStringContainsString($movement['movement_type'], $rows->first()->title);
        $this->assertStringContainsString('Redesignation', $rows->first()->title);
    }

    /* ── 3. Employees with no login ───────────────────────────────────── */

    public function test_an_employee_without_a_login_is_skipped_not_crashed(): void
    {
        $actor = $this->user();
        $employee = $this->employee(null);          // on the payroll, not on the app
        $loan = $this->loan($employee, HrEmployeeLoan::SUBMITTED);

        // Must not throw, and must not invent a recipient — sending somebody
        // else's loan approval to a stand-in would be worse than silence.
        $this->loans()->approve($loan->id, $this->tenant->id, $actor);

        $this->assertSame(HrEmployeeLoan::APPROVED, $loan->fresh()->status);
        $this->assertCount(0, $this->raised('Loan', 'Approved'));
    }

    /* ── 4. A failed notification never costs the business write ──────── */

    public function test_a_notification_failure_does_not_roll_back_the_approval(): void
    {
        $actor = $this->user();
        $employee = $this->employee($this->user());
        $loan = $this->loan($employee, HrEmployeeLoan::SUBMITTED);

        // A real failure: with the table gone every insert throws. The loan
        // being approved is the important half — losing it because a bell
        // could not be rung would be a far worse defect.
        \Schema::drop('hr_notifications');

        $this->loans()->approve($loan->id, $this->tenant->id, $actor);

        $this->assertSame(HrEmployeeLoan::APPROVED, $loan->fresh()->status);
    }

    public function test_a_notification_failure_does_not_roll_back_a_disbursement(): void
    {
        $actor = $this->user();
        $employee = $this->employee($this->user());
        $loan = $this->loan($employee, HrEmployeeLoan::APPROVED);

        \Schema::drop('hr_notifications');

        $this->loans()->disburse($loan->id, ['disbursed_on' => '2026-09-01'], $this->tenant->id, $actor);

        $fresh = $loan->fresh();
        $this->assertSame(HrEmployeeLoan::DISBURSED, $fresh->status);
        // The schedule is the part that must survive: it is written once.
        $this->assertGreaterThan(0, $fresh->installments()->count());
    }

    /* ── 5. Tenant isolation ──────────────────────────────────────────── */

    public function test_a_notification_belongs_to_its_own_tenant(): void
    {
        $actor = $this->user();
        $borrower = $this->user();
        $employee = $this->employee($borrower);
        $loan = $this->loan($employee, HrEmployeeLoan::SUBMITTED);

        $other = Tenant::create(['name' => 'Other', 'slug' => 'notif-other', 'status' => 'active']);

        $this->loans()->approve($loan->id, $this->tenant->id, $actor);

        $row = $this->raised('Loan', 'Approved')->first();
        $this->assertSame($this->tenant->id, (int) $row->tenant_id);
        $this->assertSame(0, HrNotification::where('tenant_id', $other->id)->count());
    }

    /* ── 6. Per-tenant templates still win over the config default ────── */

    public function test_a_tenant_template_overrides_the_registered_default(): void
    {
        HrNotificationTemplate::create([
            'tenant_id' => $this->tenant->id, 'module' => 'Loan', 'event' => 'Approved',
            'subject' => 'Your advance is cleared', 'body' => 'Cleared for {{amount}}.',
            'is_active' => true,
        ]);

        $actor = $this->user();
        $employee = $this->employee($this->user());
        $loan = $this->loan($employee, HrEmployeeLoan::SUBMITTED);

        $this->loans()->approve($loan->id, $this->tenant->id, $actor);

        // The registration is a DEFAULT, not a hardcoding — a workspace that
        // has edited the wording keeps its own.
        $this->assertSame('Your advance is cleared', $this->raised('Loan', 'Approved')->first()->title);
    }

    /* ── 7. The registrations themselves ──────────────────────────────── */

    public function test_the_four_missing_modules_are_now_registered(): void
    {
        $modules = array_keys(config('hr_notifications.modules'));

        foreach (['Payroll', 'Loan', 'Onboarding', 'Lifecycle'] as $module) {
            $this->assertContains($module, $modules, "{$module} must be registered or the engine skips it silently");
        }
    }

    public function test_the_already_covered_modules_are_untouched(): void
    {
        $modules = array_keys(config('hr_notifications.modules'));

        // Adding four must not have disturbed the twelve that worked.
        foreach ([
            'Announcement', 'Recruitment', 'Leave', 'Exit', 'Learning', 'Probation',
            'Performance', 'Attendance', 'Expense', 'Advance', 'Purchase', 'TPV',
        ] as $module) {
            $this->assertContains($module, $modules);
        }
    }

    public function test_no_catch_all_was_added_to_the_new_modules(): void
    {
        // '*' makes a module accept any event wording. The four new modules
        // raise named events only, so a typo must stay silent rather than
        // quietly delivering under a wildcard.
        foreach (['Payroll', 'Loan', 'Onboarding', 'Lifecycle'] as $module) {
            $this->assertArrayNotHasKey('*', config("hr_notifications.modules.{$module}"));
        }
    }

    /* ── 8. Pending approval uses the existing reminder mechanism ─────── */

    public function test_the_new_reminder_sources_are_registered(): void
    {
        $registry = app(ReminderSourceRegistry::class);

        $this->assertInstanceOf(PayrollApprovalPendingSource::class, $registry->for('Payroll', 'Approval Pending'));
        $this->assertInstanceOf(LoanApprovalPendingSource::class, $registry->for('Loan', 'Approval Pending'));
    }

    public function test_a_submitted_loan_is_due_for_a_reminder(): void
    {
        $employee = $this->employee($this->user());
        $this->loan($employee, HrEmployeeLoan::SUBMITTED);

        $due = iterator_to_array(app(LoanApprovalPendingSource::class)->due($this->tenant->id));

        $this->assertCount(1, $due);
        $this->assertSame(['hr'], $due[0]['recipient_roles']);
        $this->assertSame('HrEmployeeLoan', $due[0]['entity_type']);
    }

    public function test_a_decided_loan_stops_being_due(): void
    {
        $employee = $this->employee($this->user());
        $this->loan($employee, HrEmployeeLoan::APPROVED);

        // The row leaves the query by itself — nothing has to remember to stop
        // chasing, which is what makes a repeating reminder safe.
        $this->assertCount(0, iterator_to_array(app(LoanApprovalPendingSource::class)->due($this->tenant->id)));
    }

    public function test_the_reminder_sources_are_tenant_scoped(): void
    {
        $employee = $this->employee($this->user());
        $this->loan($employee, HrEmployeeLoan::SUBMITTED);

        $other = Tenant::create(['name' => 'Other', 'slug' => 'notif-other2', 'status' => 'active']);

        $this->assertCount(0, iterator_to_array(app(LoanApprovalPendingSource::class)->due($other->id)));
    }

    public function test_the_payroll_reminder_carries_the_period_and_amount(): void
    {
        \DB::table('hr_payroll_runs')->insert([
            'tenant_id' => $this->tenant->id, 'payroll_month' => 9, 'payroll_year' => 2026,
            'status' => 'Processing', 'stage' => 'Approve',
            'total_employees' => 12, 'total_payable' => 540000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $due = iterator_to_array(app(PayrollApprovalPendingSource::class)->due($this->tenant->id));

        $this->assertCount(1, $due);
        $this->assertSame('September 2026', $due[0]['context']['period']);
        $this->assertSame(12, $due[0]['context']['employees']);
    }

    public function test_an_approved_run_is_no_longer_chased(): void
    {
        \DB::table('hr_payroll_runs')->insert([
            'tenant_id' => $this->tenant->id, 'payroll_month' => 9, 'payroll_year' => 2026,
            'status' => 'Processing', 'stage' => 'Disburse',
            'total_employees' => 12, 'total_payable' => 540000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertCount(0, iterator_to_array(app(PayrollApprovalPendingSource::class)->due($this->tenant->id)));
    }
}
