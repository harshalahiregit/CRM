<?php

namespace Tests\Feature\Hr\Statutory;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeDetail;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrPayrollRun;
use App\Models\Hr\HrSalaryComponent;
use App\Models\Hr\HrSalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A whole July payroll, run through the real pipeline, against the filed figures.
 *
 * FiledRegisterTest checks the calculators. This checks everything between an
 * employee record and a register: structures, component flags, the engine, the
 * rules, and the context the payroll service passes through.
 *
 * That last part is why this test exists. The engine reads gender and age from a
 * context the payroll service builds, and the service loads the employee with a
 * CONSTRAINED eager load — so a column left out of that select arrives as null
 * and the rule silently does not fire. Nothing else would catch it: the
 * calculators are right, the rules are right, and the payroll is still wrong.
 */
class JulyPayrollEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'Summit', 'slug' => 'july-e2e', 'status' => 'active']);
        $this->tenantId = $tenant->id;

        $this->admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'a@july.test',
            'password' => Hash::make('x'), 'role' => 'admin', 'status' => 'active',
        ]);

        $this->artisan('hr:seed-salary-components', ['--tenant' => $this->tenantId, '--commit' => true]);
        $this->artisan('hr:seed-statutory-rules', ['--tenant' => $this->tenantId, '--commit' => true, '--from' => '2026-04-01']);
    }

    /**
     * One employee with a structure built from the heads on the salary sheet.
     *
     * @param  array<string, float>  $heads  component code => monthly amount
     */
    private function hire(string $code, string $name, array $heads, string $gender, string $dob): HrEmployee
    {
        $e = HrEmployee::create([
            'tenant_id' => $this->tenantId, 'employee_code' => $code, 'name' => $name,
            'department' => 'Ops', 'designation' => 'Staff',
            'gender' => $gender, 'dob' => $dob,
            'work_state' => 'Maharashtra',
            'joining_date' => '2017-04-01', 'status' => 'Active',
        ]);

        HrEmployeeDetail::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $e->id,
            'uan_number' => '1001127053'.substr($code, -2),
            'pf_number' => '100'.substr($code, -2),
            'esic_number' => '35135064'.substr($code, -2),
        ]);

        $gross = array_sum($heads);

        $structure = HrSalaryStructure::create([
            'tenant_id' => $this->tenantId, 'name' => "Structure {$code}", 'code' => "STR{$code}",
            'monthly_ctc' => $gross, 'annual_ctc' => $gross * 12,
            'gross_salary' => $gross, 'net_salary' => $gross, 'is_active' => true,
        ]);

        foreach ($heads as $componentCode => $amount) {
            $component = HrSalaryComponent::where('tenant_id', $this->tenantId)
                ->where('code', $componentCode)->firstOrFail();

            DB::table('hr_salary_structure_lines')->insert([
                'structure_id' => $structure->id, 'component_id' => $component->id,
                'amount' => $amount, 'calculation_type' => 'Fixed',
                'sort_order' => $component->sequence,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        HrEmployeeSalary::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $e->id,
            'salary_structure_id' => $structure->id, 'effective_from' => '2026-04-01',
            'annual_ctc' => $gross * 12, 'monthly_ctc' => $gross,
            'gross_salary' => $gross, 'net_salary' => $gross,
            'status' => HrEmployeeSalary::ACTIVE,
        ]);

        return $e;
    }

    private function runJuly(): HrPayrollRun
    {
        $run = HrPayrollRun::create([
            'tenant_id' => $this->tenantId, 'payroll_month' => 7, 'payroll_year' => 2026,
            'status' => HrPayrollRun::DRAFT,
        ]);

        app(PayrollService::class)->process($run->id, $this->tenantId, $this->admin);

        return $run->fresh();
    }

    /**
     * SD104, exactly as filed: Basic 14,259 + DA 4,194 + HRA 6,710 + CCA 2,795
     * + Special 20,520 = 48,478 gross.
     *
     * PF wages 15,000 (capped), ESIC wages 18,453 (Basic + DA), PT 200.
     */
    public function test_a_filed_employee_reproduces_through_the_whole_pipeline(): void
    {
        $e = $this->hire('SD104', 'Sanjay Ranga Nandi', [
            'BASIC' => 14259, 'DA' => 4194, 'HRA' => 6710, 'CCA' => 2795, 'SPL' => 20520,
        ], 'Male', '1976-10-08');

        $run = $this->runJuly();
        $r = $run->records()->where('employee_id', $e->id)->firstOrFail();

        $this->assertEquals(48478, $r->gross_salary, 'gross');
        $this->assertEquals(15000, $r->pf_wages, 'PF wages are Basic + DA, capped at the ceiling');
        $this->assertEquals(1800, $r->pf_employee, 'employee PF');
        $this->assertEquals(1250, $r->eps_employer, 'EPS');
        $this->assertEquals(18453, $r->esic_wages, 'ESIC wages are Basic + DA');
        $this->assertEquals(139, $r->esic_employee, 'employee ESIC rounds UP');
        $this->assertEquals(600, $r->esic_employer, 'employer ESIC rounds to nearest');
        $this->assertEquals(200, $r->pt_amount, 'PT');
    }

    /**
     * The over-58 case, through the pipeline.
     *
     * This is the one the constrained eager load would break: without `dob` in
     * the select the engine sees no age, EPS is computed as normal, and the
     * register is wrong for him alone.
     */
    public function test_the_over_58_employee_gets_no_eps(): void
    {
        $e = $this->hire('SD111', 'Vijay Jageshwar Titarmare', [
            'BASIC' => 14000, 'DA' => 4259, 'HRA' => 5000, 'SPL' => 3771,
        ], 'Male', '1966-07-06');

        $run = $this->runJuly();
        $r = $run->records()->where('employee_id', $e->id)->firstOrFail();

        $this->assertEquals(1800, $r->pf_employee, 'he is still a PF member');
        $this->assertEquals(0, $r->eps_employer, 'but past 58 there is no EPS');
    }

    /**
     * A woman under the 25,000 PT threshold pays nothing where a man pays 200.
     *
     * The other case the eager load would break — without `gender` both fall to
     * the gender-neutral reading and she is charged tax she does not owe.
     */
    public function test_the_pt_gender_threshold_survives_the_pipeline(): void
    {
        $woman = $this->hire('SD200', 'Under the threshold', [
            'BASIC' => 12000, 'DA' => 4000, 'HRA' => 4000,
        ], 'Female', '1990-01-01');

        $man = $this->hire('SD201', 'Same salary', [
            'BASIC' => 12000, 'DA' => 4000, 'HRA' => 4000,
        ], 'Male', '1990-01-01');

        $run = $this->runJuly();

        $this->assertEquals(0, $run->records()->where('employee_id', $woman->id)->first()->pt_amount,
            'A woman on 20,000 is below Maharashtra\'s 25,000 threshold');
        $this->assertEquals(200, $run->records()->where('employee_id', $man->id)->first()->pt_amount,
            'A man on the same salary pays');
    }

    /** July is not an LWF month; June is. */
    public function test_lwf_is_absent_in_july_and_present_in_june(): void
    {
        $e = $this->hire('SD104', 'Sanjay', ['BASIC' => 14259, 'DA' => 4194], 'Male', '1976-10-08');

        $july = $this->runJuly();
        $this->assertEquals(0, $july->records()->where('employee_id', $e->id)->first()->lwf_employee,
            'LWF is not deducted in July');

        $june = HrPayrollRun::create([
            'tenant_id' => $this->tenantId, 'payroll_month' => 6, 'payroll_year' => 2026,
            'status' => HrPayrollRun::DRAFT,
        ]);
        app(PayrollService::class)->process($june->id, $this->tenantId, $this->admin);

        $r = $june->fresh()->records()->where('employee_id', $e->id)->first();
        $this->assertEquals(25, $r->lwf_employee, 'June is a deduction month');
        $this->assertEquals(75, $r->lwf_employer);
    }

    /**
     * Voluntary PF is deducted, recorded, and named on the payslip.
     *
     * The PF register has a VPF column and nothing computed it, so it printed
     * zero however much somebody had chosen to save.
     */
    public function test_voluntary_pf_is_deducted_and_shown(): void
    {
        $e = $this->hire('SD300', 'Saves extra', [
            'BASIC' => 14259, 'DA' => 4194, 'HRA' => 6710,
        ], 'Male', '1985-01-01');

        $e->detail->update(['vpf_amount' => 2000]);

        $run = $this->runJuly();
        $r = $run->records()->where('employee_id', $e->id)->firstOrFail();

        $this->assertEquals(2000, $r->vpf_amount, 'VPF is recorded on the run');
        $this->assertEquals(1800, $r->pf_employee, 'and is separate from the statutory 12%');

        \Laravel\Sanctum\Sanctum::actingAs($this->admin);
        $pf = $this->getJson("/api/hr/payroll/runs/{$run->id}/registers/pf")->json('data.register');
        $this->assertEquals(2000, $pf['rows'][0]['vpf'], 'and prints in the register column');
    }

    /** A percentage works too, on the PF wage base. */
    public function test_voluntary_pf_can_be_a_percentage(): void
    {
        $e = $this->hire('SD301', 'Saves a share', [
            'BASIC' => 14259, 'DA' => 4194,
        ], 'Male', '1985-01-01');

        // 10% of the capped PF wages (15,000), not of gross.
        $e->detail->update(['vpf_percent' => 10]);

        $r = $this->runJuly()->records()->where('employee_id', $e->id)->firstOrFail();

        $this->assertEquals(1500, $r->vpf_amount);
    }

    /**
     * A deduction with no line on the payslip is a support call.
     *
     * LWF was already being subtracted from net pay with nothing naming it.
     */
    public function test_lwf_and_vpf_appear_as_named_payslip_lines(): void
    {
        $e = $this->hire('SD302', 'June payslip', [
            'BASIC' => 14259, 'DA' => 4194,
        ], 'Male', '1985-01-01');

        $e->detail->update(['vpf_amount' => 500]);

        $june = HrPayrollRun::create([
            'tenant_id' => $this->tenantId, 'payroll_month' => 6, 'payroll_year' => 2026,
            'status' => HrPayrollRun::DRAFT,
        ]);
        app(PayrollService::class)->process($june->id, $this->tenantId, $this->admin);

        $record = $june->fresh()->records()->where('employee_id', $e->id)->firstOrFail();
        $codes = DB::table('hr_payroll_record_lines')
            ->where('payroll_record_id', $record->id)->pluck('code')->all();

        $this->assertContains('LWF', $codes, 'June deducts LWF, so the payslip must name it');
        $this->assertContains('VPF', $codes, 'and the voluntary contribution too');
    }

    /** And the registers read that run. */
    public function test_the_registers_read_the_processed_run(): void
    {
        $this->hire('SD104', 'Sanjay', [
            'BASIC' => 14259, 'DA' => 4194, 'HRA' => 6710, 'CCA' => 2795, 'SPL' => 20520,
        ], 'Male', '1976-10-08');

        $run = $this->runJuly();

        \Laravel\Sanctum\Sanctum::actingAs($this->admin);

        $pf = $this->getJson("/api/hr/payroll/runs/{$run->id}/registers/pf")->assertOk()->json('data.register');
        $this->assertSame(1, $pf['employees']);
        $this->assertEquals(1250, $pf['rows'][0]['eps']);
        $this->assertEquals(550, $pf['rows'][0]['epf']);

        $esic = $this->getJson("/api/hr/payroll/runs/{$run->id}/registers/esic")->assertOk()->json('data.register');
        $this->assertEquals(18453, $esic['rows'][0]['esic_salary']);
        $this->assertEquals(139, $esic['rows'][0]['employee_contribution']);
    }
}
