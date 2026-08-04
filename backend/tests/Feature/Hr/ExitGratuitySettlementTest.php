<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrExitClearance;
use App\Models\Hr\HrExitPolicy;
use App\Models\Hr\HrExitRequest;
use App\Models\Hr\HrExitSettlement;
use App\Models\Hr\HrExitType;
use App\Models\Hr\HrSalaryComponent;
use App\Models\Hr\HrSalaryStructure;
use App\Models\Hr\HrStatutoryRule;
use App\Services\Hr\ExitSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gratuity on exit is now computed by the configurable statutory rule instead of a
 * formula hardcoded in the settlement service.
 *
 * Two things are protected here: that a configured rule genuinely drives the
 * figure (including its CEILING, which the old hardcoded formula had none of), and
 * that a tenant who has configured nothing gets exactly the amount they got
 * before — no settlement silently changes value on upgrade.
 *
 * The figures are TEST FIXTURES, not assertions about current law.
 */
class ExitGratuitySettlementTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 1;

    private function scenario(bool $gratuityApplicable = true, string $joined = '2016-01-01', float $basic = 52000): HrExitSettlement
    {
        $employee = HrEmployee::create([
            'tenant_id' => $this->tenantId, 'name' => 'Leaver', 'employee_code' => 'EX-1',
            'department' => 'Engineering', 'designation' => 'Engineer', 'status' => 'Active',
            'joining_date' => $joined,
        ]);

        $basicC = HrSalaryComponent::create([
            'tenant_id' => $this->tenantId, 'name' => 'Basic', 'code' => 'BASIC', 'type' => 'Earning',
            'calculation_type' => 'Fixed', 'is_active' => true, 'taxable' => true, 'pf_applicable' => true,
        ]);
        $structure = HrSalaryStructure::create([
            'tenant_id' => $this->tenantId, 'name' => 'Exit Structure', 'code' => 'EXS', 'is_active' => true,
        ]);
        $structure->lines()->create([
            'component_id' => $basicC->id, 'amount' => $basic, 'calculation_type' => 'Fixed', 'sort_order' => 1,
        ]);

        HrEmployeeSalary::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $employee->id,
            'salary_structure_id' => $structure->id, 'effective_from' => '2020-01-01',
            'annual_ctc' => $basic * 12, 'monthly_ctc' => $basic, 'gross_salary' => $basic,
            'total_benefits' => 0, 'total_deductions' => 0, 'net_salary' => $basic,
            'status' => HrEmployeeSalary::ACTIVE,
        ]);

        $policy = HrExitPolicy::create([
            'tenant_id' => $this->tenantId, 'name' => 'Standard', 'notice_days' => 30,
            'gratuity_applicable' => $gratuityApplicable, 'leave_encashment' => false,
            'recovery_allowed' => false, 'buyout_allowed' => false, 'is_active' => true,
        ]);
        $type = HrExitType::create([
            'tenant_id' => $this->tenantId, 'name' => 'Resignation', 'code' => 'RESIGN', 'is_active' => true,
        ]);

        $exit = HrExitRequest::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $employee->id,
            'exit_type_id' => $type->id, 'exit_policy_id' => $policy->id,
            'request_date' => '2022-01-01', 'last_working_date' => '2022-01-31',
            'notice_days' => 30, 'status' => 'Approved',
        ]);
        $clearance = HrExitClearance::create([
            'tenant_id' => $this->tenantId, 'exit_request_id' => $exit->id,
            'employee_id' => $employee->id, 'status' => HrExitClearance::COMPLETED,
        ]);

        return HrExitSettlement::create([
            'tenant_id' => $this->tenantId, 'exit_request_id' => $exit->id,
            'clearance_id' => $clearance->id, 'employee_id' => $employee->id,
            'status' => HrExitSettlement::PENDING,
        ]);
    }

    private function gratuityRule(array $config): void
    {
        HrStatutoryRule::create([
            'tenant_id' => $this->tenantId, 'rule_type' => 'gratuity', 'state' => null,
            'effective_from' => '2020-01-01', 'is_active' => true, 'config' => $config,
        ]);
    }

    private function generate(HrExitSettlement $settlement): array
    {
        return app(ExitSettlementService::class)->generate($settlement->id, [], $this->tenantId);
    }

    /* ── The configured rule drives the figure ────────────────────────── */

    public function test_a_configured_rule_computes_the_gratuity(): void
    {
        $settlement = $this->scenario();   // 6 years of service, basic 52,000
        $this->gratuityRule(['days_per_year' => 15, 'month_days' => 26, 'min_years' => 5, 'max_amount' => 2000000]);

        $out = $this->generate($settlement);
        $c = $out['components'];

        // 52,000 x 15/26 x 6 = 180,000
        $this->assertEqualsWithDelta(180000, $c['earnings']['gratuity'], 0.01);
        $this->assertSame('statutory_rule', $c['context']['gratuity_basis']['source']);
        $this->assertSame(6, $c['context']['gratuity_basis']['eligible_years']);
    }

    public function test_a_different_configured_formula_produces_a_different_figure(): void
    {
        $settlement = $this->scenario();
        // 30/30 instead of 15/26 — the engine must follow config, not tradition.
        $this->gratuityRule(['days_per_year' => 30, 'month_days' => 30, 'min_years' => 5, 'max_amount' => 5000000]);

        $c = $this->generate($settlement)['components'];

        $this->assertEqualsWithDelta(312000, $c['earnings']['gratuity'], 0.01, '52,000 x 1 x 6');
    }

    public function test_the_configured_ceiling_is_applied(): void
    {
        $settlement = $this->scenario();
        $this->gratuityRule(['days_per_year' => 15, 'month_days' => 26, 'min_years' => 5, 'max_amount' => 100000]);

        $c = $this->generate($settlement)['components'];

        // The hardcoded formula this replaced had NO ceiling — this is the bug fix.
        $this->assertEquals(100000, $c['earnings']['gratuity']);
    }

    public function test_service_below_the_configured_minimum_pays_nothing(): void
    {
        $settlement = $this->scenario(joined: '2020-01-01');   // 2 years
        $this->gratuityRule(['days_per_year' => 15, 'month_days' => 26, 'min_years' => 5, 'max_amount' => 2000000]);

        $c = $this->generate($settlement)['components'];

        $this->assertEquals(0, $c['earnings']['gratuity']);
        $this->assertStringContainsString('5-year minimum', $c['context']['gratuity_basis']['reason']);
    }

    public function test_a_lower_configured_minimum_lets_a_shorter_tenure_qualify(): void
    {
        $settlement = $this->scenario(joined: '2019-01-01');   // 3 years
        $this->gratuityRule(['days_per_year' => 15, 'month_days' => 26, 'min_years' => 3, 'max_amount' => 2000000]);

        $c = $this->generate($settlement)['components'];

        $this->assertEqualsWithDelta(90000, $c['earnings']['gratuity'], 0.01, '52,000 x 15/26 x 3');
    }

    /* ── Backward compatibility ───────────────────────────────────────── */

    public function test_with_no_rule_configured_the_legacy_figure_is_unchanged(): void
    {
        $settlement = $this->scenario();   // 6 years, basic 52,000

        $c = $this->generate($settlement)['components'];

        // Exactly what the old hardcoded 15/26 x floor(years) produced.
        $this->assertEqualsWithDelta(180000, $c['earnings']['gratuity'], 0.01);
        $this->assertSame('legacy_default', $c['context']['gratuity_basis']['source'],
            'flagged, not silently accepted — a legal figure in code is a defect');
        $this->assertStringContainsString('configure a Gratuity statutory rule',
            $c['context']['gratuity_basis']['reason']);
    }

    public function test_the_legacy_path_keeps_the_five_year_gate(): void
    {
        $settlement = $this->scenario(joined: '2020-06-01');   // under 2 years

        $c = $this->generate($settlement)['components'];

        $this->assertEquals(0, $c['earnings']['gratuity']);
        $this->assertSame('legacy_default', $c['context']['gratuity_basis']['source']);
    }

    /* ── The policy still decides whether gratuity applies at all ─────── */

    public function test_a_policy_without_gratuity_pays_none_however_it_is_configured(): void
    {
        $settlement = $this->scenario(gratuityApplicable: false);
        $this->gratuityRule(['days_per_year' => 15, 'month_days' => 26, 'min_years' => 1, 'max_amount' => 2000000]);

        $c = $this->generate($settlement)['components'];

        $this->assertEquals(0, $c['earnings']['gratuity']);
        $this->assertNull($c['context']['gratuity_basis']['source']);
        $this->assertStringContainsString('policy', $c['context']['gratuity_basis']['reason']);
    }

    public function test_gratuity_flows_into_the_settlement_totals(): void
    {
        $settlement = $this->scenario();
        $this->gratuityRule(['days_per_year' => 15, 'month_days' => 26, 'min_years' => 5, 'max_amount' => 2000000]);

        $out = $this->generate($settlement);

        $this->assertGreaterThanOrEqual(180000, (float) $out['gross_earnings']);
        $this->assertEquals((float) $out['gross_earnings'], (float) $out['net_settlement'],
            'no recoveries in this scenario');
    }
}
