<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrPayrollRecordLine;
use App\Models\Hr\HrPayrollRun;
use App\Models\Hr\HrSalaryComponent;
use App\Models\Hr\HrSalaryStructure;
use App\Models\Hr\HrStatutoryRule;
use App\Services\Hr\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Payroll + statutory integration.
 *
 * The regression test is the important one: with NO statutory rules configured,
 * a payroll run must produce exactly the totals it produced before this module
 * existed. Statutory figures may only appear once someone configures them.
 */
class PayrollStatutoryTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 1;

    private function buildSalary(float $basic = 20000, float $hra = 8000): HrEmployeeSalary
    {
        $employee = HrEmployee::create([
            'tenant_id' => $this->tenantId, 'name' => 'Statutory Test', 'employee_code' => 'STT-1',
            'department' => 'Engineering', 'designation' => 'Engineer', 'status' => 'Active',
            'joining_date' => '2020-01-01',
            // A city in `location`, the jurisdiction in `work_state` — PT reads only
            // the latter. See PayrollWorkStateTest for that boundary in detail.
            'location' => 'Pune', 'work_state' => 'Maharashtra',
        ]);

        $mk = fn (string $code, string $name, string $type, array $flags = []) => HrSalaryComponent::create(array_merge([
            'tenant_id' => $this->tenantId, 'name' => $name, 'code' => $code, 'type' => $type,
            'calculation_type' => 'Fixed', 'is_active' => true,
        ], $flags));

        $basicC = $mk('BASIC', 'Basic', 'Earning', ['taxable' => true, 'pf_applicable' => true, 'esic_applicable' => true]);
        $hraC   = $mk('HRA', 'HRA', 'Earning', ['taxable' => true, 'pf_applicable' => false, 'esic_applicable' => true]);

        $structure = HrSalaryStructure::create([
            'tenant_id' => $this->tenantId, 'name' => 'Test Structure', 'code' => 'TS1', 'is_active' => true,
        ]);
        $structure->lines()->createMany([
            ['component_id' => $basicC->id, 'amount' => $basic, 'calculation_type' => 'Fixed', 'sort_order' => 1],
            ['component_id' => $hraC->id,   'amount' => $hra,   'calculation_type' => 'Fixed', 'sort_order' => 2],
        ]);

        return HrEmployeeSalary::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $employee->id,
            'salary_structure_id' => $structure->id, 'effective_from' => now()->startOfYear(),
            'annual_ctc' => ($basic + $hra) * 12, 'monthly_ctc' => $basic + $hra,
            'gross_salary' => $basic + $hra, 'total_benefits' => 0, 'total_deductions' => 0,
            'net_salary' => $basic + $hra, 'status' => HrEmployeeSalary::ACTIVE,
        ]);
    }

    private function payrollRun(): HrPayrollRun
    {
        return HrPayrollRun::create([
            'tenant_id' => $this->tenantId, 'payroll_month' => 6, 'payroll_year' => 2026,
            'status' => HrPayrollRun::DRAFT,
        ]);
    }

    private function rule(string $type, array $config, ?string $state = null): void
    {
        HrStatutoryRule::create([
            'tenant_id' => $this->tenantId, 'rule_type' => $type, 'state' => $state,
            'effective_from' => '2020-01-01', 'config' => $config, 'is_active' => true,
        ]);
    }

    /* ── Regression: unconfigured tenant is unchanged ─────────────────── */

    public function test_payroll_runs_unchanged_when_no_statutory_rules_exist(): void
    {
        $salary = $this->buildSalary();
        $run    = $this->payrollRun();

        app(PayrollService::class)->process($run->id, $this->tenantId);

        $record = HrPayrollRecord::where('payroll_run_id', $run->id)->first();

        $this->assertNotNull($record, 'the run must still process');
        $this->assertEquals($salary->gross_salary, $record->gross_salary, 'existing totals untouched');
        $this->assertEquals($salary->net_salary, $record->net_salary);

        // Every statutory figure stays zero — nothing is invented.
        foreach (['pf_employee', 'esic_employee', 'pt_amount', 'tds_amount', 'statutory_deductions'] as $c) {
            $this->assertEquals(0, (float) $record->$c, "$c must be zero when unconfigured");
        }
    }

    public function test_component_breakdown_is_frozen_against_the_record(): void
    {
        $this->buildSalary(basic: 20000, hra: 8000);
        $this->rule('pf', ['employee_rate' => 12, 'employer_rate' => 12,
                           'wage_ceiling' => 15000, 'restrict_to_ceiling' => true]);

        $run = $this->payrollRun();
        app(PayrollService::class)->process($run->id, $this->tenantId);
        $record = HrPayrollRecord::where('payroll_run_id', $run->id)->first();

        $lines = HrPayrollRecordLine::where('payroll_record_id', $record->id)->get();

        // Structure components are frozen…
        $structure = $lines->where('source', 'structure');
        $this->assertCount(2, $structure, 'Basic + HRA stored as lines');
        $this->assertEquals(20000, (float) $structure->firstWhere('code', 'BASIC')->amount);
        $this->assertTrue((bool) $structure->firstWhere('code', 'BASIC')->pf_applicable,
            'the component flags travel with the frozen line');

        // …and the statutory deduction is appended as its own line.
        $pf = $lines->firstWhere('code', 'PF_EE');
        $this->assertNotNull($pf, 'PF appears as a statutory line');
        $this->assertSame('statutory', $pf->source);
        $this->assertEquals(1800, (float) $pf->amount);
        $this->assertSame('Deduction', $pf->type);
    }

    /* ── Configured tenant ────────────────────────────────────────────── */

    public function test_statutory_amounts_are_stored_once_rules_are_configured(): void
    {
        $this->buildSalary(basic: 20000, hra: 8000);   // gross 28,000; PF wages 20,000

        $this->rule('pf', ['employee_rate' => 12, 'employer_rate' => 12, 'eps_rate' => 8.33,
                           'wage_ceiling' => 15000, 'restrict_to_ceiling' => true]);
        $this->rule('pt', ['slabs' => [['from' => 0, 'to' => null, 'amount' => 200]]], 'Maharashtra');

        $run = $this->payrollRun();
        app(PayrollService::class)->process($run->id, $this->tenantId);

        $record = HrPayrollRecord::where('payroll_run_id', $run->id)->first();

        $this->assertEquals(15000, (float) $record->pf_wages, 'PF capped at the ceiling');
        $this->assertEquals(1800, (float) $record->pf_employee);
        $this->assertEquals(200, (float) $record->pt_amount, 'PT resolved by the employee state');
        $this->assertEquals(2000, (float) $record->statutory_deductions, 'PF 1800 + PT 200');
    }

    public function test_esic_is_skipped_above_the_threshold_and_recorded_as_such(): void
    {
        $this->buildSalary(basic: 40000, hra: 20000);   // gross 60,000
        $this->rule('esic', ['gross_threshold' => 21000, 'employee_rate' => 0.75, 'employer_rate' => 3.25]);

        $run = $this->payrollRun();
        app(PayrollService::class)->process($run->id, $this->tenantId);
        $record = HrPayrollRecord::where('payroll_run_id', $run->id)->first();

        $this->assertEquals(0, (float) $record->esic_employee);

        $meta = json_decode($record->statutory_meta, true);
        $this->assertSame('Gross above the ESIC threshold', $meta['esic'], 'the reason is recorded for audit');
    }

    public function test_employer_contributions_are_not_deducted_from_the_employee(): void
    {
        $this->buildSalary(basic: 20000, hra: 8000);
        $this->rule('pf', ['employee_rate' => 12, 'employer_rate' => 12,
                           'wage_ceiling' => 15000, 'restrict_to_ceiling' => true]);

        $run = $this->payrollRun();
        app(PayrollService::class)->process($run->id, $this->tenantId);
        $record = HrPayrollRecord::where('payroll_run_id', $run->id)->first();

        $this->assertEquals(1800, (float) $record->pf_employer, 'employer share recorded');
        $this->assertEquals(1800, (float) $record->statutory_deductions,
            'only the EMPLOYEE share counts as a deduction');
    }
}
