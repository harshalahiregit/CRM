<?php

namespace Tests\Feature\Hr\Payroll;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeDetail;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrPayrollRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The stepped payroll run.
 *
 * Computing salaries and AGREEING to pay them are separate acts, and until this
 * existed the system could not tell them apart: one button swept every employee
 * with an active salary and jumped straight to Completed, with no record of who
 * chose the people or who approved the amounts.
 *
 * What is asserted here is mostly REFUSAL. Every guard in the chain protects
 * evidence that cannot be reconstructed afterwards — an adjustment made after
 * sign-off changes a number somebody already put their name to, and a run that
 * reaches disbursement unapproved has lost the only proof anybody agreed to it.
 * So the tests that matter are the ones where the system says no.
 */
class PayrollWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;

    private User $hr;

    private HrPayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'payroll-flow', 'status' => 'active']);
        $this->tenantId = $tenant->id;

        $this->hr = User::create([
            'tenant_id' => $tenant->id, 'name' => 'HR', 'email' => 'hr@flow.test',
            'password' => Hash::make('x'), 'role' => 'admin', 'status' => 'active',
        ]);

        $this->run = HrPayrollRun::create([
            'tenant_id' => $tenant->id, 'payroll_month' => 7, 'payroll_year' => 2026,
            'status' => HrPayrollRun::DRAFT,
        ]);

        Sanctum::actingAs($this->hr);
    }

    /**
     * An employee who can actually be paid: active salary, bank details, PAN.
     *
     * `$detail` overrides let a test remove exactly one of those and assert the
     * pre-check names the one thing that is missing.
     */
    private function person(string $code, float $net = 40000, array $detail = [], array $employee = []): HrEmployee
    {
        $e = HrEmployee::create(array_merge([
            'tenant_id' => $this->tenantId, 'employee_code' => $code, 'name' => "Person {$code}",
            'department' => 'Ops', 'designation' => 'Staff',
            'joining_date' => '2020-01-01', 'status' => 'Active',
        ], $employee));

        HrEmployeeDetail::create(array_merge([
            'tenant_id' => $this->tenantId, 'employee_id' => $e->id,
            'bank_account_number' => '0012345678', 'bank_ifsc' => 'HDFC0001234',
            'bank_name' => 'HDFC Bank', 'pay_mode' => 'Transfer',
            'pan_number' => 'ABCDE1234F', 'aadhaar_number' => '111122223333',
        ], $detail));

        HrEmployeeSalary::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $e->id,
            'effective_from' => '2020-01-01',
            'annual_ctc' => $net * 12, 'monthly_ctc' => $net,
            'gross_salary' => $net, 'total_benefits' => 0, 'total_deductions' => 0,
            'net_salary' => $net, 'status' => HrEmployeeSalary::ACTIVE,
        ]);

        return $e;
    }

    private function precheck(): array
    {
        return $this->getJson("/api/hr/payroll/runs/{$this->run->id}/precheck")->assertOk()->json('data');
    }

    private function process(): array
    {
        return $this->postJson("/api/hr/payroll/runs/{$this->run->id}/process")->assertOk()->json();
    }

    private function recordFor(HrEmployee $e): HrPayrollRecord
    {
        return HrPayrollRecord::where('payroll_run_id', $this->run->id)
            ->where('employee_id', $e->id)->firstOrFail();
    }

    /* ── Stage 1: Pre-check ───────────────────────────────────────────── */

    public function test_the_precheck_names_what_is_missing_rather_than_hiding_the_person(): void
    {
        $this->person('SD01');
        $this->person('SD02', 30000, ['bank_account_number' => null]);
        $this->person('SD03', 30000, ['pan_number' => null]);

        $p = $this->precheck();

        $this->assertSame(3, $p['summary']['total']);
        $this->assertSame(1, $p['summary']['ready']);
        $this->assertSame(2, $p['summary']['blocked']);

        $reasons = collect($p['employees'])->pluck('blocked_reason')->filter()->implode(' | ');
        $this->assertStringContainsString('No bank account number', $reasons);
        $this->assertStringContainsString('PAN not on record', $reasons);
    }

    /** Somebody HR has already put on hold is excluded WITH that as the reason. */
    public function test_salary_on_hold_reads_as_a_decision_not_an_error(): void
    {
        $this->person('SD04', 30000, [], ['hold_salary' => true]);

        $this->assertSame('Salary is on hold', $this->precheck()['employees'][0]['blocked_reason']);
    }

    /**
     * A missing work state warns; it does not block.
     *
     * The transfer still works — it is the PT filing that suffers. Blocking
     * payroll over it would have HR entering a fake state to get the run out.
     */
    public function test_a_missing_work_state_warns_without_blocking(): void
    {
        $this->person('SD05');

        $row = $this->precheck()['employees'][0];

        $this->assertFalse($row['blocked']);
        $this->assertStringContainsString('Professional Tax will compute as zero', implode(' ', $row['warnings']));
    }

    /** With no selection made yet, everybody payable is pre-ticked. */
    public function test_everybody_payable_is_selected_by_default(): void
    {
        $this->person('SD06');
        $this->person('SD07', 30000, ['bank_ifsc' => null]);

        $rows = collect($this->precheck()['employees'])->keyBy('employee_code');

        $this->assertTrue($rows['SD06']['selected']);
        $this->assertFalse($rows['SD07']['selected'], 'a blocked employee is not pre-selected');
    }

    /* ── Selection actually restricts the run ─────────────────────────── */

    public function test_only_the_selected_employees_are_paid(): void
    {
        $keep = $this->person('SD10', 40000);
        $this->person('SD11', 50000);

        $this->postJson("/api/hr/payroll/runs/{$this->run->id}/employees", [
            'employee_ids' => [$keep->id],
        ])->assertOk();

        $run = $this->process();

        $this->assertSame(1, $run['total_employees'], 'the unselected employee must not be paid');
        $this->assertSame(1, HrPayrollRecord::where('payroll_run_id', $this->run->id)->count());
        $this->assertSame($keep->id, $this->recordFor($keep)->employee_id);
    }

    /**
     * Selecting somebody the pre-check blocked does not pay them.
     *
     * The row is still written, carrying its reason, so the run knows they were
     * considered and why they were left out. Silently dropping them is how a
     * person misses a salary and nobody notices until they ask.
     */
    public function test_a_blocked_employee_is_recorded_but_not_paid(): void
    {
        $ok = $this->person('SD12');
        $blocked = $this->person('SD13', 30000, ['bank_account_number' => null]);

        $this->postJson("/api/hr/payroll/runs/{$this->run->id}/employees", [
            'employee_ids' => [$ok->id, $blocked->id],
        ])->assertOk();

        $this->assertDatabaseHas('hr_payroll_run_employees', [
            'payroll_run_id' => $this->run->id,
            'employee_id'    => $blocked->id,
            'blocked_reason' => 'No bank account number',
        ]);

        $this->process();

        $this->assertSame(1, HrPayrollRecord::where('payroll_run_id', $this->run->id)->count());
    }

    /**
     * A run nobody has made a selection on still pays everybody.
     *
     * This is the behaviour every existing run and test relies on, and the
     * correct default for the ordinary month.
     */
    public function test_a_run_with_no_selection_pays_everyone_as_before(): void
    {
        $this->person('SD14');
        $this->person('SD15');

        $this->assertSame(2, $this->process()['total_employees']);
    }

    /* ── Stage 3: Adjustments ─────────────────────────────────────────── */

    public function test_an_adjustment_moves_the_pay_and_the_bank_advice_together(): void
    {
        $e = $this->person('SD20', 40000);
        $this->process();
        $record = $this->recordFor($e);

        $this->postJson("/api/hr/payroll/records/{$record->id}/adjustments", [
            'type' => 'Addition', 'amount' => 1000, 'reason' => 'Unsettled travel claim from June',
        ])->assertCreated();

        $this->assertEquals(41000, $this->recordFor($e)->netPayable());

        // The bank file must move with it. These two disagreeing is exactly the
        // failure this whole area was rebuilt around.
        $advice = $this->getJson("/api/hr/payroll/runs/{$this->run->id}/bank-advice")->assertOk()->json('data');
        $this->assertEquals(41000, $advice['rows'][0]['amount']);
        $this->assertEquals(41000, $advice['totals']['amount']);
    }

    public function test_a_deduction_reduces_the_transfer(): void
    {
        $e = $this->person('SD21', 40000);
        $this->process();

        $this->postJson("/api/hr/payroll/records/{$this->recordFor($e)->id}/adjustments", [
            'type' => 'Deduction', 'amount' => 2500, 'reason' => 'Laptop not returned',
        ])->assertCreated();

        $this->assertEquals(37500, $this->recordFor($e)->netPayable());
    }

    /** The reason is the reason the table exists. */
    public function test_an_adjustment_without_a_reason_is_refused(): void
    {
        $e = $this->person('SD22');
        $this->process();

        $this->postJson("/api/hr/payroll/records/{$this->recordFor($e)->id}/adjustments", [
            'type' => 'Addition', 'amount' => 500,
        ])->assertStatus(422);

        $this->assertSame(0, (int) $this->recordFor($e)->adjustments()->count());
    }

    public function test_removing_an_adjustment_puts_the_pay_back(): void
    {
        $e = $this->person('SD23', 40000);
        $this->process();

        $id = $this->postJson("/api/hr/payroll/records/{$this->recordFor($e)->id}/adjustments", [
            'type' => 'Addition', 'amount' => 1000, 'reason' => 'Arrear',
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/hr/payroll/adjustments/{$id}")->assertOk();

        $this->assertEquals(40000, $this->recordFor($e)->netPayable());
    }

    /* ── Stage 4: Approval ────────────────────────────────────────────── */

    public function test_a_run_cannot_be_approved_before_it_is_calculated(): void
    {
        $this->person('SD30');

        $this->postJson("/api/hr/payroll/runs/{$this->run->id}/approve")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'This run has not been calculated yet.']);
    }

    public function test_approval_records_who_and_when_and_advances_the_stage(): void
    {
        $this->person('SD31');
        $this->assertSame(HrPayrollRun::STAGE_APPROVE, $this->process()['stage']);

        $run = $this->postJson("/api/hr/payroll/runs/{$this->run->id}/approve", [
            'note' => 'Checked against the register',
        ])->assertOk()->json();

        $this->assertSame(HrPayrollRun::STAGE_DISBURSE, $run['stage']);
        $this->assertTrue($run['is_approved']);
        $this->assertSame('HR', $run['approved_by']);
        $this->assertNotNull($run['approved_at']);
    }

    /** Nothing is adjustable once somebody has signed for the amounts. */
    public function test_adjustments_are_refused_after_approval(): void
    {
        $e = $this->person('SD32', 40000);
        $this->process();
        $this->postJson("/api/hr/payroll/runs/{$this->run->id}/approve")->assertOk();

        $this->postJson("/api/hr/payroll/records/{$this->recordFor($e)->id}/adjustments", [
            'type' => 'Addition', 'amount' => 1000, 'reason' => 'Too late',
        ])->assertStatus(422);

        $this->assertEquals(40000, $this->recordFor($e)->netPayable());
    }

    public function test_a_rejection_needs_a_reason_and_sends_the_run_back(): void
    {
        $this->person('SD33');
        $this->process();

        $this->postJson("/api/hr/payroll/runs/{$this->run->id}/reject")->assertStatus(422);

        $run = $this->postJson("/api/hr/payroll/runs/{$this->run->id}/reject", [
            'note' => 'Overtime for the night shift is missing',
        ])->assertOk()->json();

        $this->assertSame(HrPayrollRun::STAGE_CALCULATE, $run['stage']);
        $this->assertSame('Overtime for the night shift is missing', $run['approval_note']);
        $this->assertFalse($run['is_approved'], 'a rejected run is adjustable again');
    }

    /**
     * Segregation of duties, when the tenant has switched it on.
     *
     * Off by default on purpose: it is the correct control and it is also the
     * control that stops a one-admin tenant from ever paying anybody.
     */
    public function test_the_processor_cannot_approve_their_own_run_when_that_is_required(): void
    {
        app(SettingsService::class)->set($this->tenantId, 'payroll', 'require_separate_approver', true);

        $this->person('SD34');
        $this->process();

        $this->postJson("/api/hr/payroll/runs/{$this->run->id}/approve")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Payroll must be approved by someone other than the person who processed it.']);
    }

    /* ── Stage 5: Disbursement ────────────────────────────────────────── */

    public function test_payments_cannot_be_recorded_before_approval(): void
    {
        $e = $this->person('SD40');
        $this->process();

        $this->postJson("/api/hr/payroll/records/{$this->recordFor($e)->id}/payment", [
            'payment_status' => 'Paid',
        ])->assertStatus(422);
    }

    public function test_the_run_reaches_paid_only_when_no_transfer_is_still_pending(): void
    {
        $a = $this->person('SD41');
        $b = $this->person('SD42');
        $this->process();
        $this->postJson("/api/hr/payroll/runs/{$this->run->id}/approve")->assertOk();

        $this->postJson("/api/hr/payroll/records/{$this->recordFor($a)->id}/payment", [
            'payment_status' => 'Paid',
        ])->assertOk();

        $this->assertSame(
            HrPayrollRun::STAGE_DISBURSE,
            $this->run->fresh()->stage,
            'one transfer settled is not the whole run'
        );

        $this->postJson("/api/hr/payroll/records/{$this->recordFor($b)->id}/payment", [
            'payment_status' => 'Paid',
        ])->assertOk();

        $this->assertSame(HrPayrollRun::STAGE_PAID, $this->run->fresh()->stage);
    }

    /**
     * A held transfer does not hold the stage open forever.
     *
     * A run that can never reach Paid because one person is on hold would leave
     * every later month queued behind it.
     */
    public function test_a_held_transfer_does_not_block_the_run_closing(): void
    {
        $a = $this->person('SD43');
        $b = $this->person('SD44');
        $this->process();
        $this->postJson("/api/hr/payroll/runs/{$this->run->id}/approve")->assertOk();

        $this->postJson("/api/hr/payroll/records/{$this->recordFor($a)->id}/payment", ['payment_status' => 'Paid']);
        $this->postJson("/api/hr/payroll/records/{$this->recordFor($b)->id}/payment", [
            'payment_status' => 'Hold', 'note' => 'Account frozen — bank contacted',
        ]);

        $this->assertSame(HrPayrollRun::STAGE_PAID, $this->run->fresh()->stage);
        $this->assertSame('Account frozen — bank contacted', $this->recordFor($b)->payment_note);
    }

    public function test_accounts_can_settle_the_whole_run_at_once(): void
    {
        $this->person('SD45');
        $this->person('SD46');
        $this->process();
        $this->postJson("/api/hr/payroll/runs/{$this->run->id}/approve")->assertOk();

        $run = $this->postJson("/api/hr/payroll/runs/{$this->run->id}/payments", [
            'payment_status' => 'Paid',
        ])->assertOk()->json();

        $this->assertSame(2, $run['payments']['paid']);
        $this->assertSame(0, $run['payments']['pending']);
    }

    /* ── Payslip visibility ───────────────────────────────────────────── */

    public function test_payslips_are_hidden_until_they_are_released(): void
    {
        $e = $this->person('SD50');
        $this->process();

        $this->assertFalse($this->recordFor($e)->payslip_visible, 'hidden by default');

        // Releasing before approval would put a payslip in front of somebody
        // before HR can answer questions about it.
        $this->postJson("/api/hr/payroll/runs/{$this->run->id}/release-payslips", ['visible' => true])
            ->assertStatus(422);

        $this->postJson("/api/hr/payroll/runs/{$this->run->id}/approve")->assertOk();
        $this->postJson("/api/hr/payroll/runs/{$this->run->id}/release-payslips", ['visible' => true])
            ->assertOk();

        $this->assertTrue($this->recordFor($e)->payslip_visible);
    }

    public function test_one_persons_payslip_can_be_held_back_on_its_own(): void
    {
        $a = $this->person('SD51');
        $b = $this->person('SD52');
        $this->process();
        $this->postJson("/api/hr/payroll/runs/{$this->run->id}/approve")->assertOk();
        $this->postJson("/api/hr/payroll/runs/{$this->run->id}/release-payslips", ['visible' => true])->assertOk();

        $this->postJson("/api/hr/payroll/records/{$this->recordFor($b)->id}/payslip-visibility", [
            'visible' => false,
        ])->assertOk();

        $this->assertTrue($this->recordFor($a)->payslip_visible);
        $this->assertFalse($this->recordFor($b)->payslip_visible);
    }

    /* ── Isolation ────────────────────────────────────────────────────── */

    public function test_another_tenants_run_is_not_reachable(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'payroll-flow-2', 'status' => 'active']);
        $theirs = HrPayrollRun::create([
            'tenant_id' => $other->id, 'payroll_month' => 7, 'payroll_year' => 2026,
            'status' => HrPayrollRun::COMPLETED, 'stage' => HrPayrollRun::STAGE_APPROVE,
        ]);

        $this->getJson("/api/hr/payroll/runs/{$theirs->id}/precheck")->assertStatus(404);
        $this->postJson("/api/hr/payroll/runs/{$theirs->id}/approve")->assertStatus(404);
    }

    public function test_the_run_total_payable_is_the_bank_total_not_the_frozen_net(): void
    {
        $e = $this->person('SD60', 40000);
        $this->process();

        // A statutory split of the kind a real run produces.
        HrPayrollRecord::where('employee_id', $e->id)->update([
            'pf_employee' => 1800, 'esic_employee' => 139, 'statutory_deductions' => 1939,
        ]);

        $this->postJson("/api/hr/payroll/records/{$this->recordFor($e)->id}/adjustments", [
            'type' => 'Addition', 'amount' => 500, 'reason' => 'Arrear',
        ])->assertCreated();

        // 40000 − 1939 + 500
        $this->assertEquals(38561, $this->run->fresh()->total_payable);
        $this->assertEquals(40000, $this->run->fresh()->total_net, 'the frozen net is untouched');
    }
}
