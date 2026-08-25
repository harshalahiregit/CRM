<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseOnboarding;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Tenant;
use App\Services\Purchase\PurchaseDashboardService;
use App\Support\Purchase\PurchaseVendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §4/§37 — the Purchase vendor Control Tower (parity with TPV), sourced only from
 * purchase_* tables. Confirms the new blocks exist, are null-safe when empty, and
 * reflect purchase data. The existing financial view is left intact.
 */
class PurchaseControlTowerTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    public function test_control_tower_blocks_exist_and_are_null_safe(): void
    {
        $dash = app(PurchaseDashboardService::class)->getDashboard(self::TENANT);

        // Financial view still present (nothing removed).
        $this->assertArrayHasKey('kpis', $dash);
        $this->assertArrayHasKey('funnel', $dash);

        // New Control Tower blocks.
        $this->assertArrayHasKey('control_tower', $dash);
        $this->assertArrayHasKey('action_centre', $dash);
        $this->assertArrayHasKey('risk_breakdown', $dash);

        $ct = $dash['control_tower'];
        $this->assertSame(0, $ct['vendors']['total']);
        $this->assertNull($ct['compliance']['overall_pct']);
        $this->assertNull($ct['compliance']['ppe_pct']);
        $this->assertNull($ct['performance']['avg_score']);
        // Gate/permit/strike KPIs must NOT be present (Purchase has no such data).
        $this->assertArrayNotHasKey('gate_violations', $ct['open']);
        $this->assertArrayNotHasKey('total_strikes', $ct['open']);
        $this->assertArrayNotHasKey('on_site_now', $ct['workforce']);
    }

    public function test_control_tower_reflects_purchase_data(): void
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme Supplies',
            'purchase_vendor_code' => 'PV-'.uniqid(),
            'status' => PurchaseVendorStatus::ACTIVE, 'registration_type' => 'temporary_vendor',
        ]);
        PurchaseOnboarding::create(['tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id, 'status' => 'Submitted']);
        PurchaseWorker::create(['tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id, 'full_name' => 'Ravi', 'status' => 'Active']);

        $ct = app(PurchaseDashboardService::class)->getDashboard(self::TENANT)['control_tower'];

        $this->assertSame(1, $ct['vendors']['total']);
        $this->assertSame(1, $ct['vendors']['active']);
        $this->assertSame(1, $ct['vendors']['temporary']);
        $this->assertSame(1, $ct['vendors']['pending_onboarding']);
        $this->assertSame(1, $ct['workforce']['total']);
        $this->assertSame(1, $ct['workforce']['active']);
    }
}
