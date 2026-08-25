<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvActivity;
use App\Models\Tpv\TpvWorkPackage;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\WorkPermit;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvWorkerService;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rule 6 — "No Permit, No High-Risk Work" (Sangoe TPV §19/§36).
 *
 * A worker deployed on a package with a high-risk activity (requires_permit) cannot
 * be badged unless the employing vendor holds a valid Permit-to-Work covering it
 * (matching the pinned type). Enforced at `TpvWorkerService::blockers()`.
 */
class PermitGateTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function vendor(): Vendor
    {
        return Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);
    }

    private function highRiskPackage(Vendor $v, ?string $permitType): TpvWorkPackage
    {
        $wp = TpvWorkPackage::create(['tenant_id' => self::TENANT, 'vendor_id' => $v->id, 'name' => 'Hot Work WP', 'status' => 'Active']);
        TpvActivity::create([
            'tenant_id' => self::TENANT, 'work_package_id' => $wp->id, 'name' => 'Welding',
            'requires_permit' => true, 'permit_type' => $permitType, 'status' => 'Active', 'sort_order' => 1,
        ]);

        return $wp;
    }

    private function worker(Vendor $v, int $wpId): TpvWorker
    {
        return TpvWorker::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id, 'work_package_id' => $wpId,
            'name' => 'Ravi', 'designation' => 'Welder', 'status' => 'Draft',
        ]);
    }

    private function permit(Vendor $v, string $type, string $status = 'Approved', ?string $validTo = null): WorkPermit
    {
        return WorkPermit::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id, 'reference' => 'PTW-'.$type.'-'.uniqid(),
            'type' => $type, 'title' => $type.' PTW', 'status' => $status, 'valid_to' => $validTo,
        ]);
    }

    private function hasPermitBlocker(TpvWorker $w): bool
    {
        return collect(app(TpvWorkerService::class)->blockers($w->fresh()))
            ->contains(fn ($b) => str_contains($b, 'requires') && str_contains($b, 'permit'));
    }

    public function test_high_risk_worker_without_permit_is_blocked(): void
    {
        $v = $this->vendor();
        $wp = $this->highRiskPackage($v, 'Hot_Work');
        $this->assertTrue($this->hasPermitBlocker($this->worker($v, $wp->id)));
    }

    public function test_matching_valid_permit_clears_the_block(): void
    {
        $v = $this->vendor();
        $wp = $this->highRiskPackage($v, 'Hot_Work');
        $this->permit($v, 'Hot_Work', 'Approved', now()->addMonth()->toDateTimeString());
        $this->assertFalse($this->hasPermitBlocker($this->worker($v, $wp->id)));
    }

    public function test_wrong_permit_type_still_blocks(): void
    {
        $v = $this->vendor();
        $wp = $this->highRiskPackage($v, 'Hot_Work');
        $this->permit($v, 'Electrical'); // wrong type
        $this->assertTrue($this->hasPermitBlocker($this->worker($v, $wp->id)));
    }

    public function test_expired_permit_still_blocks(): void
    {
        $v = $this->vendor();
        $wp = $this->highRiskPackage($v, 'Hot_Work');
        $this->permit($v, 'Hot_Work', 'Approved', now()->subDay()->toDateTimeString());
        $this->assertTrue($this->hasPermitBlocker($this->worker($v, $wp->id)));
    }

    public function test_untyped_requirement_accepts_any_active_permit(): void
    {
        $v = $this->vendor();
        $wp = $this->highRiskPackage($v, null); // requires_permit but no pinned type
        $this->permit($v, 'Electrical', 'Active', null);
        $this->assertFalse($this->hasPermitBlocker($this->worker($v, $wp->id)));
    }

    public function test_non_high_risk_package_needs_no_permit(): void
    {
        $v = $this->vendor();
        $wp = TpvWorkPackage::create(['tenant_id' => self::TENANT, 'vendor_id' => $v->id, 'name' => 'Low risk', 'status' => 'Active']);
        TpvActivity::create(['tenant_id' => self::TENANT, 'work_package_id' => $wp->id, 'name' => 'Cleaning', 'requires_permit' => false, 'status' => 'Active']);
        $this->assertFalse($this->hasPermitBlocker($this->worker($v, $wp->id)));
    }
}
