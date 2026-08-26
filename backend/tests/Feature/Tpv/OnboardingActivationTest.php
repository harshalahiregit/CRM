<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvOnboarding;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvOnboardingService;
use App\Support\Tpv\TpvOnboardingStatus as Status;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The admin onboarding decision must drive the REAL account, not only the
 * onboarding's own status text. This is the regression this class guards:
 * approve() used to flip vendor.status directly and bypass VendorService, so the
 * portal login was never provisioned, the temporary access window never opened,
 * and the activation email never fired — the vendor was "Approved" on paper but
 * could not actually log in. approve() now routes through
 * VendorService::updateStatus(ACTIVE), and reject()/hold() reflect onto the
 * vendor account too.
 *
 * We assert the SYNCHRONOUS effects (status + login + access window), which is
 * exactly what "actually activate" means. The activation e-mail is dispatched via
 * DB::afterCommit and is therefore deliberately out of scope here — under
 * RefreshDatabase the wrapping transaction never commits, so an afterCommit
 * assertion would fail for a reason that has nothing to do with the behaviour.
 */
class OnboardingActivationTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'tenant-1',
            'subdomain' => 'tenant1', 'status' => 'active',
        ])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(6).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    /**
     * A vendor with its own portal login, sitting where the wizard leaves it:
     * vendor Inactive, login not yet active, onboarding Submitted (awaiting the
     * admin decision).
     */
    private function pendingVendor(string $name, string $vendorType = 'permanent'): Vendor
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => $name, 'role' => 'third_party_vendor',
            'email' => strtolower(Str::slug($name)).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'inactive',
        ]);

        return Vendor::create([
            'tenant_id'   => self::TENANT, 'user_id' => $user->id,
            'company_name' => $name, 'email' => $user->email,
            'vendor_type' => $vendorType, 'status' => VendorStatus::INACTIVE,
        ]);
    }

    private function submittedOnboarding(Vendor $v): TpvOnboarding
    {
        $ob = TpvOnboarding::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id,
            'current_step' => Status::TOTAL_STEPS, 'status' => Status::SUBMITTED,
            'profile' => ['company_name' => $v->company_name],
        ]);

        // §10 — the general checklist gates activation, so a vendor ready for the
        // approve decision has its resolved checklist ticked. Seed it complete here;
        // the gate itself is exercised in OnboardingChecklistGateTest.
        $svc = app(TpvOnboardingService::class);
        $items = array_column($svc->checklist($ob)['items'], 'item');
        $svc->setChecklist($ob, array_fill_keys($items, true));

        return $ob->fresh();
    }

    public function test_approve_activates_the_vendor_and_its_portal_login(): void
    {
        $vendor = $this->pendingVendor('ActivateCo');
        $ob     = $this->submittedOnboarding($vendor);

        app(TpvOnboardingService::class)->approve($ob, $this->admin(), 'Looks good');

        $ob->refresh();
        $vendor->refresh();

        $this->assertSame(Status::APPROVED, $ob->status);
        $this->assertNotEmpty($ob->registration_number, 'a registration number is issued on approval');

        // The heart of the fix: the vendor account and its login are really live.
        $this->assertSame(VendorStatus::ACTIVE, $vendor->status);
        $this->assertSame('active', $vendor->user->fresh()->status, 'the portal login is provisioned active');
    }

    public function test_temporary_vendor_gets_a_five_day_access_window_at_approval(): void
    {
        $vendor = $this->pendingVendor('TempCo', 'temporary');
        $ob     = $this->submittedOnboarding($vendor);

        app(TpvOnboardingService::class)->approve($ob, $this->admin());

        $expires = $vendor->user->fresh()->access_expires_at;
        $this->assertNotNull($expires, 'a temporary vendor’s clock starts at activation');
        $this->assertSame(
            now()->addDays(5)->toDateString(),
            \Illuminate\Support\Carbon::parse($expires)->toDateString(),
        );
    }

    public function test_permanent_vendor_never_expires(): void
    {
        $vendor = $this->pendingVendor('PermCo', 'permanent');
        $ob     = $this->submittedOnboarding($vendor);

        app(TpvOnboardingService::class)->approve($ob, $this->admin());

        $this->assertNull($vendor->user->fresh()->access_expires_at);
    }

    /** Re-approving an already-active vendor changes nothing — the window is not pushed forward. */
    public function test_re_approval_is_idempotent(): void
    {
        $vendor = $this->pendingVendor('TwiceCo', 'temporary');
        $ob     = $this->submittedOnboarding($vendor);
        $svc    = app(TpvOnboardingService::class);

        $svc->approve($ob, $this->admin());
        $reg     = $ob->fresh()->registration_number;
        // Compare the value, not the Carbon instance — two reads are equal dates
        // but never the same object.
        $expires = (string) $vendor->user->fresh()->access_expires_at;

        $svc->approve($ob->fresh(), $this->admin());

        $this->assertSame($reg, $ob->fresh()->registration_number, 'the registration number is stable');
        $this->assertSame($expires, (string) $vendor->user->fresh()->access_expires_at, 'the access window is not re-opened');
        $this->assertSame(VendorStatus::ACTIVE, $vendor->fresh()->status);
    }

    public function test_reject_marks_the_vendor_rejected_and_locks_the_login(): void
    {
        $vendor = $this->pendingVendor('NopeCo');
        $ob     = $this->submittedOnboarding($vendor);

        app(TpvOnboardingService::class)->reject($ob, $this->admin(), 'Missing statutory cover');

        $this->assertSame(Status::REJECTED, $ob->fresh()->status);
        $this->assertSame(VendorStatus::REJECTED, $vendor->fresh()->status);
        $this->assertSame('inactive', $vendor->user->fresh()->status, 'a rejected vendor cannot log in');
    }

    public function test_hold_parks_the_vendor_and_release_returns_it_for_review(): void
    {
        $vendor = $this->pendingVendor('WaitCo');
        $ob     = $this->submittedOnboarding($vendor);
        $svc    = app(TpvOnboardingService::class);

        $svc->hold($ob, $this->admin(), 'Awaiting clarification');

        $this->assertSame(Status::ON_HOLD, $ob->fresh()->status);
        $this->assertSame(VendorStatus::ON_HOLD, $vendor->fresh()->status);
        $this->assertSame('inactive', $vendor->user->fresh()->status);

        $svc->release($ob->fresh(), $this->admin());

        $this->assertSame(Status::UNDER_REVIEW, $ob->fresh()->status);
        $this->assertSame(VendorStatus::PENDING_APPROVAL, $vendor->fresh()->status, 'the hold lifts back to a reviewable state');
    }
}
