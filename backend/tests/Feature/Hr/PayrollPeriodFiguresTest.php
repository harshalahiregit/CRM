<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrPayrollRun;
use App\Models\Hr\HrSalaryComponent;
use App\Models\Hr\HrSalaryStructure;
use App\Models\Hr\HrStatutoryRule;
use App\Services\Hr\PayrollReportService;
use App\Services\Hr\PayrollService;
use App\Services\Hr\PayslipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The period figures, and the one identity every payroll consumer relies on.
 *
 * hr_payroll_records carries TWO different kinds of money and they were being
 * confused. `gross_salary` / `total_deductions` / `net_salary` are the frozen
 * salary-STRUCTURE snapshot — for a structure that defines no deductions of its
 * own (which is every ordinary one, because PF, ESIC and PT are statutory and
 * resolved per period) that means deductions of 0 and net == gross, forever.
 * The PERIOD figures live in their own columns beside them.
 *
 * Reading the snapshot where the period was meant produced a payroll hub that
 * said "Deductions ₹0" directly above a "Statutory ₹8,261" tile computed from
 * the same run, payslips telling people their net was their gross, and payroll
 * reports showing a company that withheld nothing from anybody.
 *
 * These tests exist so that cannot come back quietly.
 */
class PayrollPeriodFiguresTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 1;

    private function salary(float $basic = 20000, float $hra = 8000): HrEmployeeSalary
    {
        $employee = HrEmployee::create([
            'tenant_id' => $this->tenantId, 'name' => 'Period Figures', 'employee_code' => 'PFT-1',
            'department' => 'Engineering', 'designation' => 'Engineer', 'status' => 'Active',
            'joining_date' => '2020-01-01', 'work_state' => 'Maharashtra',
        ]);

        $mk = fn (string $code, string $name, array $flags = []) => HrSalaryComponent::create(array_merge([
            'tenant_id' => $this->tenantId, 'name' => $name, 'code' => $code, 'type' => 'Earning',
            'calculation_type' => 'Fixed', 'is_active' => true,
        ], $flags));

        $basicC = $mk('BASIC', 'Basic', ['taxable' => true, 'pf_applicable' => true, 'esic_applicable' => true]);
        $hraC   = $mk('HRA', 'HRA', ['taxable' => true, 'esic_applicable' => true]);

        $structure = HrSalaryStructure::create([
            'tenant_id' => $this->tenantId, 'name' => 'Period Structure', 'code' => 'PFS1', 'is_active' => true,
        ]);
        $structure->lines()->createMany([
            ['component_id' => $basicC->id, 'amount' => $basic, 'calculation_type' => 'Fixed', 'sort_order' => 1],
            ['component_id' => $hraC->id,   'amount' => $hra,   'calculation_type' => 'Fixed', 'sort_order' => 2],
        ]);

        return HrEmployeeSalary::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $employee->id,
            'salary_structure_id' => $structure->id, 'effective_from' => '2026-01-01',
            'annual_ctc' => ($basic + $hra) * 12, 'monthly_ctc' => $basic + $hra,
            'gross_salary' => $basic + $hra, 'total_benefits' => 0, 'total_deductions' => 0,
            'net_salary' => $basic + $hra, 'status' => HrEmployeeSalary::ACTIVE,
        ]);
    }

    private function runFor(int $month = 6): HrPayrollRun
    {
        return HrPayrollRun::create([
            'tenant_id' => $this->tenantId, 'payroll_month' => $month, 'payroll_year' => 2026,
            'status' => HrPayrollRun::DRAFT,
        ]);
    }

    private function withPf(): void
    {
        HrStatutoryRule::create([
            'tenant_id' => $this->tenantId, 'rule_type' => 'pf', 'is_active' => true,
            'effective_from' => '2026-01-01',
            'config' => ['employee_rate' => 12, 'employer_rate' => 12, 'wage_ceiling' => 15000],
        ]);
    }

    public function test_period_deductions_are_not_the_structure_snapshot(): void
    {
        $this->withPf();
        $salary = $this->salary();
        $run = $this->runFor();

        app(PayrollService::class)->process($run->id, $this->tenantId);

        $record = $run->fresh()->records()->firstOrFail();

        // The snapshot is untouched and still says "this structure deducts nothing".
        $this->assertEquals(0.0, (float) $record->total_deductions,
            'The structure snapshot must stay as it was — it is not the period figure.');

        // The period figure is the real one.
        $this->assertGreaterThan(0, $record->periodDeductions(),
            'PF was configured and withheld, so period deductions cannot be zero.');
        $this->assertEquals(
            round((float) $record->statutory_deductions, 2),
            $record->periodDeductions(),
            'With no loans or late marks, period deductions are exactly the statutory ones.'
        );
    }

    public function test_gross_minus_deductions_equals_net_payable(): void
    {
        $this->withPf();
        $salary = $this->salary();
        $run = $this->runFor();

        app(PayrollService::class)->process($run->id, $this->tenantId);
        $record = $run->fresh()->records()->firstOrFail();

        $this->assertEquals(
            $record->netPayable(),
            round($record->periodGross() - $record->periodDeductions(), 2),
            'periodGross() - periodDeductions() must equal netPayable(), or the hub, '
            .'payslip and reports will disagree about the same person.'
        );
    }

    public function test_employer_contributions_are_never_employee_deductions(): void
    {
        $this->withPf();
        $salary = $this->salary();
        $run = $this->runFor();

        app(PayrollService::class)->process($run->id, $this->tenantId);
        $record = $run->fresh()->records()->firstOrFail();

        $this->assertGreaterThan(0, $record->employerContributions(),
            'The employer PF share should have been computed.');
        $this->assertEqualsWithDelta(
            (float) $record->statutory_deductions,
            $record->periodDeductions(),
            0.01,
            'Employer contributions are company cost and must not appear in what the employee lost.'
        );
    }

    public function test_run_totals_use_period_figures_and_reconcile(): void
    {
        $this->withPf();
        $this->salary();
        $run = $this->runFor();

        app(PayrollService::class)->process($run->id, $this->tenantId);
        $run->refresh();

        $this->assertGreaterThan(0, (float) $run->total_deductions,
            'Run totals used to accumulate the structure snapshot and were always zero.');
        $this->assertEqualsWithDelta((float) $run->total_payable, (float) $run->total_net, 0.01,
            'total_net is the period net and must agree with what is actually payable.');
        $this->assertEqualsWithDelta(
            (float) $run->total_payable,
            round((float) $run->total_gross - (float) $run->total_deductions, 2),
            0.01,
            'Run totals must reconcile: gross - deductions = payable.'
        );
    }

    public function test_payslip_carries_the_period_figures(): void
    {
        $this->withPf();
        $this->salary();
        $run = $this->runFor();

        app(PayrollService::class)->process($run->id, $this->tenantId);
        $record = $run->fresh()->records()->firstOrFail();

        app(PayslipService::class)->generateForRun($run->id, $this->tenantId);
        $payslip = $record->fresh()->payslip ?? \App\Models\Hr\HrPayslip::where('payroll_record_id', $record->id)->firstOrFail();

        $this->assertEqualsWithDelta($record->netPayable(), (float) $payslip->net_salary, 0.01,
            'A payslip that states gross as net is not a payslip.');
        $this->assertEqualsWithDelta($record->periodDeductions(), (float) $payslip->total_deductions, 0.01,
            'PF and PT were withheld; an Indian payslip must show them.');
        $this->assertGreaterThan(0, (float) $payslip->total_deductions);
    }

    public function test_csv_export_exports_period_figures_under_period_headers(): void
    {
        $this->withPf();
        $this->salary();
        $run = $this->runFor();

        app(PayrollService::class)->process($run->id, $this->tenantId);
        $record = $run->fresh()->records()->firstOrFail();

        $export = app(PayrollReportService::class)->exportRows('summary', $this->tenantId, []);

        $this->assertContains('Employee Deductions', $export['headers']);
        $this->assertContains('Net Payable', $export['headers']);
        $this->assertContains('Period Gross', $export['headers']);
        $this->assertContains('Employer Contributions', $export['headers']);

        $row = array_combine($export['headers'], $export['rows'][0]);

        $this->assertEqualsWithDelta($record->periodDeductions(), (float) $row['Employee Deductions'], 0.01,
            'The export used to put the structure snapshot (always 0) under a "Deductions" header.');
        $this->assertEqualsWithDelta($record->netPayable(), (float) $row['Net Payable'], 0.01);
        $this->assertEqualsWithDelta($record->periodGross(), (float) $row['Period Gross'], 0.01);
        $this->assertEqualsWithDelta($record->employerContributions(), (float) $row['Employer Contributions'], 0.01,
            'Employer contributions belong in their own column, never inside employee deductions.');
    }

    public function test_payroll_reports_do_not_report_zero_deductions(): void
    {
        $this->withPf();
        $this->salary();
        $run = $this->runFor();

        app(PayrollService::class)->process($run->id, $this->tenantId);

        $summary = app(PayrollReportService::class)->summary($this->tenantId, []);

        $this->assertGreaterThan(0, $summary['total_deductions'],
            'Reports aggregated the structure snapshot and showed a company withholding nothing.');
        $this->assertEqualsWithDelta(
            $summary['total_payroll_cost'],
            round($summary['total_earnings'] - $summary['total_deductions'], 2),
            0.01,
            'Report totals must reconcile the same way the run totals do.'
        );
    }
}
