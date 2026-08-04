<?php

namespace Tests\Unit\Hr;

use App\Services\Hr\Statutory\BonusCalculator;
use App\Services\Hr\Statutory\EsicCalculator;
use App\Services\Hr\Statutory\GratuityCalculator;
use App\Services\Hr\Statutory\PfCalculator;
use App\Services\Hr\Statutory\PtCalculator;
use App\Services\Hr\Statutory\TdsCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The statutory calculators, driven purely by config.
 *
 * The rates used below are TEST FIXTURES chosen to make the arithmetic checkable —
 * they are not an assertion about current law. The point of every test is that the
 * calculator applies whatever config it is given, and produces ZERO (with a reason)
 * when given none.
 */
class StatutoryCalculatorsTest extends TestCase
{
    /* ── PF ─────────────────────────────────────────────────────────── */

    public function test_pf_is_zero_and_explains_itself_when_unconfigured(): void
    {
        $r = (new PfCalculator)->calculate(50000, null);

        $this->assertFalse($r['applicable']);
        $this->assertSame(0.0, $r['employee']);
        $this->assertSame('PF not configured', $r['reason']);
    }

    public function test_pf_caps_wages_at_the_ceiling_when_restricted(): void
    {
        $cfg = ['employee_rate' => 12, 'employer_rate' => 12, 'wage_ceiling' => 15000, 'restrict_to_ceiling' => true];

        $r = (new PfCalculator)->calculate(50000, $cfg);

        $this->assertSame(15000.0, $r['wages'], 'wages must be capped at the ceiling');
        $this->assertSame(1800.0, $r['employee']);   // 12% of 15,000
    }

    public function test_pf_uses_actual_wages_when_not_restricted(): void
    {
        $cfg = ['employee_rate' => 12, 'employer_rate' => 12, 'wage_ceiling' => 15000, 'restrict_to_ceiling' => false];

        $r = (new PfCalculator)->calculate(50000, $cfg);

        $this->assertSame(50000.0, $r['wages']);
        $this->assertSame(6000.0, $r['employee']);
    }

    public function test_eps_is_carved_out_of_the_employer_share_not_added_to_it(): void
    {
        $cfg = ['employee_rate' => 12, 'employer_rate' => 12, 'eps_rate' => 8.33,
                'wage_ceiling' => 15000, 'restrict_to_ceiling' => true];

        $r = (new PfCalculator)->calculate(15000, $cfg);

        $this->assertSame(1800.0, $r['employer']);
        $this->assertEqualsWithDelta(1249.5, $r['eps'], 0.01);
        $this->assertEqualsWithDelta($r['employer'], $r['eps'] + $r['epf'], 0.01, 'EPS + EPF must equal the employer share');
    }

    /* ── ESIC ───────────────────────────────────────────────────────── */

    public function test_esic_does_not_apply_above_the_gross_threshold(): void
    {
        $cfg = ['gross_threshold' => 21000, 'employee_rate' => 0.75, 'employer_rate' => 3.25];

        $r = (new EsicCalculator)->calculate(25000, 25000, $cfg);

        $this->assertFalse($r['applicable']);
        $this->assertSame(0.0, $r['employee']);
        $this->assertSame('Gross above the ESIC threshold', $r['reason']);
    }

    public function test_esic_applies_at_or_below_the_threshold(): void
    {
        $cfg = ['gross_threshold' => 21000, 'employee_rate' => 0.75, 'employer_rate' => 3.25];

        $r = (new EsicCalculator)->calculate(20000, 20000, $cfg);

        $this->assertTrue($r['applicable']);
        $this->assertSame(150.0, $r['employee']);   // 0.75%
        $this->assertSame(650.0, $r['employer']);   // 3.25%
    }

    public function test_esic_boundary_is_inclusive(): void
    {
        $cfg = ['gross_threshold' => 21000, 'employee_rate' => 0.75, 'employer_rate' => 3.25];

        $this->assertTrue((new EsicCalculator)->calculate(21000, 21000, $cfg)['applicable']);
    }

    /* ── PT ─────────────────────────────────────────────────────────── */

    public function test_pt_picks_the_matching_slab(): void
    {
        $cfg = ['slabs' => [
            ['from' => 0,     'to' => 7500,  'amount' => 0],
            ['from' => 7501,  'to' => 10000, 'amount' => 175],
            ['from' => 10001, 'to' => null,  'amount' => 200],
        ]];

        $pt = new PtCalculator;
        $this->assertSame(0.0,   $pt->calculate(5000, $cfg, null, 'Maharashtra')['amount']);
        $this->assertSame(175.0, $pt->calculate(9000, $cfg, null, 'Maharashtra')['amount']);
        $this->assertSame(200.0, $pt->calculate(50000, $cfg, null, 'Maharashtra')['amount']);
    }

    public function test_pt_month_override_applies_only_in_that_month(): void
    {
        $cfg = ['slabs' => [['from' => 10001, 'to' => null, 'amount' => 200]],
                'month_overrides' => ['2' => 300]];

        $pt = new PtCalculator;
        $this->assertSame(300.0, $pt->calculate(50000, $cfg, 2, 'Maharashtra')['amount'], 'February override');
        $this->assertSame(200.0, $pt->calculate(50000, $cfg, 3, 'Maharashtra')['amount']);
    }

    public function test_pt_is_zero_when_the_state_has_no_rule(): void
    {
        $r = (new PtCalculator)->calculate(50000, null, null, 'Karnataka');

        $this->assertFalse($r['applicable']);
        $this->assertSame('PT not configured for Karnataka', $r['reason'],
            'the reason names the state, so the fix is obvious');
    }

    public function test_pt_without_a_work_state_says_so_rather_than_blaming_the_config(): void
    {
        // "PT not configured" would send someone to the rules screen; the actual
        // fix is on the employee record. The two causes must not share a message.
        $cfg = ['slabs' => [['from' => 0, 'to' => null, 'amount' => 200]]];

        $r = (new PtCalculator)->calculate(50000, $cfg, null, null);

        $this->assertFalse($r['applicable']);
        $this->assertSame(0.0, $r['amount']);
        $this->assertStringContainsString('Work state not set', $r['reason']);
    }

    /* ── Bonus ──────────────────────────────────────────────────────── */

    public function test_bonus_respects_eligibility_and_calculation_ceilings(): void
    {
        $cfg = ['rate' => 8.33, 'eligibility_gross_ceiling' => 21000, 'calculation_ceiling' => 7000];
        $b = new BonusCalculator;

        $high = $b->calculate(30000, 30000, $cfg);
        $this->assertFalse($high['applicable'], 'above the eligibility ceiling');

        $ok = $b->calculate(20000, 20000, $cfg);
        $this->assertTrue($ok['applicable']);
        $this->assertSame(7000.0, $ok['wages'], 'wage base capped by calculation_ceiling');
        $this->assertEqualsWithDelta(583.1, $ok['amount'], 0.01);
    }

    /* ── Gratuity ───────────────────────────────────────────────────── */

    public function test_gratuity_settlement_requires_the_minimum_service(): void
    {
        $cfg = ['days_per_year' => 15, 'month_days' => 26, 'min_years' => 5, 'max_amount' => 2000000];

        $r = (new GratuityCalculator)->settlement(50000, 48, $cfg);   // 4 years

        $this->assertFalse($r['applicable']);
        $this->assertStringContainsString('5-year minimum', $r['reason']);
    }

    public function test_gratuity_settlement_uses_the_configured_formula(): void
    {
        $cfg = ['days_per_year' => 15, 'month_days' => 26, 'min_years' => 5, 'max_amount' => 2000000];

        $r = (new GratuityCalculator)->settlement(52000, 72, $cfg);   // 6 years

        // 52000 * 15/26 * 6 = 180,000
        $this->assertTrue($r['applicable']);
        $this->assertEqualsWithDelta(180000.0, $r['amount'], 0.01);
        $this->assertSame(6, $r['eligible_years']);
    }

    public function test_gratuity_settlement_is_capped(): void
    {
        $cfg = ['days_per_year' => 15, 'month_days' => 26, 'min_years' => 5, 'max_amount' => 2000000];

        $r = (new GratuityCalculator)->settlement(500000, 480, $cfg);   // 40 years

        $this->assertSame(2000000.0, $r['amount'], 'capped at max_amount');
    }

    public function test_gratuity_provision_is_separate_from_settlement(): void
    {
        $r = (new GratuityCalculator)->provision(50000, ['rate' => 4.81]);

        $this->assertTrue($r['applicable']);
        $this->assertEqualsWithDelta(2405.0, $r['amount'], 0.01);
    }

    /* ── TDS ────────────────────────────────────────────────────────── */

    public function test_tds_is_zero_when_unconfigured(): void
    {
        $r = (new TdsCalculator)->calculate(100000, 12, null);

        $this->assertFalse($r['applicable']);
        $this->assertSame(0.0, $r['monthly_tds']);
    }

    public function test_tds_applies_slabs_marginally(): void
    {
        $cfg = ['slabs' => [
            ['from' => 0,      'to' => 300000, 'rate' => 0],
            ['from' => 300000, 'to' => 700000, 'rate' => 5],
            ['from' => 700000, 'to' => null,   'rate' => 10],
        ], 'standard_deduction' => 0, 'cess_rate' => 0];

        // 1,00,000/mo → 12,00,000 projected.
        // 5% on (700k-300k)=20,000 + 10% on 500,000=50,000 → 70,000
        $r = (new TdsCalculator)->calculate(100000, 12, $cfg);

        $this->assertEqualsWithDelta(70000.0, $r['annual_tax'], 0.01);
        $this->assertEqualsWithDelta(5833.33, $r['monthly_tds'], 0.01);
    }

    public function test_tds_applies_standard_deduction_rebate_and_cess(): void
    {
        $cfg = ['slabs' => [
            ['from' => 0,      'to' => 300000, 'rate' => 0],
            ['from' => 300000, 'to' => 700000, 'rate' => 5],
        ],
            'standard_deduction' => 75000,
            'rebate_87a' => ['taxable_income_limit' => 700000, 'max_rebate' => 25000],
            'cess_rate'  => 4,
        ];

        // 50,000/mo → 600,000 − 75,000 = 525,000 taxable
        // 5% on 225,000 = 11,250 → fully wiped by the 87A rebate
        $r = (new TdsCalculator)->calculate(50000, 12, $cfg);

        $this->assertSame(525000.0, $r['taxable_income']);
        $this->assertEqualsWithDelta(11250.0, $r['rebate'], 0.01);
        $this->assertSame(0.0, $r['annual_tax'], 'rebate wipes the liability');
        $this->assertSame(0.0, $r['monthly_tds']);
    }

    public function test_tds_spreads_the_balance_over_remaining_months_only(): void
    {
        $cfg = ['slabs' => [['from' => 0, 'to' => null, 'rate' => 10]], 'cess_rate' => 0];

        // 10,000/mo → 120,000 projected → 12,000 annual tax.
        // 9,000 already paid, 3 months left → 1,000/month.
        $r = (new TdsCalculator)->calculate(10000, 3, $cfg, 9000);

        $this->assertEqualsWithDelta(12000.0, $r['annual_tax'], 0.01);
        $this->assertEqualsWithDelta(1000.0, $r['monthly_tds'], 0.01);
    }

    public function test_tds_never_returns_a_negative_deduction(): void
    {
        $cfg = ['slabs' => [['from' => 0, 'to' => null, 'rate' => 10]], 'cess_rate' => 0];

        // Already over-deducted — must clamp at zero, not refund via payroll.
        $r = (new TdsCalculator)->calculate(10000, 3, $cfg, 999999);

        $this->assertSame(0.0, $r['monthly_tds']);
    }
}
