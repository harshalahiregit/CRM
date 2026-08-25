<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvCapa;
use App\Models\Tpv\TpvVendorCompliance;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvRenewalService;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §28 renewal assessment (Rule 10 inputs). The snapshot now includes the §21
 * compliance score and the §25 CAPA register for the vendor, alongside the
 * existing VRS / NCR / incident-CAPA / strike / violation inputs.
 */
class RenewalAssessmentInputsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    public function test_assessment_carries_compliance_and_tpv_capa_inputs(): void
    {
        $vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);

        // One compliant record → a non-zero compliance %.
        TpvVendorCompliance::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id,
            'category' => 'Legal', 'status' => 'Compliant',
        ]);

        // Two §25 CAPAs; one Verified (closed) so only one counts as open.
        TpvCapa::create(['tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'title' => 'Open one', 'status' => 'Open']);
        TpvCapa::create(['tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'title' => 'Closed one', 'status' => 'Verified']);

        $a = app(TpvRenewalService::class)->assess($vendor);

        $this->assertArrayHasKey('compliance', $a);
        $this->assertSame(1, $a['compliance']['ok']);
        $this->assertGreaterThan(0, $a['compliance']['percent']);
        $this->assertSame(1, $a['open_tpv_capas']);
    }
}
