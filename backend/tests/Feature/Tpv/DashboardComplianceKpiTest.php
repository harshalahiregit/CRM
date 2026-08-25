<?php

namespace Tests\Feature\Tpv;

use App\Models\Inventory\Product;
use App\Models\Tenant;
use App\Models\Tpv\TpvPpeRequirement;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerPpeIssue;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvDashboardService;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §4 Control Tower — executive compliance KPIs. PPE compliance % counts only
 * workers that have a PPE rule; the Action Centre surfaces the pending-PPE queue.
 * Empty tenants report null percentages rather than a misleading 0%.
 */
class DashboardComplianceKpiTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    public function test_compliance_block_is_null_safe_on_an_empty_tenant(): void
    {
        $ct = app(TpvDashboardService::class)->getDashboard(self::TENANT)['control_tower'];

        $this->assertArrayHasKey('compliance', $ct);
        $this->assertNull($ct['compliance']['ppe_pct']);
        $this->assertNull($ct['compliance']['overall_pct']);
        $this->assertSame(0, $ct['compliance']['vendors_tracked']);
    }

    public function test_ppe_compliance_percent_and_pending_queue(): void
    {
        $vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);
        $product = Product::create(['tenant_id' => self::TENANT, 'name' => 'Helmet', 'sku' => 'PPE-'.uniqid()]);

        // One mandatory rule for Welders.
        TpvPpeRequirement::create([
            'tenant_id' => self::TENANT, 'scope_type' => 'designation', 'scope_value' => 'Welder',
            'ppe_class' => 'mandatory', 'product_id' => $product->id, 'qty' => 1, 'is_active' => true,
        ]);

        // Two Welders; equip only one.
        $equipped = TpvWorker::create(['tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'name' => 'A', 'designation' => 'Welder', 'status' => 'Draft']);
        TpvWorker::create(['tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'name' => 'B', 'designation' => 'Welder', 'status' => 'Draft']);

        TpvWorkerPpeIssue::create([
            'tenant_id' => self::TENANT, 'tpv_worker_id' => $equipped->id, 'inventory_item_id' => $product->id,
            'item' => 'Helmet', 'qty' => 1, 'status' => 'issued', 'returned_qty' => 0,
        ]);

        $dash = app(TpvDashboardService::class)->getDashboard(self::TENANT);
        $co = $dash['control_tower']['compliance'];

        $this->assertSame(2, $co['ppe_configured']);
        $this->assertSame(50, $co['ppe_pct']);
        $this->assertSame(1, $co['ppe_missing']);

        $ppeRow = collect($dash['action_centre'])->firstWhere('key', 'ppe_pending');
        $this->assertNotNull($ppeRow, 'the PPE-pending action row should appear');
        $this->assertSame(1, $ppeRow['count']);
    }
}
