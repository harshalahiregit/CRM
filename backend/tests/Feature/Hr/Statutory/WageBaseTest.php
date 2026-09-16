<?php

namespace Tests\Feature\Hr\Statutory;

use App\Models\Hr\HrSalaryComponent;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The PF and ESIC wage bases come out of the component flags, and match what was filed.
 *
 * The engine sums components flagged pf_applicable / esic_applicable. Which heads
 * carry those flags is therefore the single most consequential piece of payroll
 * configuration there is: get it wrong and every contribution for every employee
 * is wrong, in a direction nobody notices until an inspection.
 *
 * It is checkable, because the filed July 2026 registers state the answer:
 * Basic + DA equals the ESIC salary exactly, for every employee on it.
 */
class WageBaseTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = Tenant::create(['name' => 'S', 'slug' => 'wage-base', 'status' => 'active'])->id;

        $this->artisan('hr:seed-salary-components', ['--tenant' => $this->tenantId, '--commit' => true])
            ->assertSuccessful();
    }

    /** @param array<string, float> $amounts keyed by component code */
    private function base(array $amounts, string $flag): float
    {
        $codes = HrSalaryComponent::where('tenant_id', $this->tenantId)
            ->where($flag, true)->pluck('code')->all();

        return (float) collect($amounts)
            ->filter(fn ($v, $code) => in_array($code, $codes, true))
            ->sum();
    }

    /**
     * Three employees from the filed register. Basic + DA is the ESIC salary in
     * every case, so the flags are right if and only if these reproduce.
     */
    public function test_basic_plus_da_is_the_esic_wage_base(): void
    {
        $filed = [
            // [Basic, DA, HRA, CCA, ESIC salary as filed]
            'SD104' => [14259, 4194, 6710, 2795, 18453],
            'SD105' => [14974, 5241, 10482, 6738, 20215],
            'SD107' => [14860, 4246, 4776, 2654, 19106],
        ];

        foreach ($filed as $code => [$basic, $da, $hra, $cca, $esicSalary]) {
            $amounts = ['BASIC' => $basic, 'DA' => $da, 'HRA' => $hra, 'CCA' => $cca];

            $this->assertSame(
                (float) $esicSalary,
                $this->base($amounts, 'esic_applicable'),
                "{$code}: the ESIC base must be Basic + DA, as filed",
            );
        }
    }

    /** HRA and the allowances must stay OUT, or every contribution is inflated. */
    public function test_hra_and_the_allowances_are_outside_both_bases(): void
    {
        $amounts = ['BASIC' => 14259, 'DA' => 4194, 'HRA' => 6710, 'CCA' => 2795, 'SPL' => 20520];

        // If HRA or the allowances leaked in, this would be the full 48,478 gross.
        $this->assertSame(18453.0, $this->base($amounts, 'pf_applicable'));
        $this->assertSame(18453.0, $this->base($amounts, 'esic_applicable'));
    }

    /**
     * Overtime is ESIC wages but not PF wages.
     *
     * The one head where the two flags deliberately differ, so a change that
     * collapses them into a single "statutory" flag fails here.
     */
    public function test_overtime_counts_for_esic_but_not_for_pf(): void
    {
        $amounts = ['BASIC' => 14259, 'DA' => 4194, 'OT' => 3000];

        $this->assertSame(18453.0, $this->base($amounts, 'pf_applicable'), 'OT is not PF wages');
        $this->assertSame(21453.0, $this->base($amounts, 'esic_applicable'), 'OT is ESIC wages');
    }

    /** Bonus and leave encashment sit outside both. */
    public function test_bonus_and_encashment_are_outside_both_bases(): void
    {
        $amounts = ['BASIC' => 14259, 'DA' => 4194, 'BONUS' => 5000, 'LVENC' => 8000];

        $this->assertSame(18453.0, $this->base($amounts, 'pf_applicable'));
        $this->assertSame(18453.0, $this->base($amounts, 'esic_applicable'));
    }

    /** Seeding twice must not double the heads. */
    public function test_seeding_again_changes_nothing(): void
    {
        $before = HrSalaryComponent::where('tenant_id', $this->tenantId)->count();

        $this->artisan('hr:seed-salary-components', ['--tenant' => $this->tenantId, '--commit' => true])
            ->assertSuccessful();

        $this->assertSame($before, HrSalaryComponent::where('tenant_id', $this->tenantId)->count());
    }
}
