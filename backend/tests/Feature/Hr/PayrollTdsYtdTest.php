<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrInvestmentDeclaration;
use App\Models\Hr\HrInvestmentDeclarationItem;
use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrPayrollRun;
use App\Models\Hr\HrSalaryComponent;
use App\Models\Hr\HrSalaryStructure;
use App\Models\Hr\HrStatutoryRule;
use App\Services\Hr\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Year-to-date TDS.
 *
 * Every slab, rate and limit below is a TEST FIXTURE picked to make the arithmetic
 * checkable by hand. None is an assertion about current law — the point of each
 * test is that the engine applies whatever it is configured with, reads what was
 * actually paid in earlier months, and refuses to act on unverified claims.
 */
class PayrollTdsYtdTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 1;

    private HrEmployee $employee;

    /** Flat 10% on everything, no cess — so annual tax is exactly 10% of taxable. */
    private array $flatSlabs = [['from' => 0, 'to' => null, 'rate' => 10]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->employee = $this->buildEmployee();
    }

    private function buildEmployee(float $basic = 50000, float $hra = 20000): HrEmployee
    {
        $employee = HrEmployee::create([
            'tenant_id' => $this->tenantId, 'name' => 'YTD Test', 'employee_code' => 'YTD-1',
            'department' => 'Engineering', 'designation' => 'Engineer', 'status' => 'Active',
            'joining_date' => '2020-01-01', 'work_state' => 'Maharashtra',
        ]);

        $mk = fn (string $code, string $name) => HrSalaryComponent::create([
            'tenant_id' => $this->tenantId, 'name' => $name, 'code' => $code, 'type' => 'Earning',
            'calculation_type' => 'Fixed', 'is_active' => true, 'taxable' => true,
            'pf_applicable' => $code === 'BASIC', 'esic_applicable' => false,
        ]);

        $structure = HrSalaryStructure::create([
            'tenant_id' => $this->tenantId, 'name' => 'YTD Structure', 'code' => 'YTD', 'is_active' => true,
        ]);
        $structure->lines()->createMany([
            ['component_id' => $mk('BASIC', 'Basic')->id, 'amount' => $basic, 'calculation_type' => 'Fixed', 'sort_order' => 1],
            ['component_id' => $mk('HRA', 'HRA')->id,     'amount' => $hra,   'calculation_type' => 'Fixed', 'sort_order' => 2],
        ]);

        HrEmployeeSalary::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $employee->id,
            'salary_structure_id' => $structure->id, 'effective_from' => '2026-04-01',
            'annual_ctc' => ($basic + $hra) * 12, 'monthly_ctc' => $basic + $hra,
            'gross_salary' => $basic + $hra, 'total_benefits' => 0, 'total_deductions' => 0,
            'net_salary' => $basic + $hra, 'status' => HrEmployeeSalary::ACTIVE,
        ]);

        return $employee;
    }

    private function tdsRule(array $config): void
    {
        HrStatutoryRule::create([
            'tenant_id' => $this->tenantId, 'rule_type' => 'tds', 'state' => null,
            'effective_from' => '2020-01-01', 'is_active' => true, 'config' => $config,
        ]);
    }

    /** Both regimes in one rule, as a Finance Act would define them. */
    private function bothRegimes(): void
    {
        $this->tdsRule(['regimes' => [
            'new' => ['slabs' => $this->flatSlabs, 'standard_deduction' => 0, 'cess_rate' => 0,
                      'allowed_sections' => []],
            'old' => ['slabs' => $this->flatSlabs, 'standard_deduction' => 0, 'cess_rate' => 0,
                      'allowed_sections' => ['80C', '80D'],
                      'section_limits' => ['80C' => 150000, '80D' => 25000],
                      'hra' => ['salary_percent_metro' => 50, 'salary_percent_non_metro' => 40,
                                'rent_excess_percent' => 10]],
        ]]);
    }

    private function runFor(int $month, int $year = 2026): HrPayrollRecord
    {
        $run = HrPayrollRun::create([
            'tenant_id' => $this->tenantId, 'payroll_month' => $month, 'payroll_year' => $year,
            'status' => HrPayrollRun::DRAFT,
        ]);
        app(PayrollService::class)->process($run->id, $this->tenantId);

        return HrPayrollRecord::where('payroll_run_id', $run->id)->firstOrFail();
    }

    private function declaration(string $regime, array $attrs = [], array $items = []): HrInvestmentDeclaration
    {
        $d = HrInvestmentDeclaration::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $this->employee->id,
            'financial_year' => '2026-2027', 'regime' => $regime,
            'status' => HrInvestmentDeclaration::VERIFIED,
        ] + $attrs);

        foreach ($items as [$section, $amount]) {
            HrInvestmentDeclarationItem::create([
                'tenant_id' => $this->tenantId, 'declaration_id' => $d->id,
                'section' => $section, 'declared_amount' => $amount, 'verified_amount' => $amount,
            ]);
        }

        return $d->load('items');
    }

    /* ── Backward compatibility ───────────────────────────────────────── */

    public function test_no_tds_rule_still_means_no_deduction(): void
    {
        $record = $this->runFor(4);

        $this->assertEquals(0, (float) $record->tds_amount);
        $this->assertNull($record->tax_regime);
    }

    public function test_a_flat_rule_without_regimes_still_applies(): void
    {
        // Rules written before regimes existed have no `regimes` key. They must
        // keep working for every employee rather than resolving to nothing.
        $this->tdsRule(['slabs' => $this->flatSlabs, 'cess_rate' => 0, 'standard_deduction' => 0]);

        $record = $this->runFor(4);

        $this->assertGreaterThan(0, (float) $record->tds_amount);
        $this->assertEquals(84000, (float) $record->annual_tax_liability, '10% of 70,000 x 12');
    }

    /* ── Year-to-date behaviour ───────────────────────────────────────── */

    public function test_april_spreads_the_year_evenly(): void
    {
        $this->bothRegimes();

        $record = $this->runFor(4);

        // 70,000/mo x 12 = 840,000 → 10% = 84,000 over 12 months = 7,000.
        $this->assertEquals(840000, (float) $record->annual_taxable_income);
        $this->assertEquals(84000, (float) $record->annual_tax_liability);
        $this->assertEquals(7000, (float) $record->tds_amount);
        $this->assertSame('2026-2027', $record->financial_year);
    }

    public function test_later_months_deduct_only_the_balance_still_owed(): void
    {
        $this->bothRegimes();

        $april = $this->runFor(4);
        $may   = $this->runFor(5);
        $june  = $this->runFor(6);

        // Each month re-derives the annual figure and divides the REMAINDER by the
        // months left. With an unchanged salary that is a flat 7,000 every month.
        $this->assertEquals(7000, (float) $april->tds_amount);
        $this->assertEquals(7000, (float) $may->tds_amount);
        $this->assertEquals(7000, (float) $june->tds_amount);

        // The year-to-date figures accumulate — this is what projection could not do.
        $this->assertEquals(70000, (float) $april->ytd_taxable_earnings);
        $this->assertEquals(210000, (float) $june->ytd_taxable_earnings, 'three months of pay');
        $this->assertEquals(21000, (float) $june->ytd_tds, 'three months of TDS');
    }

    public function test_a_mid_year_start_does_not_tax_months_that_were_never_paid(): void
    {
        $this->bothRegimes();

        // First run of the year is October — six months already gone.
        $october = $this->runFor(10);

        // Only Oct–Mar exist: 70,000 x 6 = 420,000 → 42,000 tax over 6 months.
        $this->assertEquals(420000, (float) $october->annual_taxable_income,
            'no income is invented for months with no payroll');
        $this->assertEquals(42000, (float) $october->annual_tax_liability);
        $this->assertEquals(7000, (float) $october->tds_amount);
    }

    public function test_tds_already_deducted_reduces_what_remains(): void
    {
        $this->bothRegimes();
        $this->runFor(4);
        $this->runFor(5);

        // Two months paid 7,000 each. March must not re-collect them.
        $march = $this->runFor(3, 2027);

        $detail = json_decode($march->statutory_meta, true)['tds_detail']['working'];
        $this->assertEquals(14000, $detail['tds_already_deducted'], 'April + May are read, not re-charged');
        $this->assertEquals(1, $detail['months_remaining'], 'March is the last month');
    }

    /* ── Regimes ──────────────────────────────────────────────────────── */

    public function test_the_new_regime_ignores_chapter_via_deductions(): void
    {
        $this->bothRegimes();
        $this->declaration('new', [], [['80C', 150000]]);

        $record = $this->runFor(4);

        $this->assertSame('new', $record->tax_regime);
        $this->assertEquals(840000, (float) $record->annual_taxable_income,
            'the new regime enables no sections, so the claim changes nothing');
    }

    public function test_the_old_regime_applies_the_declared_deductions(): void
    {
        $this->bothRegimes();
        $this->declaration('old', [], [['80C', 150000], ['80D', 25000]]);

        $record = $this->runFor(4);

        $this->assertSame('old', $record->tax_regime);
        $this->assertEquals(665000, (float) $record->annual_taxable_income, '840,000 − 175,000');
        $this->assertEquals(66500, (float) $record->annual_tax_liability);
    }

    public function test_a_section_is_capped_at_its_configured_limit(): void
    {
        $this->bothRegimes();
        $this->declaration('old', [], [['80C', 400000]]);   // way over the 150,000 fixture cap

        $record = $this->runFor(4);

        $this->assertEquals(690000, (float) $record->annual_taxable_income, 'only 150,000 is allowed');
    }

    public function test_a_section_the_regime_does_not_enable_is_ignored(): void
    {
        $this->bothRegimes();
        $this->declaration('old', [], [['80E', 50000]]);   // not in the old-regime fixture list

        $record = $this->runFor(4);

        $this->assertEquals(840000, (float) $record->annual_taxable_income);
    }

    /* ── Declaration status gates the benefit ─────────────────────────── */

    public function test_an_unverified_declaration_does_not_reduce_tax(): void
    {
        $this->bothRegimes();
        $d = $this->declaration('old', [], [['80C', 150000]]);
        $d->update(['status' => HrInvestmentDeclaration::SUBMITTED]);

        $record = $this->runFor(4);

        $this->assertEquals(840000, (float) $record->annual_taxable_income,
            'a claim is not evidence — under-deducting all year would leave a bill in March');

        $working = json_decode($record->statutory_meta, true)['tds_detail']['working'];
        $this->assertFalse($working['declaration_counts_for_tax']);
        $this->assertStringContainsString('verified', $working['chapter_via']['reason']);
    }

    public function test_verifying_a_declaration_changes_the_next_month(): void
    {
        $this->bothRegimes();
        $d = $this->declaration('old', [], [['80C', 150000]]);
        $d->update(['status' => HrInvestmentDeclaration::SUBMITTED]);

        $april = $this->runFor(4);
        $d->update(['status' => HrInvestmentDeclaration::VERIFIED]);
        $may = $this->runFor(5);

        $this->assertEquals(840000, (float) $april->annual_taxable_income);
        $this->assertEquals(690000, (float) $may->annual_taxable_income, 'the deduction now applies');
        $this->assertLessThan((float) $april->tds_amount, (float) $may->tds_amount,
            'and the monthly deduction falls');
    }

    /* ── Previous employer ────────────────────────────────────────────── */

    public function test_previous_employer_income_is_added_to_the_year(): void
    {
        $this->bothRegimes();
        $this->declaration('new', ['previous_employer_income' => 300000]);

        $record = $this->runFor(4);

        $this->assertEquals(1140000, (float) $record->annual_taxable_income, '840,000 + 300,000');
        $this->assertEquals(114000, (float) $record->annual_tax_liability);
    }

    public function test_previous_employer_tds_is_credited_not_re_collected(): void
    {
        $this->bothRegimes();
        $this->declaration('new', ['previous_employer_income' => 300000, 'previous_employer_tds' => 30000]);

        $record = $this->runFor(4);

        // Annual tax 114,000 − 30,000 already deducted = 84,000 over 12 months.
        $this->assertEquals(7000, (float) $record->tds_amount);
    }

    /* ── HRA ──────────────────────────────────────────────────────────── */

    public function test_hra_exemption_reduces_taxable_income_under_the_old_regime(): void
    {
        $this->bothRegimes();
        $this->declaration('old', ['hra' => ['rent_paid_annual' => 360000, 'metro' => true, 'months' => 12]]);

        $record = $this->runFor(4);

        // HRA received 20k x 12 = 240,000; 50% of basic 50k x 12 = 300,000;
        // rent 360,000 − 10% of 600,000 = 300,000 → least is 240,000.
        $this->assertEquals(600000, (float) $record->annual_taxable_income, '840,000 − 240,000');

        $working = json_decode($record->statutory_meta, true)['tds_detail']['working'];
        $this->assertEquals(240000, $working['hra_exemption']['amount']);
        $this->assertEquals(240000, $working['hra_exemption']['legs']['hra_received'], 'the binding leg');
    }

    public function test_hra_is_not_exempt_under_a_regime_with_no_hra_config(): void
    {
        $this->bothRegimes();   // the 'new' fixture has no `hra` block
        $this->declaration('new', ['hra' => ['rent_paid_annual' => 360000, 'metro' => true]]);

        $record = $this->runFor(4);

        $this->assertEquals(840000, (float) $record->annual_taxable_income);
    }

    /* ── The working is recorded ──────────────────────────────────────── */

    public function test_the_full_working_is_frozen_onto_the_record(): void
    {
        $this->bothRegimes();
        $this->declaration('old', [], [['80C', 100000]]);

        $record = $this->runFor(4);
        $working = json_decode($record->statutory_meta, true)['tds_detail']['working'];

        // "Why was this deducted?" must be answerable from the record alone.
        foreach (['ytd_taxable_before_this_month', 'this_month_taxable', 'projected_future_months',
                  'previous_employer_income', 'standard_deduction', 'hra_exemption', 'chapter_via',
                  'tds_already_deducted', 'months_remaining', 'declaration_status'] as $key) {
            $this->assertArrayHasKey($key, $working, "the working must record {$key}");
        }
        $this->assertSame('80C', $working['chapter_via']['sections'][0]['section']);
        $this->assertEquals(100000, $working['chapter_via']['sections'][0]['allowed']);
    }
}
