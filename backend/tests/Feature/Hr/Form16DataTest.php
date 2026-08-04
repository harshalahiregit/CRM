<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrInvestmentDeclaration;
use App\Models\Hr\HrInvestmentDeclarationItem;
use App\Models\Hr\HrPayrollRun;
use App\Models\Hr\HrSalaryComponent;
use App\Models\Hr\HrSalaryStructure;
use App\Models\Hr\HrStatutoryRule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Form-16-READY data.
 *
 * It is assembled from FROZEN payroll records, never recomputed — so a rule change
 * next April cannot alter what a past year reports. The warnings matter as much as
 * the figures: a part-processed year or an unverified declaration must say so
 * rather than present year-to-date numbers as final.
 */
class Form16DataTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private HrEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT, 999] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => 'Tenant '.$id, 'slug' => 'tenant-'.$id,
                'subdomain' => 'tenant'.$id, 'status' => 'active',
            ])->save();
        }

        $this->employee = HrEmployee::create([
            'tenant_id' => self::TENANT, 'name' => 'Taxpayer', 'employee_code' => 'F16-1',
            'department' => 'Engineering', 'designation' => 'Engineer', 'status' => 'Active',
            'joining_date' => '2020-01-01', 'work_state' => 'Maharashtra',
        ]);

        $mk = fn (string $code, string $name) => HrSalaryComponent::create([
            'tenant_id' => self::TENANT, 'name' => $name, 'code' => $code, 'type' => 'Earning',
            'calculation_type' => 'Fixed', 'is_active' => true, 'taxable' => true,
            'pf_applicable' => $code === 'BASIC', 'esic_applicable' => false,
        ]);
        $structure = HrSalaryStructure::create([
            'tenant_id' => self::TENANT, 'name' => 'F16', 'code' => 'F16', 'is_active' => true,
        ]);
        $structure->lines()->createMany([
            ['component_id' => $mk('BASIC', 'Basic')->id, 'amount' => 50000, 'calculation_type' => 'Fixed', 'sort_order' => 1],
            ['component_id' => $mk('HRA', 'HRA')->id,     'amount' => 20000, 'calculation_type' => 'Fixed', 'sort_order' => 2],
        ]);
        HrEmployeeSalary::create([
            'tenant_id' => self::TENANT, 'employee_id' => $this->employee->id,
            'salary_structure_id' => $structure->id, 'effective_from' => '2026-04-01',
            'annual_ctc' => 840000, 'monthly_ctc' => 70000, 'gross_salary' => 70000,
            'total_benefits' => 0, 'total_deductions' => 0, 'net_salary' => 70000,
            'status' => HrEmployeeSalary::ACTIVE,
        ]);
    }

    private function actAsHr(int $tenantId = self::TENANT): void
    {
        Sanctum::actingAs(User::create([
            'tenant_id' => $tenantId, 'name' => 'HR', 'email' => 'hr'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]));
    }

    private function taxRules(): void
    {
        HrStatutoryRule::create([
            'tenant_id' => self::TENANT, 'rule_type' => 'tds', 'state' => null,
            'effective_from' => '2020-01-01', 'is_active' => true,
            'config' => ['regimes' => [
                'old' => ['slabs' => [['from' => 0, 'to' => null, 'rate' => 10]], 'cess_rate' => 0,
                          'standard_deduction' => 50000, 'allowed_sections' => ['80C'],
                          'section_limits' => ['80C' => 150000]],
                'new' => ['slabs' => [['from' => 0, 'to' => null, 'rate' => 10]], 'cess_rate' => 0,
                          'standard_deduction' => 0, 'allowed_sections' => []],
            ]],
        ]);
        HrStatutoryRule::create([
            'tenant_id' => self::TENANT, 'rule_type' => 'pt', 'state' => 'Maharashtra',
            'effective_from' => '2020-01-01', 'is_active' => true,
            'config' => ['slabs' => [['from' => 0, 'to' => null, 'amount' => 200]]],
        ]);
    }

    private function process(int $month, int $year = 2026): void
    {
        $run = HrPayrollRun::create([
            'tenant_id' => self::TENANT, 'payroll_month' => $month, 'payroll_year' => $year,
            'status' => HrPayrollRun::DRAFT,
        ]);
        app(PayrollService::class)->process($run->id, self::TENANT);
    }

    private function verifiedDeclaration(): void
    {
        $d = HrInvestmentDeclaration::create([
            'tenant_id' => self::TENANT, 'employee_id' => $this->employee->id,
            'financial_year' => '2026-2027', 'regime' => 'old',
            'status' => HrInvestmentDeclaration::VERIFIED,
        ]);
        HrInvestmentDeclarationItem::create([
            'tenant_id' => self::TENANT, 'declaration_id' => $d->id,
            'section' => '80C', 'declared_amount' => 150000, 'verified_amount' => 150000,
        ]);
    }

    private function fetch(): array
    {
        return $this->getJson("/api/hr/payroll/form16/{$this->employee->id}?financial_year=2026-2027")
            ->assertOk()->json();
    }

    /* ── Assembly ─────────────────────────────────────────────────────── */

    public function test_it_sums_the_processed_months(): void
    {
        $this->actAsHr();
        $this->taxRules();
        $this->verifiedDeclaration();
        $this->process(4);
        $this->process(5);
        $this->process(6);

        $f16 = $this->fetch();

        $this->assertSame(3, $f16['months_processed']);
        $this->assertEquals(210000, $f16['salary']['gross_salary_this_employer'], '70,000 x 3');
        $this->assertCount(3, $f16['monthly'], 'a month-by-month annexure');
        $this->assertEquals(600, $f16['other']['professional_tax'], '200 x 3');
    }

    public function test_it_reports_the_regime_and_the_chapter_via_breakdown(): void
    {
        $this->actAsHr();
        $this->taxRules();
        $this->verifiedDeclaration();
        $this->process(4);

        $f16 = $this->fetch();

        $this->assertSame('old', $f16['regime']);
        $this->assertSame('80C', $f16['chapter_via']['sections'][0]['section']);
        $this->assertEquals(150000, $f16['chapter_via']['total']);
        $this->assertEquals(50000, $f16['salary']['standard_deduction']);
        // 840,000 − 50,000 standard − 150,000 under 80C
        $this->assertEquals(640000, $f16['tax']['taxable_income']);
    }

    public function test_previous_employer_income_appears_in_the_gross_total(): void
    {
        $this->actAsHr();
        $this->taxRules();
        HrInvestmentDeclaration::create([
            'tenant_id' => self::TENANT, 'employee_id' => $this->employee->id,
            'financial_year' => '2026-2027', 'regime' => 'new',
            'status' => HrInvestmentDeclaration::VERIFIED,
            'previous_employer_income' => 300000, 'previous_employer_tds' => 25000,
        ]);
        $this->process(4);

        $f16 = $this->fetch();

        $this->assertEquals(70000, $f16['salary']['gross_salary_this_employer']);
        $this->assertEquals(300000, $f16['salary']['previous_employer_income']);
        $this->assertEquals(370000, $f16['salary']['gross_total_salary']);
        $this->assertEquals(25000, $f16['tds']['deducted_previous']);
    }

    public function test_the_tds_balance_reconciles_against_the_liability(): void
    {
        $this->actAsHr();
        $this->taxRules();
        $this->verifiedDeclaration();
        $this->process(4);

        $f16 = $this->fetch();

        $expected = round($f16['tax']['tax_liability'] - $f16['tds']['total_deducted'], 2);
        $this->assertEquals($expected, $f16['tds']['balance_payable'],
            'what is still owed after everything deducted so far');
    }

    public function test_the_assessment_year_follows_the_financial_year(): void
    {
        $this->actAsHr();
        $this->taxRules();
        $this->process(4);

        $f16 = $this->fetch();

        $this->assertSame('2026-2027', $f16['financial_year']['label']);
        $this->assertSame('2027-28', $f16['financial_year']['assessment_year']);
        $this->assertSame('2026-04-01', $f16['financial_year']['from']);
        $this->assertSame('2027-03-31', $f16['financial_year']['to']);
    }

    /* ── Honesty about what it is ─────────────────────────────────────── */

    public function test_it_states_that_it_is_not_a_form_16(): void
    {
        $this->actAsHr();

        $this->assertStringContainsString('not a Form 16', $this->fetch()['disclaimer']);
    }

    public function test_a_part_processed_year_is_flagged_as_provisional(): void
    {
        $this->actAsHr();
        $this->taxRules();
        $this->process(4);

        $warnings = implode(' ', $this->fetch()['warnings']);

        $this->assertStringContainsString('1 of 12 months', $warnings,
            'year-to-date figures must not be mistaken for final ones');
    }

    public function test_an_unverified_declaration_is_flagged(): void
    {
        $this->actAsHr();
        $this->taxRules();
        HrInvestmentDeclaration::create([
            'tenant_id' => self::TENANT, 'employee_id' => $this->employee->id,
            'financial_year' => '2026-2027', 'regime' => 'old',
            'status' => HrInvestmentDeclaration::SUBMITTED,
        ]);
        $this->process(4);

        $warnings = implode(' ', $this->fetch()['warnings']);

        $this->assertStringContainsString('Submitted', $warnings);
        $this->assertStringContainsString('did not reduce tax', $warnings);
    }

    public function test_a_year_with_no_payroll_returns_zeros_and_says_so(): void
    {
        $this->actAsHr();

        $f16 = $this->fetch();

        $this->assertSame(0, $f16['months_processed']);
        $this->assertEquals(0, $f16['salary']['gross_salary_this_employer']);
        $this->assertStringContainsString('No processed payroll', implode(' ', $f16['warnings']));
    }

    /* ── Years + tenancy ──────────────────────────────────────────────── */

    public function test_available_years_come_from_processed_payroll(): void
    {
        $this->actAsHr();
        $this->taxRules();
        $this->process(4);
        $this->process(3, 2027);   // still 2026-2027

        $years = $this->getJson("/api/hr/payroll/form16/{$this->employee->id}/years")
            ->assertOk()->json('data');

        $this->assertCount(1, $years, 'April 2026 and March 2027 are the same financial year');
        $this->assertSame('2026-2027', $years[0]['label']);
    }

    public function test_one_tenant_cannot_read_another_tenants_form16(): void
    {
        $this->actAsHr(999);

        $this->getJson("/api/hr/payroll/form16/{$this->employee->id}")->assertStatus(404);
    }
}
