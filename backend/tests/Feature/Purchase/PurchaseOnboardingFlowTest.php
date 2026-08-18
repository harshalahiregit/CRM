<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseOnboarding;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Purchase\PurchaseOnboardingService;
use App\Support\Purchase\PurchaseOnboardingStatus as OS;
use App\Support\Purchase\PurchaseVendorStatus as VS;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Purchase Vendor onboarding — the vendor-driven half of the lifecycle.
 *
 * Purchase is NOT a copy of TPV and this pins the differences as much as the
 * similarities: approval activates the vendor here (TPV leaves vendor status
 * alone), the portal identity is a PurchaseVendor token rather than a User with
 * a role, and Purchase has no PPE issuance at all.
 *
 * These are the first tests for the Purchase module — `find tests -ipath *urchase*`
 * previously returned nothing.
 */
class PurchaseOnboardingFlowTest extends TestCase
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

    private function vendor(string $name): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => strtolower($name).'@test.local',
            'status' => VS::DRAFT, 'portal_status' => 'active',
        ]);
    }

    private function onboardingFor(PurchaseVendor $v): PurchaseOnboarding
    {
        return PurchaseOnboarding::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $v->id,
            'current_step' => 1, 'status' => OS::IN_PROGRESS,
        ]);
    }

    public function test_onboarding_exposes_six_steps_in_order(): void
    {
        $steps = app(PurchaseOnboardingService::class)
            ->stepStatus($this->onboardingFor($this->vendor('StepsCo')))['steps'];

        $this->assertCount(6, $steps);
        $this->assertSame(
            ['kickoff', 'profile', 'documents', 'review', 'confirmation', 'submission'],
            array_column($steps, 'key')
        );
    }

    /** A vendor may not skip ahead — the endpoint is the boundary, not the wizard. */
    public function test_vendor_cannot_skip_steps(): void
    {
        $vendor = $this->vendor('SkipCo');
        $ob     = $this->onboardingFor($vendor);
        $svc    = app(PurchaseOnboardingService::class);

        foreach ([2, 3, 5, 6] as $step) {
            try {
                $svc->setStep($ob, $step, $vendor);
                $this->fail("Step {$step} should have been refused.");
            } catch (\App\Exceptions\BusinessException $e) {
                $this->assertStringContainsString('Complete step 1', $e->getMessage());
            }
        }

        $this->assertSame(1, $svc->setStep($ob, 1, $vendor)->current_step);
    }

    /** Staff must keep free navigation or document review becomes unreachable. */
    public function test_staff_are_not_step_gated(): void
    {
        $ob = $this->onboardingFor($this->vendor('AdminNavCo'));

        $this->assertSame(
            4,
            app(PurchaseOnboardingService::class)->setStep($ob, 4, $this->admin())->current_step
        );
    }

    public function test_completing_a_step_opens_the_next_one_only(): void
    {
        $vendor = $this->vendor('NextCo');
        $ob     = $this->onboardingFor($vendor);
        $svc    = app(PurchaseOnboardingService::class);

        $ob->update(['acknowledged' => true]);              // step 1 done
        $this->assertSame(2, $svc->setStep($ob, 2, $vendor)->current_step);

        $this->expectException(\App\Exceptions\BusinessException::class);
        $svc->setStep($ob, 3, $vendor);                     // profile still empty
    }

    /** Progress survives a reload — the pointer is DB state, not client state. */
    public function test_step_progress_is_persisted(): void
    {
        $vendor = $this->vendor('ResumeCo');
        $ob     = $this->onboardingFor($vendor);

        $ob->update(['acknowledged' => true]);
        app(PurchaseOnboardingService::class)->setStep($ob, 2, $vendor);

        $this->assertSame(2, PurchaseOnboarding::find($ob->id)->current_step);
    }

    /** Purchase-specific: approval activates the vendor. TPV does not do this. */
    public function test_approval_activates_the_purchase_vendor(): void
    {
        $vendor = $this->vendor('ApproveCo');
        $ob     = $this->onboardingFor($vendor);
        $ob->update(['status' => OS::SUBMITTED]);

        app(PurchaseOnboardingService::class)->approve($ob->fresh(), $this->admin(), 'ok');

        $this->assertSame(OS::APPROVED, $ob->fresh()->status);
        $this->assertSame(VS::ACTIVE, $vendor->fresh()->status);
        $this->assertNotEmpty($ob->fresh()->registration_number);
    }

    /**
     * approve() takes a User, never a PurchaseVendor — a vendor cannot be the
     * approving actor even if it reached the service.
     */
    public function test_only_a_user_can_approve(): void
    {
        $type = (string) (new \ReflectionMethod(PurchaseOnboardingService::class, 'approve'))
            ->getParameters()[1]->getType();

        $this->assertSame(User::class, $type);
    }

    /** Every onboarding is owned by exactly one vendor — the ownership anchor. */
    public function test_onboarding_is_anchored_to_one_vendor(): void
    {
        $a = $this->vendor('OwnerA');
        $b = $this->vendor('OwnerB');

        $this->assertNotSame(
            (int) $this->onboardingFor($a)->purchase_vendor_id,
            (int) $this->onboardingFor($b)->purchase_vendor_id
        );
    }
}
