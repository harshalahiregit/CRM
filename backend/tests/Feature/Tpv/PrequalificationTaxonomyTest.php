<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Vendor\Vendor;
use App\Services\Vendor\VendorPrequalificationService;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §6 — the prequalification questionnaire carries the doc's fuller taxonomy:
 * Company (regional/manpower), the HSE sub-items, Commercial, and Compliance
 * (licences as a discrete item). Scoring still normalises cleanly.
 */
class PrequalificationTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    public function test_new_sections_and_hse_subitems_present(): void
    {
        $sections = config('vendor_prequalification.sections');

        foreach (['company', 'commercial', 'compliance'] as $s) {
            $this->assertArrayHasKey($s, $sections, "Prequal section '{$s}' must exist (§6).");
        }
        foreach (['hse_organization', 'safety_statistics', 'training_system', 'risk_assessment_system', 'emergency_preparedness'] as $q) {
            $this->assertArrayHasKey($q, $sections['hse']['questions'], "HSE question '{$q}' must exist (§6).");
        }
        $this->assertArrayHasKey('licences', $sections['compliance']['questions']);
    }

    public function test_scoring_still_normalises_to_0_100(): void
    {
        $c = app(VendorPrequalificationService::class)->compute([
            'company'    => ['regional_capability' => 'national', 'manpower_capability' => 'strong'],
            'compliance' => ['licences' => 'complete'],
        ]);

        $this->assertGreaterThanOrEqual(0, $c['score']);
        $this->assertLessThanOrEqual(100, $c['score']);
    }
}
