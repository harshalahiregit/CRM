<?php

namespace Tests\Feature\Tpv;

use App\Models\Inventory\Product;
use App\Models\Tenant;
use App\Models\Tpv\TpvPpeRequirement;
use App\Models\Tpv\TpvWorker;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\PpeInventoryService;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §18 PPE Matrix classes. Only a MANDATORY requirement gates the badge; Optional
 * and Conditional requirements are advisory and never block. Guards the rebuild
 * of the single-dimension role→PPE matrix into Job+Hazard+Activity + class.
 */
class PpeMatrixClassTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function worker(): TpvWorker
    {
        $v = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);

        return TpvWorker::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id, 'name' => 'Ravi',
            'designation' => 'Welder', 'status' => 'Draft',
        ]);
    }

    private function ppeProduct(): Product
    {
        return Product::create(['tenant_id' => self::TENANT, 'name' => 'Welding Helmet', 'sku' => 'PPE-'.uniqid()]);
    }

    private function rule(int $productId, string $class): TpvPpeRequirement
    {
        return TpvPpeRequirement::create([
            'tenant_id' => self::TENANT, 'scope_type' => 'designation', 'scope_value' => 'Welder',
            'hazard' => 'Arc/Heat', 'activity' => 'Welding', 'ppe_class' => $class,
            'product_id' => $productId, 'qty' => 1, 'is_active' => true,
        ]);
    }

    public function test_mandatory_requirement_blocks_the_badge(): void
    {
        $w = $this->worker();
        $this->rule($this->ppeProduct()->id, 'mandatory');
        $missing = app(PpeInventoryService::class)->missingMandatoryFor($w);
        $this->assertCount(1, $missing);
    }

    public function test_optional_requirement_does_not_block(): void
    {
        $w = $this->worker();
        $this->rule($this->ppeProduct()->id, 'optional');
        $this->assertCount(0, app(PpeInventoryService::class)->missingMandatoryFor($w));
    }

    public function test_conditional_requirement_does_not_block(): void
    {
        $w = $this->worker();
        $this->rule($this->ppeProduct()->id, 'conditional');
        $this->assertCount(0, app(PpeInventoryService::class)->missingMandatoryFor($w));
    }

    public function test_compliance_view_carries_the_class_and_hazard(): void
    {
        $w = $this->worker();
        $this->rule($this->ppeProduct()->id, 'mandatory');
        $c = app(PpeInventoryService::class)->complianceFor($w);
        $this->assertSame('mandatory', $c['items'][0]['ppe_class']);
        $this->assertSame('Arc/Heat', $c['items'][0]['hazard']);
    }
}
