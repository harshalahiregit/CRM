<?php

namespace Tests\Feature\Vendor;

use App\Exceptions\BusinessException;
use App\Models\Tenant;
use App\Models\Tpv\TpvOnboarding;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Vendor\VendorService;
use App\Support\Tpv\TpvOnboardingStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rule 1 — "No Approval, No Activation." A vendor cannot be flipped Active through
 * the general updateStatus() path (the PATCH /vendors/{id}/status bypass) unless
 * its onboarding has actually been approved. The sanctioned onboarding-approve
 * path sets the onboarding Approved first, so it still activates (covered by
 * OnboardingActivationTest); here we prove the raw path is refused.
 */
class ActivationApprovalGuardTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function actor(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(): Vendor
    {
        return Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::PENDING_APPROVAL]);
    }

    public function test_activation_is_refused_while_onboarding_is_not_approved(): void
    {
        $actor = $this->actor();
        $vendor = $this->vendor();
        TpvOnboarding::create(['tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'status' => TpvOnboardingStatus::SUBMITTED, 'current_step' => 5]);

        $this->expectException(BusinessException::class);
        app(VendorService::class)->updateStatus($vendor, VendorStatus::ACTIVE, $actor);
    }

    public function test_activation_is_refused_when_there_is_no_onboarding_at_all(): void
    {
        $actor = $this->actor();
        $vendor = $this->vendor();

        $this->expectException(BusinessException::class);
        app(VendorService::class)->updateStatus($vendor, VendorStatus::ACTIVE, $actor);

        $this->assertNotSame(VendorStatus::ACTIVE, $vendor->fresh()->status);
    }

    public function test_activation_succeeds_once_onboarding_is_approved(): void
    {
        $actor = $this->actor();
        $vendor = $this->vendor();
        TpvOnboarding::create(['tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'status' => TpvOnboardingStatus::APPROVED, 'current_step' => 6]);

        app(VendorService::class)->updateStatus($vendor, VendorStatus::ACTIVE, $actor);
        $this->assertSame(VendorStatus::ACTIVE, $vendor->fresh()->status);
    }

    public function test_non_active_transitions_are_unaffected_by_the_guard(): void
    {
        $actor = $this->actor();
        $vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);

        // Deactivating never needs an approval — only → Active does.
        app(VendorService::class)->updateStatus($vendor, VendorStatus::INACTIVE, $actor);
        $this->assertSame(VendorStatus::INACTIVE, $vendor->fresh()->status);
    }
}
