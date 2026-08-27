<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\HsseIncident;
use App\Models\Tpv\TpvWorker;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §14/§23 — Project/Site/Department/Activity context persists on workers and
 * incidents, so risk and reporting can group by those dimensions.
 */
class DimensionFieldsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    public function test_worker_dimension_fields_persist(): void
    {
        $v = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);
        $w = TpvWorker::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id, 'name' => 'Ravi', 'status' => 'Draft',
            'project' => 'Refinery', 'site' => 'Unit 4', 'department' => 'Mechanical',
        ]);
        $this->assertSame(['Refinery', 'Unit 4', 'Mechanical'], [$w->fresh()->project, $w->fresh()->site, $w->fresh()->department]);
    }

    public function test_incident_dimension_fields_persist(): void
    {
        $v = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);
        $i = HsseIncident::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id, 'reference' => 'INC-'.uniqid(), 'title' => 'Slip', 'type' => 'First_Aid',
            'severity' => 'Minor', 'status' => 'Reported',
            'project' => 'Refinery', 'site' => 'Unit 4', 'department' => 'Mechanical', 'activity' => 'Welding',
        ]);
        $fresh = $i->fresh();
        $this->assertSame('Refinery', $fresh->project);
        $this->assertSame('Welding', $fresh->activity);
    }
}
