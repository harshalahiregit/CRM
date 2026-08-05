<?php

namespace Tests\Unit\Hr;

use App\Services\Hr\Statutory\HraCalculator;
use PHPUnit\Framework\TestCase;

/**
 * HRA exemption — least of three.
 *
 * The percentages below are TEST FIXTURES chosen so each leg can be made to win
 * in turn. They are not an assertion about current law; the calculator applies
 * whatever it is configured with.
 */
class HraCalculatorTest extends TestCase
{
    private array $cfg = [
        'salary_percent_metro'     => 50,
        'salary_percent_non_metro' => 40,
        'rent_excess_percent'      => 10,
    ];

    public function test_it_is_zero_and_explains_itself_when_unconfigured(): void
    {
        $r = (new HraCalculator)->exempt(120000, 300000, 240000, true, null);

        $this->assertFalse($r['applicable']);
        $this->assertSame(0.0, $r['amount']);
        $this->assertSame('HRA exemption rules not configured', $r['reason']);
    }

    public function test_the_hra_actually_received_can_be_the_binding_leg(): void
    {
        // HRA 60k; 50% of salary 150k; rent-excess 210k → the 60k received wins.
        $r = (new HraCalculator)->exempt(60000, 300000, 240000, true, $this->cfg);

        $this->assertTrue($r['applicable']);
        $this->assertSame(60000.0, $r['amount']);
        $this->assertSame(60000.0, $r['legs']['hra_received']);
    }

    public function test_the_salary_percentage_can_be_the_binding_leg(): void
    {
        // HRA 200k; 50% of 300k = 150k; rent 240k − 30k = 210k → 150k wins.
        $r = (new HraCalculator)->exempt(200000, 300000, 240000, true, $this->cfg);

        $this->assertSame(150000.0, $r['amount']);
    }

    public function test_the_rent_excess_can_be_the_binding_leg(): void
    {
        // HRA 200k; 50% of 300k = 150k; rent 120k − 30k = 90k → 90k wins.
        $r = (new HraCalculator)->exempt(200000, 300000, 120000, true, $this->cfg);

        $this->assertSame(90000.0, $r['amount']);
    }

    public function test_metro_and_non_metro_use_different_percentages(): void
    {
        $metro    = (new HraCalculator)->exempt(200000, 300000, 240000, true, $this->cfg);
        $nonMetro = (new HraCalculator)->exempt(200000, 300000, 240000, false, $this->cfg);

        $this->assertSame(150000.0, $metro['amount'],    '50% of salary');
        $this->assertSame(120000.0, $nonMetro['amount'], '40% of salary');
    }

    public function test_rent_below_the_excess_threshold_yields_no_exemption(): void
    {
        // Rent 20k is under 10% of a 300k salary, so the third leg is zero.
        $r = (new HraCalculator)->exempt(200000, 300000, 20000, true, $this->cfg);

        $this->assertSame(0.0, $r['amount']);
    }

    public function test_no_hra_component_means_no_exemption(): void
    {
        $r = (new HraCalculator)->exempt(0, 300000, 240000, true, $this->cfg);

        $this->assertFalse($r['applicable']);
        $this->assertSame('No HRA component in this salary structure', $r['reason']);
    }

    public function test_no_rent_declared_means_no_exemption(): void
    {
        $r = (new HraCalculator)->exempt(120000, 300000, 0, true, $this->cfg);

        $this->assertFalse($r['applicable']);
        $this->assertSame('No rent declared', $r['reason']);
    }

    public function test_an_incomplete_config_is_refused_rather_than_half_applied(): void
    {
        $r = (new HraCalculator)->exempt(120000, 300000, 240000, true, ['salary_percent_metro' => 50]);

        $this->assertFalse($r['applicable']);
        $this->assertStringContainsString('incomplete', $r['reason']);
    }

    public function test_every_leg_is_returned_so_the_binding_one_is_visible(): void
    {
        $r = (new HraCalculator)->exempt(200000, 300000, 240000, true, $this->cfg);

        $this->assertSame(['hra_received', 'salary_percent', 'rent_excess'], array_keys($r['legs']));
        $this->assertSame($r['amount'], min($r['legs']));
    }
}
