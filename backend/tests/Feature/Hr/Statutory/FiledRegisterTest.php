<?php

namespace Tests\Feature\Hr\Statutory;

use App\Services\Hr\Statutory\EsicCalculator;
use App\Services\Hr\Statutory\LwfCalculator;
use App\Services\Hr\Statutory\PfCalculator;
use App\Services\Hr\Statutory\PtCalculator;
use Tests\TestCase;

/**
 * The engine reproduces a month that was actually filed.
 *
 * Every figure below is from Summit Online Trade Solution's July 2026 registers
 * — PF (9 employees), ESIC (8) and PT (13) — as submitted. This is the only
 * check that matters for statutory code: not that the arithmetic is
 * self-consistent, but that it agrees with what the government already has.
 *
 * A rate changed by notification will break these, which is the point. When it
 * does, the fix is the rule in hr_statutory_rules, and these numbers move to
 * the month the new rate first applied.
 */
class FiledRegisterTest extends TestCase
{
    private const PF_CONFIG = [
        'employee_rate' => 12, 'employer_rate' => 12, 'eps_rate' => 8.33,
        'wage_ceiling' => 15000, 'restrict_to_ceiling' => true, 'eps_max_age' => 58,
    ];

    private const ESIC_CONFIG = [
        'gross_threshold' => 42000, 'employee_rate' => 0.75,
        'employer_rate' => 3.25, 'round_employee_up' => true,
    ];

    private const PT_CONFIG = [
        'slabs' => [
            ['from' => 0, 'to' => 7500, 'amount' => 0, 'gender' => 'M'],
            ['from' => 7501, 'to' => 10000, 'amount' => 175, 'gender' => 'M'],
            ['from' => 10001, 'to' => null, 'amount' => 200, 'gender' => 'M'],
            ['from' => 0, 'to' => 25000, 'amount' => 0, 'gender' => 'F'],
            ['from' => 25001, 'to' => null, 'amount' => 200, 'gender' => 'F'],
        ],
        'month_overrides' => ['2' => 300],
    ];

    /**
     * PF register, July 2026. Everyone is at the 15,000 ceiling, so PF is 1,800,
     * EPS 1,250 and EPF 550 — except one man.
     */
    public function test_the_pf_register_reproduces(): void
    {
        $pf = new PfCalculator;

        // [code, gross, age, expected employee, expected eps, expected epf]
        $rows = [
            ['SD104', 48478, 49, 1800.0, 1250.0, 550.0],
            ['SD105', 46491, 40, 1800.0, 1250.0, 550.0],
            ['SD107', 31780, 39, 1800.0, 1250.0, 550.0],
            ['SD109', 33412, 55, 1800.0, 1250.0, 550.0],
            // Born 06/07/1966 — 60 in July 2026. EPS stops at 58, so his whole
            // employer share goes to EPF. The register shows exactly this.
            ['SD111', 27030, 60, 1800.0, 0.0, 1800.0],
            ['SD112', 27196, 46, 1800.0, 1250.0, 550.0],
            ['SD115', 64171, 39, 1800.0, 1250.0, 550.0],
            ['SD125', 30866, 43, 1800.0, 1250.0, 550.0],
            ['SD126', 22015, 34, 1800.0, 1250.0, 550.0],
        ];

        $totalEps = 0.0;
        $totalEpf = 0.0;

        foreach ($rows as [$code, $gross, $age, $employee, $eps, $epf]) {
            $r = $pf->calculate((float) $gross, self::PF_CONFIG, $age);

            $this->assertSame($employee, $r['employee'], "{$code}: employee PF");
            $this->assertSame($eps, $r['eps'], "{$code}: EPS");
            $this->assertSame($epf, $r['epf'], "{$code}: EPF");

            $totalEps += $r['eps'];
            $totalEpf += $r['epf'];
        }

        // The register's own totals.
        $this->assertSame(10000.0, $totalEps, 'Total EPS (A/C 10) must be 10,000');
        $this->assertSame(6200.0, $totalEpf, 'Total EPF must be 6,200');
    }

    /**
     * ESIC register, July 2026.
     *
     * The rounding is the whole test. Plain round() on both sides is wrong on
     * three of these rows and plain ceil() on four.
     */
    public function test_the_esic_register_reproduces(): void
    {
        $esic = new EsicCalculator;

        $rows = [
            ['SD104', 18453, 139.0, 600.0],
            ['SD105', 20215, 152.0, 657.0],
            ['SD107', 19106, 144.0, 621.0],
            ['SD109', 18169, 137.0, 590.0],
            ['SD111', 18259, 137.0, 593.0],
            ['SD112', 17850, 134.0, 580.0],
            ['SD125', 18474, 139.0, 600.0],
            ['SD126', 16000, 120.0, 520.0],
        ];

        $employees = 0.0;
        $employers = 0.0;

        foreach ($rows as [$code, $wages, $employee, $employer]) {
            $r = $esic->calculate((float) $wages, (float) $wages, self::ESIC_CONFIG);

            $this->assertSame($employee, $r['employee'], "{$code}: employee ESIC");
            $this->assertSame($employer, $r['employer'], "{$code}: employer ESIC");

            $employees += $r['employee'];
            $employers += $r['employer'];
        }

        // The employee total is the sum of the rounded rows, and matches.
        $this->assertSame(1102.0, $employees, 'Employee contribution total');

        // The employer total is where THEIR register disagrees with itself. Its
        // eight rows sum to 4761; it prints 4762 on the grand-total line and 4763
        // a line below that. 4762 is 3.25% of the total wages (146526 × 3.25% =
        // 4762.10) — i.e. the total is computed from the total wage rather than
        // by adding up the rounded rows, while the EMPLOYEE total is the sum of
        // rows. Two methods in one report.
        //
        // We sum the rows, because that is the figure each employee's payslip
        // shows and the one that reconciles. The discrepancy is recorded here so
        // that whoever compares our register with theirs finds the reason rather
        // than a bug.
        $this->assertSame(4761.0, $employers, 'Employer contribution, summed from the rows');
        $this->assertSame(4762.0, round(146526 * 3.25 / 100), 'Their printed total is the rate on total wages');
    }

    /**
     * PT register, July 2026 — 13 employees, every one at 200.
     *
     * Includes the only woman on the roll, at 47,029. She is above the 25,000
     * threshold so she pays; a woman below it does not, which is why the slabs
     * carry a gender.
     */
    public function test_the_pt_register_reproduces(): void
    {
        $pt = new PtCalculator;

        $rows = [
            ['SD114', 47029, 'F', 200.0],
            ['SD85', 126170, 'M', 200.0],
            ['SD92', 57808, 'M', 200.0],
            ['SD107', 29126, 'M', 200.0],
            ['SD125', 28108, 'M', 200.0],
            ['SD105', 39753, 'M', 200.0],
            ['SD126', 21015, 'M', 200.0],
            ['SD111', 25828, 'M', 200.0],
            ['SD109', 28733, 'M', 200.0],
            ['SD104', 45683, 'M', 200.0],
            ['SD112', 23371, 'M', 200.0],
            ['SD115', 51337, 'M', 200.0],
            ['SD22', 97082, 'M', 200.0],
        ];

        $total = 0.0;

        foreach ($rows as [$code, $gross, $gender, $expected]) {
            $r = $pt->calculate((float) $gross, self::PT_CONFIG, 7, 'Maharashtra', $gender);

            $this->assertSame($expected, $r['amount'], "{$code}: PT");
            $total += $r['amount'];
        }

        $this->assertSame(2600.0, $total, 'Total PT for the month');
    }

    /** A woman under 25,000 pays nothing, where a man on the same salary pays. */
    public function test_the_gender_threshold_is_the_reason_slabs_carry_a_gender(): void
    {
        $pt = new PtCalculator;

        $this->assertSame(0.0, $pt->calculate(20000, self::PT_CONFIG, 7, 'Maharashtra', 'F')['amount']);
        $this->assertSame(200.0, $pt->calculate(20000, self::PT_CONFIG, 7, 'Maharashtra', 'M')['amount']);
        $this->assertSame(175.0, $pt->calculate(9000, self::PT_CONFIG, 7, 'Maharashtra', 'M')['amount']);
    }

    /** February is 300 for anybody who pays 200 the rest of the year. */
    public function test_february_is_three_hundred(): void
    {
        $pt = new PtCalculator;

        $this->assertSame(300.0, $pt->calculate(47029, self::PT_CONFIG, 2, 'Maharashtra', 'F')['amount']);
        $this->assertSame(200.0, $pt->calculate(47029, self::PT_CONFIG, 7, 'Maharashtra', 'F')['amount']);
    }

    /** LWF is half-yearly — the reason it cannot ride on the monthly path. */
    public function test_lwf_is_taken_only_in_june_and_december(): void
    {
        $lwf = new LwfCalculator;
        $config = ['employee_amount' => 25, 'employer_amount' => 75, 'months' => [6, 12]];

        foreach ([6, 12] as $month) {
            $r = $lwf->calculate($config, $month);
            $this->assertTrue($r['applicable'], "Month {$month} is a deduction month");
            $this->assertSame(25.0, $r['employee']);
            $this->assertSame(75.0, $r['employer']);
            $this->assertSame(100.0, $r['total']);
        }

        foreach ([1, 5, 7, 11] as $month) {
            $r = $lwf->calculate($config, $month);
            $this->assertFalse($r['applicable'], "Month {$month} must take no LWF");
            $this->assertSame(0.0, $r['employee']);
        }
    }
}
