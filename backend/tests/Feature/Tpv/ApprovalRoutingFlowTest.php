<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvApprovalService;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §12 — end-to-end proof that a RAISED approval actually carries and threads
 * through its dimension-based route (not merely that the resolver returns levels).
 * A High-risk vendor's request must pass manager → head; only the final sign-off
 * marks it Approved. A default (single-level) route still approves in one step.
 */
class ApprovalRoutingFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function vendor(?string $risk): Vendor
    {
        return Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE,
            'risk_level' => $risk,
        ]);
    }

    private function actor(): User
    {
        return User::factory()->create(['tenant_id' => self::TENANT, 'role' => 'admin']);
    }

    private function service(): TpvApprovalService
    {
        return app(TpvApprovalService::class);
    }

    public function test_high_risk_request_is_routed_manager_then_head(): void
    {
        $vendor = $this->vendor('High');
        $svc = $this->service();

        $approval = $svc->raise([
            'approval_type' => 'General', 'title' => 'Access request', 'vendor_id' => $vendor->id,
        ], self::TENANT, $this->actor()->id);

        // Raised onto the two-level route, waiting on the manager.
        $this->assertSame(['manager', 'head'], $approval->route);
        $this->assertSame(0, $approval->route_index);
        $this->assertSame('manager', $approval->current_level);
        $this->assertSame('Pending', $approval->status);

        // Manager signs off → still Pending, now waiting on the head.
        $approval = $svc->decide($approval, 'approve', 'ok by manager', $this->actor());
        $this->assertSame('Pending', $approval->status);
        $this->assertSame(1, $approval->route_index);
        $this->assertSame('head', $approval->current_level);
        $this->assertNull($approval->decided_at);

        // Head signs off → terminal Approved.
        $approval = $svc->decide($approval, 'approve', 'ok by head', $this->actor());
        $this->assertSame('Approved', $approval->status);
        $this->assertNotNull($approval->decided_at);
        $this->assertNull($approval->current_level);
    }

    public function test_low_risk_request_approves_in_one_step(): void
    {
        $vendor = $this->vendor('Low');
        $svc = $this->service();

        $approval = $svc->raise([
            'approval_type' => 'General', 'title' => 'Access request', 'vendor_id' => $vendor->id,
        ], self::TENANT, $this->actor()->id);

        $this->assertSame(['manager'], $approval->route);

        $approval = $svc->decide($approval, 'approve', null, $this->actor());
        $this->assertSame('Approved', $approval->status);
    }

    public function test_reject_at_first_level_is_terminal(): void
    {
        $vendor = $this->vendor('Critical');
        $svc = $this->service();

        $approval = $svc->raise([
            'approval_type' => 'General', 'title' => 'Access request', 'vendor_id' => $vendor->id,
        ], self::TENANT, $this->actor()->id);
        // Critical routes through three levels.
        $this->assertSame(['manager', 'head', 'director'], $approval->route);

        $approval = $svc->decide($approval, 'reject', 'not acceptable', $this->actor());
        $this->assertSame('Rejected', $approval->status);
        $this->assertNull($approval->current_level);
    }

    public function test_explicit_route_context_overrides_by_value(): void
    {
        $vendor = $this->vendor('Low');
        $svc = $this->service();

        // A high-value request routes through three levels even for a low-risk vendor.
        $approval = $svc->raise([
            'approval_type' => 'General', 'title' => 'Big spend', 'vendor_id' => $vendor->id,
            'route_context' => ['value' => 'over_1cr'],
        ], self::TENANT, $this->actor()->id);

        $this->assertSame(['manager', 'head', 'director'], $approval->route);
    }
}
