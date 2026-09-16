<?php

namespace Tests\Feature\Vendor;

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
 * The vendor status toggle turns portal access on and off. Both ways.
 *
 * This replaces ActivationApprovalGuardTest, which asserted "No Approval, No
 * Activation" — a vendor could not be flipped Active unless its onboarding was
 * approved.
 *
 * That rule guarded ONE of three doors. Creating a vendor set Active with no
 * check; the edit form wrote the status directly with no check; only this path
 * refused. A vendor reached Active in one click through either of the others,
 * and on the live workspace every single vendor was Active with its onboarding
 * still In_Progress — so the rule never actually held anything back.
 *
 * What it did do was make the toggle a one-way trap. An admin could switch a
 * vendor off and then not switch it back on, because the way back ran through
 * the only door that checked, and the sole escape was the edit form — which is
 * not where anyone looks for a status they can see a switch for.
 *
 * The protection that matters was never here. EnsureVendorOnboardingComplete
 * refuses every operational write — workers, permits, medicals, badges — until
 * the vendor is Active, and that is what stops an uncleared company putting
 * somebody on a site. Deactivating still locks the login out immediately.
 */
class VendorStatusToggleTest extends TestCase
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

    private function vendor(string $status = VendorStatus::PENDING_APPROVAL): Vendor
    {
        return Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => $status]);
    }

    /** Unapproved onboarding no longer blocks the switch. */
    public function test_a_vendor_can_be_activated_while_onboarding_is_still_in_progress(): void
    {
        $vendor = $this->vendor();
        TpvOnboarding::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id,
            'status' => TpvOnboardingStatus::SUBMITTED, 'current_step' => 5,
        ]);

        app(VendorService::class)->updateStatus($vendor, VendorStatus::ACTIVE, $this->actor());

        $this->assertSame(VendorStatus::ACTIVE, $vendor->fresh()->status);
    }

    /** Nor does having no onboarding at all. */
    public function test_a_vendor_with_no_onboarding_can_still_be_activated(): void
    {
        $vendor = $this->vendor();

        app(VendorService::class)->updateStatus($vendor, VendorStatus::ACTIVE, $this->actor());

        $this->assertSame(VendorStatus::ACTIVE, $vendor->fresh()->status);
    }

    /** An approved onboarding activates as it always did. */
    public function test_activation_still_works_once_onboarding_is_approved(): void
    {
        $vendor = $this->vendor();
        TpvOnboarding::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id,
            'status' => TpvOnboardingStatus::APPROVED, 'current_step' => 6,
        ]);

        app(VendorService::class)->updateStatus($vendor, VendorStatus::ACTIVE, $this->actor());

        $this->assertSame(VendorStatus::ACTIVE, $vendor->fresh()->status);
    }

    /**
     * The one that was actually broken: off, then on again.
     *
     * This is the exact sequence an admin performed and could not undo — the
     * vendor was Active, they switched it off, and the switch would not go back.
     */
    public function test_a_vendor_switched_off_can_be_switched_back_on(): void
    {
        $actor = $this->actor();
        $vendor = $this->vendor(VendorStatus::ACTIVE);

        app(VendorService::class)->updateStatus($vendor, VendorStatus::INACTIVE, $actor);
        $this->assertSame(VendorStatus::INACTIVE, $vendor->fresh()->status);

        app(VendorService::class)->updateStatus($vendor->fresh(), VendorStatus::ACTIVE, $actor);
        $this->assertSame(VendorStatus::ACTIVE, $vendor->fresh()->status, 'the toggle is still a one-way trap');
    }

    /** Deactivating needs no approval and never did. */
    public function test_deactivating_is_unaffected(): void
    {
        $vendor = $this->vendor(VendorStatus::ACTIVE);

        app(VendorService::class)->updateStatus($vendor, VendorStatus::INACTIVE, $this->actor());

        $this->assertSame(VendorStatus::INACTIVE, $vendor->fresh()->status);
    }

    /** Switching off locks the portal login out with it. */
    public function test_deactivating_locks_the_login_out(): void
    {
        $actor = $this->actor();
        $login = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Vendor Login', 'role' => 'third_party_vendor',
            'email' => 'v-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme',
            'status' => VendorStatus::ACTIVE, 'user_id' => $login->id,
        ]);

        app(VendorService::class)->updateStatus($vendor, VendorStatus::INACTIVE, $actor);

        $this->assertNotSame('active', $login->fresh()->status, 'the login was left open on a deactivated vendor');
    }
}
