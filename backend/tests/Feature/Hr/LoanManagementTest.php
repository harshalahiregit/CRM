<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLoan;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrLoanInstallment;
use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrPayrollRun;
use App\Models\Hr\HrSalaryComponent;
use App\Models\Hr\HrSalaryStructure;
use App\Services\Hr\LoanService;
use App\Services\Hr\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Employee Loan & Salary Advance, including the payroll deduction.
 *
 * The behaviours that carry the design:
 *  - a salary advance is a LOAN TYPE, not a second module;
 *  - only a DISBURSED loan is deducted — approving is not receiving;
 *  - the schedule is frozen at disbursement and sums exactly to the total payable;
 *  - reprocessing a payroll run releases what it collected, so nothing is charged
 *    twice or silently lost.
 */
class LoanManagementTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 1;

    private HrEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->employee = $this->buildEmployeeWithSalary();
    }

    private function service(): LoanService
    {
        return app(LoanService::class);
    }

    private function buildEmployeeWithSalary(float $gross = 60000): HrEmployee
    {
        $employee = HrEmployee::create([
            'tenant_id' => $this->tenantId, 'name' => 'Borrower', 'employee_code' => 'LN-1',
            'department' => 'Ops', 'designation' => 'Executive', 'status' => 'Active',
            'joining_date' => '2020-01-01', 'work_state' => 'Maharashtra',
        ]);

        $component = HrSalaryComponent::create([
            'tenant_id' => $this->tenantId, 'name' => 'Basic', 'code' => 'BASIC', 'type' => 'Earning',
            'calculation_type' => 'Fixed', 'is_active' => true, 'taxable' => true, 'pf_applicable' => true,
        ]);
        $structure = HrSalaryStructure::create([
            'tenant_id' => $this->tenantId, 'name' => 'Std', 'code' => 'STD', 'is_active' => true,
        ]);
        $structure->lines()->create([
            'component_id' => $component->id, 'amount' => $gross, 'calculation_type' => 'Fixed', 'sort_order' => 1,
        ]);

        HrEmployeeSalary::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $employee->id,
            'salary_structure_id' => $structure->id, 'effective_from' => '2026-01-01',
            'annual_ctc' => $gross * 12, 'monthly_ctc' => $gross, 'gross_salary' => $gross,
            'total_benefits' => 0, 'total_deductions' => 0, 'net_salary' => $gross,
            'status' => HrEmployeeSalary::ACTIVE,
        ]);

        return $employee;
    }

    private function loanType(array $overrides = []): array
    {
        return $this->service()->saveType(null, array_merge([
            'name' => 'Personal Loan', 'code' => 'PL', 'is_advance' => false,
            'max_amount' => 500000, 'max_tenure_months' => 60, 'interest_rate' => 12,
            'requires_approval' => true,
        ], $overrides), $this->tenantId);
    }

    private function advanceType(): array
    {
        return $this->service()->saveType(null, [
            'name' => 'Salary Advance', 'code' => 'ADV', 'is_advance' => true,
            'max_amount' => 50000, 'requires_approval' => true,
        ], $this->tenantId);
    }

    /** A loan taken all the way to Disbursed. */
    private function disbursedLoan(array $typeOverrides = [], float $principal = 120000, int $months = 12, string $start = '2026-04'): array
    {
        $type = empty($typeOverrides['is_advance']) ? $this->loanType($typeOverrides) : $this->advanceType();

        $loan = $this->service()->save(null, [
            'employee_id' => $this->employee->id, 'loan_type_id' => $type['id'],
            'principal' => $principal, 'tenure_months' => $months, 'interest_rate' => 0,
        ], $this->tenantId);

        $this->service()->submit($loan['id'], $this->tenantId);
        $this->service()->approve($loan['id'], $this->tenantId);

        return $this->service()->disburse($loan['id'], ['disbursed_on' => '2026-03-20', 'start_period' => $start], $this->tenantId);
    }

    private function runPayroll(int $month, int $year = 2026): HrPayrollRecord
    {
        $run = HrPayrollRun::create([
            'tenant_id' => $this->tenantId, 'payroll_month' => $month, 'payroll_year' => $year,
            'status' => HrPayrollRun::DRAFT,
        ]);
        app(PayrollService::class)->process($run->id, $this->tenantId);

        return HrPayrollRecord::where('payroll_run_id', $run->id)->firstOrFail();
    }

    /* ── Schedule maths ───────────────────────────────────────────────── */

    public function test_an_interest_free_schedule_splits_evenly(): void
    {
        $schedule = $this->service()->buildSchedule(120000, 0, 12);

        $this->assertEquals(10000, $schedule['emi']);
        $this->assertEquals(120000, $schedule['total_payable']);
        $this->assertEquals(0, $schedule['total_interest']);
        $this->assertCount(12, $schedule['rows']);
        $this->assertEquals(0, $schedule['rows'][11]['balance_after']);
    }

    public function test_an_interest_bearing_schedule_reduces_the_balance(): void
    {
        $schedule = $this->service()->buildSchedule(100000, 12, 12);

        $this->assertGreaterThan(100000, $schedule['total_payable'], 'interest is charged');
        $this->assertGreaterThan(0, $schedule['total_interest']);
        // Interest falls and principal rises as the balance reduces.
        $this->assertGreaterThan($schedule['rows'][11]['interest'], $schedule['rows'][0]['interest']);
        $this->assertLessThan($schedule['rows'][11]['principal'], $schedule['rows'][0]['principal']);
    }

    public function test_the_schedule_sums_exactly_to_the_total_payable(): void
    {
        // Deliberately awkward figures — rounding drift left in the last instalment
        // would leave a few paise outstanding forever and the loan would never close.
        $schedule = $this->service()->buildSchedule(97531, 9.75, 7);

        $sum = round(array_sum(array_column($schedule['rows'], 'amount')), 2);
        $this->assertEquals($schedule['total_payable'], $sum);
        $this->assertEquals(0, $schedule['rows'][6]['balance_after']);
    }

    /* ── Advance reuses the loan engine ───────────────────────────────── */

    public function test_a_salary_advance_is_a_single_interest_free_instalment(): void
    {
        $type = $this->advanceType();

        $loan = $this->service()->save(null, [
            'employee_id' => $this->employee->id, 'loan_type_id' => $type['id'],
            'principal' => 25000, 'tenure_months' => 6, 'interest_rate' => 15,   // both ignored
        ], $this->tenantId);

        $this->assertTrue($loan['is_advance']);
        $this->assertSame(1, $loan['tenure_months'], 'an advance is repaid in one go by definition');
        $this->assertEquals(0, $loan['interest_rate']);
        $this->assertEquals(25000, $loan['emi']);
    }

    /* ── Ceilings ─────────────────────────────────────────────────────── */

    public function test_the_type_amount_ceiling_is_enforced(): void
    {
        $type = $this->loanType(['max_amount' => 100000]);

        $this->expectExceptionMessage('capped at');
        $this->service()->save(null, [
            'employee_id' => $this->employee->id, 'loan_type_id' => $type['id'], 'principal' => 250000,
        ], $this->tenantId);
    }

    public function test_the_type_tenure_ceiling_is_enforced(): void
    {
        $type = $this->loanType(['max_tenure_months' => 12]);

        $this->expectExceptionMessage('at most 12 month');
        $this->service()->save(null, [
            'employee_id' => $this->employee->id, 'loan_type_id' => $type['id'],
            'principal' => 50000, 'tenure_months' => 24,
        ], $this->tenantId);
    }

    /* ── Lifecycle ────────────────────────────────────────────────────── */

    public function test_a_type_that_needs_no_approval_is_approved_on_submit(): void
    {
        $type = $this->loanType(['requires_approval' => false]);
        $loan = $this->service()->save(null, [
            'employee_id' => $this->employee->id, 'loan_type_id' => $type['id'], 'principal' => 20000,
        ], $this->tenantId);

        $submitted = $this->service()->submit($loan['id'], $this->tenantId);

        $this->assertSame(HrEmployeeLoan::APPROVED, $submitted['status'],
            'otherwise a queue builds up that nobody is expected to action');
    }

    public function test_a_loan_cannot_be_disbursed_before_approval(): void
    {
        $type = $this->loanType();
        $loan = $this->service()->save(null, [
            'employee_id' => $this->employee->id, 'loan_type_id' => $type['id'], 'principal' => 20000,
        ], $this->tenantId);

        $this->expectExceptionMessage('Only an approved loan can be disbursed');
        $this->service()->disburse($loan['id'], [], $this->tenantId);
    }

    public function test_disbursing_freezes_the_schedule_with_periods(): void
    {
        $loan = $this->disbursedLoan(principal: 120000, months: 12, start: '2026-04');

        $this->assertSame(HrEmployeeLoan::DISBURSED, $loan['status']);
        $this->assertCount(12, $loan['installments']);
        $this->assertSame('2026-04', $loan['installments'][0]['period']);
        $this->assertSame('2027-03', $loan['installments'][11]['period']);
        $this->assertNotEmpty($loan['loan_number']);
        $this->assertEquals(120000, $loan['outstanding']);
    }

    public function test_repayment_defaults_to_the_month_after_disbursement(): void
    {
        $type = $this->loanType(['interest_rate' => 0]);
        $loan = $this->service()->save(null, [
            'employee_id' => $this->employee->id, 'loan_type_id' => $type['id'],
            'principal' => 60000, 'tenure_months' => 6,
        ], $this->tenantId);
        $this->service()->submit($loan['id'], $this->tenantId);
        $this->service()->approve($loan['id'], $this->tenantId);

        $disbursed = $this->service()->disburse($loan['id'], ['disbursed_on' => '2026-05-10'], $this->tenantId);

        $this->assertSame('2026-06', $disbursed['installments'][0]['period'],
            'deducting in the same month would collect before the money was useful');
    }

    /* ── Payroll integration ──────────────────────────────────────────── */

    public function test_payroll_is_unchanged_for_a_tenant_with_no_loans(): void
    {
        $record = $this->runPayroll(4);

        $this->assertEquals(0, (float) $record->loan_deduction);
        $this->assertEquals(60000, (float) $record->net_salary, 'existing totals untouched');
    }

    public function test_an_approved_but_undisbursed_loan_is_not_deducted(): void
    {
        $type = $this->loanType(['interest_rate' => 0]);
        $loan = $this->service()->save(null, [
            'employee_id' => $this->employee->id, 'loan_type_id' => $type['id'],
            'principal' => 12000, 'tenure_months' => 12,
        ], $this->tenantId);
        $this->service()->submit($loan['id'], $this->tenantId);
        $this->service()->approve($loan['id'], $this->tenantId);

        $record = $this->runPayroll(4);

        $this->assertEquals(0, (float) $record->loan_deduction,
            'deducting would take repayment for money the employee never received');
    }

    public function test_a_disbursed_loan_is_deducted_and_the_installment_is_marked(): void
    {
        $this->disbursedLoan(principal: 120000, months: 12, start: '2026-04');

        $record = $this->runPayroll(4);

        $this->assertEquals(10000, (float) $record->loan_deduction);

        $installment = HrLoanInstallment::where('period', '2026-04')->first();
        $this->assertSame(HrLoanInstallment::DEDUCTED, $installment->status);
        $this->assertSame($record->id, $installment->payroll_record_id, 'the audit link to the run that collected it');
    }

    public function test_the_deduction_appears_as_a_payroll_line(): void
    {
        $this->disbursedLoan(principal: 120000, months: 12, start: '2026-04');
        $record = $this->runPayroll(4);

        $line = \App\Models\Hr\HrPayrollRecordLine::where('payroll_record_id', $record->id)
            ->where('source', 'loan')->first();

        $this->assertNotNull($line, 'the payslip must show what was taken');
        $this->assertSame('Deduction', $line->type);
        $this->assertEquals(10000, (float) $line->amount);
    }

    public function test_outstanding_falls_as_installments_are_collected(): void
    {
        $loan = $this->disbursedLoan(principal: 120000, months: 12, start: '2026-04');

        $this->runPayroll(4);
        $this->runPayroll(5);

        $fresh = $this->service()->show($loan['id'], $this->tenantId);

        $this->assertEquals(20000, $fresh['total_repaid']);
        $this->assertEquals(100000, $fresh['outstanding']);
    }

    public function test_a_missed_month_is_collected_as_an_arrear_not_lost(): void
    {
        $this->disbursedLoan(principal: 120000, months: 12, start: '2026-04');

        // April payroll is never run; May is. Both instalments are due by May.
        $may = $this->runPayroll(5);

        $this->assertEquals(20000, (float) $may->loan_deduction, 'April + May');
    }

    public function test_reprocessing_a_run_releases_and_recollects_rather_than_double_charging(): void
    {
        $loan = $this->disbursedLoan(principal: 120000, months: 12, start: '2026-04');

        $run = HrPayrollRun::create([
            'tenant_id' => $this->tenantId, 'payroll_month' => 4, 'payroll_year' => 2026,
            'status' => HrPayrollRun::DRAFT,
        ]);
        app(PayrollService::class)->process($run->id, $this->tenantId);

        // Force the run back to Draft with a raw update. A Completed run cannot be
        // reprocessed through the service, so this exercises the safety net behind
        // the pre-existing "clean slate if a Draft run is (re)processed" path —
        // without it, released instalments would stay pointing at deleted records.
        // (An Eloquent update() here would be a no-op: this instance still holds the
        // pre-process status in memory, so nothing would be dirty.)
        HrPayrollRun::where('id', $run->id)->update(['status' => HrPayrollRun::DRAFT]);

        app(PayrollService::class)->process($run->id, $this->tenantId);

        $record = HrPayrollRecord::where('payroll_run_id', $run->id)->firstOrFail();
        $this->assertEquals(10000, (float) $record->loan_deduction, 'still one instalment, not two');

        $fresh = $this->service()->show($loan['id'], $this->tenantId);
        $this->assertEquals(10000, $fresh['total_repaid']);
        $this->assertSame(1, collect($fresh['installments'])->where('status', 'Deducted')->count());
    }

    public function test_a_fully_repaid_loan_closes_itself(): void
    {
        $loan = $this->disbursedLoan(principal: 20000, months: 2, start: '2026-04');

        $this->runPayroll(4);
        $this->runPayroll(5);

        $fresh = $this->service()->show($loan['id'], $this->tenantId);

        $this->assertSame(HrEmployeeLoan::CLOSED, $fresh['status']);
        $this->assertEquals(0, $fresh['outstanding']);
    }

    public function test_a_waived_installment_is_not_collected(): void
    {
        $loan = $this->disbursedLoan(principal: 120000, months: 12, start: '2026-04');
        $first = $loan['installments'][0]['id'];

        $this->service()->waiveInstallment($loan['id'], $first, 'Hardship', $this->tenantId);
        $record = $this->runPayroll(4);

        $this->assertEquals(0, (float) $record->loan_deduction);
        $this->assertEquals(110000, $this->service()->show($loan['id'], $this->tenantId)['outstanding']);
    }

    public function test_closing_a_loan_skips_the_remaining_installments(): void
    {
        $loan = $this->disbursedLoan(principal: 120000, months: 12, start: '2026-04');
        $this->runPayroll(4);

        $closed = $this->service()->close($loan['id'], 'Settled in full off-payroll', $this->tenantId);

        $this->assertSame(HrEmployeeLoan::CLOSED, $closed['status']);
        $this->assertEquals(0, $closed['outstanding']);
        // Skipped, not deleted — the schedule remains as evidence of what was agreed.
        $this->assertSame(11, collect($closed['installments'])->where('status', 'Skipped')->count());
    }

    public function test_net_payable_reflects_the_loan_without_altering_the_frozen_net(): void
    {
        $this->disbursedLoan(principal: 120000, months: 12, start: '2026-04');
        $run = HrPayrollRun::create([
            'tenant_id' => $this->tenantId, 'payroll_month' => 4, 'payroll_year' => 2026,
            'status' => HrPayrollRun::DRAFT,
        ]);
        app(PayrollService::class)->process($run->id, $this->tenantId);

        $row = app(PayrollService::class)->records($run->id, $this->tenantId)[0];

        $this->assertEquals(60000, $row['net_salary'], 'the frozen snapshot is untouched');
        $this->assertEquals(10000, $row['loan_deduction']);
        $this->assertEquals(50000, $row['net_payable'], 'what actually reaches the bank');
    }
}
