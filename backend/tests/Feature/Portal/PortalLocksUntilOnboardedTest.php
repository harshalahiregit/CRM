<?php

namespace Tests\Feature\Portal;

use App\Models\Purchase\PurchaseOnboarding;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\Tpv\TpvOnboarding;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Both vendor portals stay shut until onboarding is approved.
 *
 * The nav lock lives in the client (lib/vendors/workspaceLock — see
 * scripts/vendor-lock.check.mjs for its own cases), but it can only work if
 * /me actually SHIPS the onboarding. It did not: both portals loaded contacts
 * and the account manager and nothing else, so the client found no onboarding
 * and fell back to the vendor's status column — which routinely says Active
 * while the wizard is still at step 1. Every one of those vendors saw the whole
 * portal open on first login.
 *
 * So what is pinned here is the payload, because that is the half that silently
 * turns the lock off. TPV additionally has to expose it under the key the
 * SHARED PortalShell reads: the relation is tpvOnboarding (a Vendor can carry a
 * Purchase onboarding too) but the client asks for `onboarding`.
 */
class PortalLocksUntilOnboardedTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Sangoe OS', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function user(string $role): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Vendor Rep', 'role' => $role,
            'email' => 'v-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /* ── Purchase portal ─────────────────────────────────────────────── */

    private function purchaseVendor(string $obStatus): array
    {
        $user = $this->user('vendor');
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => $user->email, 'user_id' => $user->id,
            // Active on purpose: this is the state that used to unlock everything.
            'status' => 'Active', 'portal_status' => 'active',
        ]);

        PurchaseOnboarding::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id,
            'status' => $obStatus, 'current_step' => $obStatus === 'Approved' ? 6 : 1,
        ]);

        return [$user, $vendor];
    }

    public function test_the_purchase_portal_ships_the_onboarding_so_the_nav_can_lock(): void
    {
        [, $vendor] = $this->purchaseVendor('In_Progress');

        // The Purchase portal authenticates as the PurchaseVendor itself
        // (tokenable = purchase_vendors), not as a User.
        Sanctum::actingAs($vendor);
        $res = $this->getJson('/api/portal/purchase/me')->assertOk();

        $this->assertNotNull($res->json('vendor.onboarding'),
            'without this the client falls back to vendor.status and unlocks everything');
        $this->assertSame('In_Progress', $res->json('vendor.onboarding.status'));
    }

    public function test_an_approved_purchase_onboarding_reads_as_approved(): void
    {
        [, $vendor] = $this->purchaseVendor('Approved');

        Sanctum::actingAs($vendor);
        $this->getJson('/api/portal/purchase/me')->assertOk()
            ->assertJsonPath('vendor.onboarding.status', 'Approved');
    }

    /* ── TPV portal ──────────────────────────────────────────────────── */

    private function tpvVendor(string $obStatus): array
    {
        $user = $this->user('third_party_vendor');
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'user_id' => $user->id,
            'company_name' => 'Acme TPV', 'vendor_code' => 'V-'.strtoupper(Str::random(5)),
            'email' => $user->email, 'status' => 'Active',
        ]);

        TpvOnboarding::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id,
            'status' => $obStatus, 'current_step' => $obStatus === 'Approved' ? 6 : 1,
        ]);

        return [$user, $vendor];
    }

    /**
     * The key matters as much as the value.
     *
     * PortalShell is shared by both portals and reads `vendor.onboarding`. TPV's
     * relation is named tpvOnboarding, so without the alias the client sees
     * nothing here and the lock quietly turns itself off.
     */
    public function test_the_tpv_portal_ships_the_onboarding_under_the_shared_key(): void
    {
        [$user] = $this->tpvVendor('In_Progress');

        Sanctum::actingAs($user);
        $res = $this->getJson('/api/portal/me')->assertOk();

        $this->assertNotNull($res->json('vendor.onboarding'),
            'the shared PortalShell reads vendor.onboarding, not vendor.tpvOnboarding');
        $this->assertSame('In_Progress', $res->json('vendor.onboarding.status'));
    }

    public function test_an_approved_tpv_onboarding_reads_as_approved(): void
    {
        [$user] = $this->tpvVendor('Approved');

        Sanctum::actingAs($user);
        $this->getJson('/api/portal/me')->assertOk()
            ->assertJsonPath('vendor.onboarding.status', 'Approved');
    }

    /* ── and the gate behind the nav ─────────────────────────────────── */

    /**
     * Hiding the menu is not the control — this is.
     *
     * EnsureVendorOnboardingComplete refuses operational WRITES until the vendor
     * is onboarded, and it asked `status === Active`. A vendor set Active with
     * its onboarding still In_Progress therefore walked straight through the one
     * gate that exists to stop it, and could create the worker records the
     * middleware's own docblock describes as the reason it exists.
     */
    public function test_an_active_vendor_mid_onboarding_still_cannot_write(): void
    {
        [$user, $vendor] = $this->tpvVendor('In_Progress');

        Sanctum::actingAs($user);
        $res = $this->postJson('/api/portal/workers', [
            'full_name' => 'Ravi Kumar',
            'gender' => 'Male',
            'dob' => now()->subYears(30)->toDateString(),
        ]);

        $this->assertSame(403, $res->getStatusCode(),
            'an unfinished onboarding must refuse operational writes, whatever the status column says');
        $this->assertSame(0, \App\Models\Tpv\TpvWorker::where('vendor_id', $vendor->id)->count());
    }

    /** And the wizard itself stays open, or nobody could ever get approved. */
    public function test_the_onboarding_wizard_is_still_writable_while_locked(): void
    {
        [$user, $vendor] = $this->tpvVendor('In_Progress');
        $onboarding = TpvOnboarding::where('vendor_id', $vendor->id)->firstOrFail();

        Sanctum::actingAs($user);
        $res = $this->postJson("/api/portal/onboarding/{$onboarding->id}/profile", [
            'profile' => ['legal_name' => 'Acme TPV Pvt Ltd'],
        ]);

        $this->assertNotSame(403, $res->getStatusCode(),
            'blocking the wizard would make approval unreachable');
    }
}
