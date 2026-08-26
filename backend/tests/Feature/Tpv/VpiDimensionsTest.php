<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvVendorPerformanceSnapshot;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvVendorPerformanceService;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §27 VPI — the doc's extra dimensions are surfaced (at weight 0 so the overall
 * index is undisturbed), the C band reads "Watch", and history persists.
 */
class VpiDimensionsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function vendor(): Vendor
    {
        return Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);
    }

    public function test_all_doc_dimensions_are_present_and_weighted_per_data_feed(): void
    {
        $c = app(TpvVendorPerformanceService::class)->compute($this->vendor());

        foreach (['productivity', 'timeliness', 'training', 'environmental', 'security', 'incident', 'meeting_action'] as $dim) {
            $this->assertArrayHasKey($dim, $c['dimensions'], "VPI dimension '{$dim}' must be present (§27).");
        }
        // §27 — the four dimensions computed from live data now carry real weight and
        // genuinely move the index (not merely displayed).
        foreach (['training', 'environmental', 'security', 'incident'] as $dim) {
            $this->assertGreaterThan(0.0, (float) $c['weights'][$dim], "VPI dimension '{$dim}' must contribute to the index (§27).");
        }
        // The three with no data feed yet stay weightless (structural stubs).
        foreach (['productivity', 'timeliness', 'meeting_action'] as $dim) {
            $this->assertSame(0.0, (float) $c['weights'][$dim]);
        }
        // The index remains a proper weighted average.
        $this->assertEqualsWithDelta(1.0, array_sum($c['weights']), 0.001);
    }

    public function test_band_label_renames_c_to_watch(): void
    {
        $this->assertSame('Watch', config('vpi.band_labels.C'));
    }

    public function test_snapshot_persists_history(): void
    {
        $vendor = $this->vendor();
        $snap = app(TpvVendorPerformanceService::class)->snapshot($vendor, 'Refinery TA-2026');

        $this->assertDatabaseHas('tpv_vendor_performance_snapshots', [
            'id' => $snap->id, 'vendor_id' => $vendor->id, 'project' => 'Refinery TA-2026',
        ]);
        $this->assertIsArray($snap->dimensions);
    }
}
