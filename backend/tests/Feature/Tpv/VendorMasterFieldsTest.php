<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorClass;
use App\Support\Vendor\VendorRiskFactor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §5 Vendor Master — the doc's added lifecycle statuses, the project/site/
 * department/client context, and the vendor-class + risk-factor vocabularies.
 */
class VendorMasterFieldsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    public function test_status_vocabulary_has_the_doc_stages(): void
    {
        foreach (['Invited', 'Registered', 'Under_Review', 'Approved', 'Expired'] as $s) {
            $this->assertContains($s, VendorStatus::ALL, "$s should be a vendor status");
        }
    }

    public function test_class_and_risk_factor_catalogues(): void
    {
        $this->assertContains('Contractor', VendorClass::ALL);
        foreach (['Regulatory_Requirements', 'Previous_Incidents', 'Compliance_History', 'Vendor_Performance'] as $f) {
            $this->assertContains($f, VendorRiskFactor::ALL, "$f should be a risk factor");
        }
    }

    public function test_project_site_department_client_persist(): void
    {
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE,
            'vendor_class' => VendorClass::CONTRACTOR,
            'project' => 'Refinery Upgrade', 'site' => 'Unit 4', 'department' => 'Mechanical', 'client_id' => 7,
        ]);

        $fresh = $vendor->fresh();
        $this->assertSame('Refinery Upgrade', $fresh->project);
        $this->assertSame('Unit 4', $fresh->site);
        $this->assertSame('Mechanical', $fresh->department);
        $this->assertSame(7, (int) $fresh->client_id);
    }
}
