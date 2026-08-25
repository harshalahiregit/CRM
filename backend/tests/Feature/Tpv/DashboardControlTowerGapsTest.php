<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvContract;
use App\Models\Tpv\TpvGateScan;
use App\Models\Tpv\TpvOnboarding;
use App\Models\Tpv\TpvRenewal;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvDashboardService;
use App\Support\Tpv\GateDecision;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §4/§37 Control Tower — the KPIs and Action Centre rows added to close the doc
 * gaps: Pending Onboarding, cumulative Gate Violations, MOM pending, Contract
 * expiry and Vendor-renewal-to-assess.
 */
class DashboardControlTowerGapsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    public function test_new_kpis_default_to_zero_on_empty_tenant(): void
    {
        $ct = app(TpvDashboardService::class)->getDashboard(self::TENANT)['control_tower'];
        $this->assertSame(0, $ct['vendors']['pending_onboarding']);
        $this->assertSame(0, $ct['open']['gate_violations']);
    }

    public function test_new_kpis_and_action_rows_reflect_data(): void
    {
        $vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);

        // Pending onboarding (not Approved/Rejected).
        TpvOnboarding::create(['tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'status' => 'Submitted', 'current_step' => 3]);
        // A refused gate entry → one gate violation.
        $worker = \App\Models\Tpv\TpvWorker::create(['tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'name' => 'Ravi', 'status' => 'Active']);
        TpvGateScan::create(['tenant_id' => self::TENANT, 'tpv_worker_id' => $worker->id, 'decision' => GateDecision::DENY, 'scanned_at' => now()]);
        // A contract expiring inside 30 days.
        TpvContract::create(['tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'title' => 'Service Agreement', 'status' => 'Active', 'end_date' => now()->addDays(10)->toDateString()]);
        // A renewal awaiting assessment.
        TpvRenewal::create(['tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'status' => 'Pending', 'due_date' => now()->addDays(5)->toDateString()]);

        $dash = app(TpvDashboardService::class)->getDashboard(self::TENANT);
        $ct = $dash['control_tower'];
        $this->assertSame(1, $ct['vendors']['pending_onboarding']);
        $this->assertSame(1, $ct['open']['gate_violations']);

        $rows = collect($dash['action_centre'])->keyBy('key');
        $this->assertSame(1, $rows['contract_expiry']['count'] ?? 0, 'contract expiry row should surface');
        $this->assertSame(1, $rows['renewal_assessment_due']['count'] ?? 0, 'renewal-to-assess row should surface');
    }
}
