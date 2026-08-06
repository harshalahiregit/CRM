<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrSalaryComponent;
use App\Models\Hr\HrSalaryStructure;
use App\Services\Hr\LoanEligibilityService;
use App\Services\Hr\LoanService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Loan affordability: total EMI against monthly net salary.
 *
 * "Total" is the point — a second loan is judged on the combined burden, because
 * three individually-affordable loans still leave someone with no take-home pay.
 *
 * The percentages here are the shipped defaults (warn 40, block 50); each test
 * that depends on a different figure sets it explicitly, since the whole point is
 * that they are per-tenant configuration.
 */
class LoanEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 1;

    private function eligibility(): LoanEligibilityService
    {
        return app(LoanEligibilityService::class);
    }

    private function loans(): LoanService
    {
        return app(LoanService::class);
    }

    private function setLimit(string $key, $value): void
    {
        app(SettingsService::class)->set($this->tenantId, 'payroll', $key, $value);
    }

    /** An employee with a monthly net of $net. */
    private function employee(float $net = 100000, string $code = 'EL-1'): HrEmployee
    {
        $employee = HrEmployee::create([
            'tenant_id' => $this->tenantId, 'name' => "Emp {$code}", 'employee_code' => $code,
            'department' => 'Ops', 'designation' => 'Executive', 'status' => 'Active',
            'joining_date' => '2020-01-01',
        ]);

        $component = HrSalaryComponent::create([
            'tenant_id' => $this->tenantId, 'name' => 'Basic '.$code, 'code' => 'BASIC'.$employee->id,
            'type' => 'Earning', 'calculation_type' => 'Fixed', 'is_active' => true, 'taxable' => true,
        ]);
        $structure = HrSalaryStructure::create([
            'tenant_id' => $this->tenantId, 'name' => 'Std '.$code, 'code' => 'STD'.$employee->id, 'is_active' => true,
        ]);
        $structure->lines()->create([
            'component_id' => $component->id, 'amount' => $net, 'calculation_type' => 'Fixed', 'sort_order' => 1,
        ]);
        HrEmployeeSalary::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $employee->id,
            'salary_structure_id' => $structure->id, 'effective_from' => '2026-01-01',
            'annual_ctc' => $net * 12, 'monthly_ctc' => $net, 'gross_salary' => $net,
            'total_benefits' => 0, 'total_deductions' => 0, 'net_salary' => $net,
            'status' => HrEmployeeSalary::ACTIVE,
        ]);

        return $employee;
    }

    private function loanType(array $overrides = []): array
    {
        return $this->loans()->saveType(null, array_merge([
            'name' => 'Personal '.uniqid(), 'is_advance' => false,
            'max_amount' => 10000000, 'max_tenure_months' => 120,
            'interest_rate' => 0, 'requires_approval' => true,
        ], $overrides), $this->tenantId);
    }

    /** A disbursed loan, to build up an existing EMI burden. */
    private function disbursed(HrEmployee $employee, float $principal, int $months): array
    {
        $type = $this->loanType();
        $loan = $this->loans()->save(null, [
            'employee_id' => $employee->id, 'loan_type_id' => $type['id'],
            'principal' => $principal, 'tenure_months' => $months, 'interest_rate' => 0,
        ], $this->tenantId);
        $this->loans()->submit($loan['id'], $this->tenantId);
        $this->loans()->approve($loan['id'], $this->tenantId);

        return $this->loans()->disburse($loan['id'], ['start_period' => '2026-04'], $this->tenantId);
    }

    /* ── Thresholds ───────────────────────────────────────────────────── */

    public function test_an_emi_below_the_warning_threshold_is_fine(): void
    {
        $employee = $this->employee(net: 100000);

        $result = $this->eligibility()->evaluate($employee->id, $this->tenantId, 30000);

        $this->assertSame(LoanEligibilityService::OK, $result['status']);
        $this->assertEquals(30, $result['percent']);
        $this->assertFalse($result['blocks']);
        $this->assertNull($result['message']);
    }

    public function test_an_emi_between_the_thresholds_warns_but_allows(): void
    {
        $employee = $this->employee(net: 100000);

        $result = $this->eligibility()->evaluate($employee->id, $this->tenantId, 45000);

        $this->assertSame(LoanEligibilityService::WARNING, $result['status']);
        $this->assertEquals(45, $result['percent']);
        $this->assertFalse($result['blocks'], 'a warning is not a refusal');
        $this->assertStringContainsString('40%', $result['message']);
    }

    public function test_an_emi_above_the_hard_limit_blocks(): void
    {
        $employee = $this->employee(net: 100000);

        $result = $this->eligibility()->evaluate($employee->id, $this->tenantId, 60000);

        $this->assertSame(LoanEligibilityService::BLOCKED, $result['status']);
        $this->assertTrue($result['blocks']);
        $this->assertStringContainsString('50%', $result['message']);
    }

    public function test_the_boundary_is_inclusive_of_the_threshold(): void
    {
        $employee = $this->employee(net: 100000);

        // Exactly 40% is comfortable; exactly 50% is still allowed.
        $this->assertSame(LoanEligibilityService::OK, $this->eligibility()->evaluate($employee->id, $this->tenantId, 40000)['status']);
        $this->assertSame(LoanEligibilityService::WARNING, $this->eligibility()->evaluate($employee->id, $this->tenantId, 50000)['status']);
        $this->assertSame(LoanEligibilityService::BLOCKED, $this->eligibility()->evaluate($employee->id, $this->tenantId, 50001)['status']);
    }

    /* ── Per-tenant configuration ─────────────────────────────────────── */

    public function test_the_thresholds_are_configurable(): void
    {
        $employee = $this->employee(net: 100000);
        $this->setLimit('loan_emi_warn_percent', 20);
        $this->setLimit('loan_emi_max_percent', 25);

        $this->assertSame(LoanEligibilityService::OK,      $this->eligibility()->evaluate($employee->id, $this->tenantId, 15000)['status']);
        $this->assertSame(LoanEligibilityService::WARNING, $this->eligibility()->evaluate($employee->id, $this->tenantId, 22000)['status']);
        $this->assertSame(LoanEligibilityService::BLOCKED, $this->eligibility()->evaluate($employee->id, $this->tenantId, 30000)['status']);
    }

    public function test_a_max_below_the_warn_threshold_is_normalised(): void
    {
        $employee = $this->employee(net: 100000);
        // A misconfiguration: the block limit under the comfort threshold would
        // make every warning also a block. The max is raised to meet it instead.
        $this->setLimit('loan_emi_warn_percent', 40);
        $this->setLimit('loan_emi_max_percent', 10);

        $limits = $this->eligibility()->limits($this->tenantId);

        $this->assertEquals(40, $limits['max_percent']);
    }

    public function test_enforcement_can_be_turned_off_leaving_the_warning(): void
    {
        $employee = $this->employee(net: 100000);
        $this->setLimit('loan_enforce_eligibility', false);

        $result = $this->eligibility()->evaluate($employee->id, $this->tenantId, 90000);

        $this->assertSame(LoanEligibilityService::BLOCKED, $result['status'], 'still reported');
        $this->assertFalse($result['blocks'], 'but not enforced');
    }

    /* ── Total burden, not one loan in isolation ──────────────────────── */

    public function test_an_existing_loan_counts_toward_the_burden(): void
    {
        $employee = $this->employee(net: 100000);
        $this->disbursed($employee, principal: 360000, months: 12);   // EMI 30,000

        $result = $this->eligibility()->evaluate($employee->id, $this->tenantId, 25000);

        $this->assertEquals(30000, $result['existing_emi']);
        $this->assertEquals(55000, $result['total_emi']);
        $this->assertSame(LoanEligibilityService::BLOCKED, $result['status'],
            'affordable alone, unaffordable on top of what is already being repaid');
    }

    public function test_a_second_unaffordable_loan_is_refused_at_save(): void
    {
        $employee = $this->employee(net: 100000);
        $this->disbursed($employee, principal: 360000, months: 12);   // EMI 30,000

        $type = $this->loanType();
        $this->expectExceptionMessage('exceeds the 50% limit');
        $this->loans()->save(null, [
            'employee_id' => $employee->id, 'loan_type_id' => $type['id'],
            'principal' => 360000, 'tenure_months' => 12, 'interest_rate' => 0,
        ], $this->tenantId);
    }

    public function test_editing_a_loan_does_not_count_its_own_emi_twice(): void
    {
        $employee = $this->employee(net: 100000);
        $type = $this->loanType();

        $loan = $this->loans()->save(null, [
            'employee_id' => $employee->id, 'loan_type_id' => $type['id'],
            'principal' => 480000, 'tenure_months' => 12, 'interest_rate' => 0,   // EMI 40,000
        ], $this->tenantId);

        // Re-saving the same loan must not treat its own EMI as pre-existing debt.
        $again = $this->loans()->save($loan['id'], [
            'employee_id' => $employee->id, 'loan_type_id' => $type['id'],
            'principal' => 480000, 'tenure_months' => 12, 'interest_rate' => 0,
        ], $this->tenantId);

        $this->assertEquals(40000, $again['emi']);
    }

    public function test_an_undisbursed_loan_does_not_count_toward_the_burden(): void
    {
        $employee = $this->employee(net: 100000);
        $type = $this->loanType();

        $first = $this->loans()->save(null, [
            'employee_id' => $employee->id, 'loan_type_id' => $type['id'],
            'principal' => 360000, 'tenure_months' => 12, 'interest_rate' => 0,
        ], $this->tenantId);
        $this->loans()->submit($first['id'], $this->tenantId);
        $this->loans()->approve($first['id'], $this->tenantId);   // approved, NOT disbursed

        $result = $this->eligibility()->evaluate($employee->id, $this->tenantId, 25000);

        $this->assertEquals(0, $result['existing_emi'],
            'no money has moved, so nothing is being repaid yet');
        $this->assertSame(LoanEligibilityService::OK, $result['status']);
    }

    /* ── Cannot evaluate ──────────────────────────────────────────────── */

    public function test_an_employee_with_no_salary_is_not_blocked(): void
    {
        $employee = HrEmployee::create([
            'tenant_id' => $this->tenantId, 'name' => 'No Salary', 'employee_code' => 'NS-1',
            'department' => 'Ops', 'designation' => 'Executive', 'status' => 'Active',
            'joining_date' => '2020-01-01',
        ]);

        $result = $this->eligibility()->evaluate($employee->id, $this->tenantId, 90000);

        // Refusing because payroll is not set up would present the system's gap as
        // the employee's problem.
        $this->assertSame(LoanEligibilityService::NOT_EVALUATED, $result['status']);
        $this->assertFalse($result['blocks']);
        $this->assertNull($result['net_salary']);
        $this->assertStringContainsString('No active salary', $result['message']);
    }

    /* ── Reported figures ─────────────────────────────────────────────── */

    public function test_it_reports_the_maximum_affordable_instalment(): void
    {
        $employee = $this->employee(net: 100000);
        $this->disbursed($employee, principal: 120000, months: 12);   // EMI 10,000

        $result = $this->eligibility()->evaluate($employee->id, $this->tenantId, 5000);

        // 50% of 100,000 = 50,000, less the 10,000 already committed.
        $this->assertEquals(40000, $result['max_affordable_emi']);
    }

    public function test_the_loan_payload_carries_live_eligibility(): void
    {
        $employee = $this->employee(net: 100000);
        $loan = $this->disbursed($employee, principal: 360000, months: 12);

        $detail = $this->loans()->show($loan['id'], $this->tenantId);

        $this->assertArrayHasKey('eligibility', $detail);
        $this->assertEquals(30, $detail['eligibility']['percent']);
        $this->assertSame(LoanEligibilityService::OK, $detail['eligibility']['status']);
    }

    public function test_disbursement_rechecks_affordability(): void
    {
        $employee = $this->employee(net: 100000);
        $type = $this->loanType();

        // Approved while affordable…
        $loan = $this->loans()->save(null, [
            'employee_id' => $employee->id, 'loan_type_id' => $type['id'],
            'principal' => 480000, 'tenure_months' => 12, 'interest_rate' => 0,   // EMI 40,000
        ], $this->tenantId);
        $this->loans()->submit($loan['id'], $this->tenantId);
        $this->loans()->approve($loan['id'], $this->tenantId);

        // …then the tenant tightens policy before the money moves. Both thresholds
        // must move: a max below the warn threshold is normalised back up, so
        // lowering only the max would leave the effective limit unchanged.
        $this->setLimit('loan_emi_warn_percent', 15);
        $this->setLimit('loan_emi_max_percent', 20);

        $this->expectExceptionMessage('exceeds the 20% limit');
        $this->loans()->disburse($loan['id'], ['start_period' => '2026-04'], $this->tenantId);
    }
}
