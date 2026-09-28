<?php

namespace Tests\Feature\Portal;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Onboarding first. Everything else after.
 *
 * A vendor who has not been approved must not be able to run the operational
 * side of the portal — adding workers above all, because a worker record is what
 * later carries a medical clearance, an induction, a PPE issue and a site badge.
 * Letting one exist before the vendor is approved means a person can be walked
 * onto a site by a company nobody has cleared to be there.
 *
 * ── Why this needed a test rather than a glance ─────────────────────────
 * The portal ALREADY hid the workforce menu behind `vendor.status === 'Active'`,
 * so from a browser it looked enforced. It was not: EnsureVendorPortalAccess
 * checks the caller's ROLE and that a vendor profile exists, and nothing else,
 * so POST /api/portal/workers was open to a Draft vendor the whole time. Hiding
 * a menu is not a permission — anything with the vendor's token could post.
 *
 * ── What stays open before approval, deliberately ───────────────────────
 * Onboarding itself. A vendor has to reach the wizard, save their profile and
 * submit before anybody can approve them, so the gate covers the operational
 * routes only. Gating the whole portal would lock every vendor out of the one
 * thing they are there to do.
 */
class OnboardingGatesOperationsTest extends TestCase
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

    /** A TPV vendor with a portal login, at whatever status the case needs. */
    private function tpv(string $status): array
    {
        $login = User::create([
            'tenant_id' => self::TENANT, 'name' => 'AlphaCo', 'role' => 'third_party_vendor',
            'email' => 'alpha-'.Str::random(6).'@login.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'AlphaCo',
            'email' => 'alpha-'.Str::random(6).'@vendor.local',
            'status' => $status, 'user_id' => $login->id,
        ]);

        $this->onboardingFor($vendor, $status);

        return [$login, $vendor];
    }

    /**
     * The onboarding the vendor's state implies.
     *
     * The gate reads the ONBOARDING, not the status column, so a fixture that
     * sets a status and stops describes a vendor that cannot exist. Active gets
     * an approved onboarding; every other state gets one that is not approved,
     * which is the thing actually under test.
     */
    private function onboardingFor(object $vendor, string $status): void
    {
        $approved = $status === VendorStatus::ACTIVE;

        if ($vendor instanceof Vendor) {
            \App\Models\Tpv\TpvOnboarding::create([
                'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id,
                'status' => $approved ? 'Approved' : 'In_Progress',
                'current_step' => $approved ? 6 : 1,
            ]);

            return;
        }

        \App\Models\Purchase\PurchaseOnboarding::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id,
            'status' => $approved ? 'Approved' : 'In_Progress',
            'current_step' => $approved ? 6 : 1,
        ]);
    }

    private function purchase(string $status): PurchaseVendor
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sg-'.Str::random(4).'@t.local',
            'status' => $status, 'portal_status' => 'active',
        ]);

        $this->onboardingFor($vendor, $status);

        return $vendor;
    }

    /* ── TPV ─────────────────────────────────────────────────────────── */

    /**
     * @dataProvider notYetApproved
     */
    public function test_a_vendor_who_is_not_approved_cannot_add_a_worker(string $status): void
    {
        [$login] = $this->tpv($status);

        Sanctum::actingAs($login);
        $this->postJson('/api/portal/workers', ['name' => 'Ravi Kumar'])
            ->assertStatus(403);

        $this->assertSame(0, \App\Models\Tpv\TpvWorker::count(),
            "a {$status} vendor must not be able to create a worker record");
    }

    public static function notYetApproved(): array
    {
        return [
            'draft' => [VendorStatus::DRAFT],
            'pending approval' => [VendorStatus::PENDING_APPROVAL],
            'on hold' => [VendorStatus::ON_HOLD],
            'rejected' => [VendorStatus::REJECTED],
        ];
    }

    public function test_an_approved_vendor_can_add_a_worker(): void
    {
        [$login] = $this->tpv(VendorStatus::ACTIVE);

        Sanctum::actingAs($login);

        // The gate must not become a wall: this is the whole point of approving.
        $this->postJson('/api/portal/workers', ['name' => 'Ravi Kumar'])
            ->assertSuccessful();
    }

    public function test_an_unapproved_vendor_can_still_reach_onboarding(): void
    {
        [$login] = $this->tpv(VendorStatus::PENDING_APPROVAL);

        Sanctum::actingAs($login);

        // Otherwise the gate locks them out of the one thing they are here to
        // do, and no vendor could ever become approved in the first place.
        $this->getJson('/api/portal/onboarding')->assertSuccessful();
        $this->getJson('/api/portal/me')->assertSuccessful();
    }

    public function test_the_whole_operational_surface_is_gated_not_just_the_one_route(): void
    {
        [$login] = $this->tpv(VendorStatus::DRAFT);

        Sanctum::actingAs($login);

        // Gating only POST /workers would leave the bulk upload wide open, and
        // an import is the easy way to add two hundred of them. The gate is
        // default-deny across the operational surface, so these need no
        // individual rule -- which is the point of writing it that way.
        foreach ([
            '/api/portal/workers/upload',
            '/api/portal/contacts',
            '/api/portal/incidents',
        ] as $uri) {
            $this->postJson($uri, [])->assertStatus(403, "{$uri} must be closed before approval");
        }
    }

    public function test_reads_stay_open_so_the_awaiting_approval_screen_still_draws(): void
    {
        [$login] = $this->tpv(VendorStatus::DRAFT);

        Sanctum::actingAs($login);

        // The portal dashboard calls workers/stats to draw the "awaiting
        // approval" screen. Blocking reads would break the very page that tells
        // a vendor what they are waiting for, and an empty list of their own
        // workers discloses nothing.
        $this->getJson('/api/portal/workers/stats')->assertSuccessful();
        $this->getJson('/api/portal/workers')->assertSuccessful();
    }

    public function test_onboarding_writes_still_go_through_before_approval(): void
    {
        [$login, $vendor] = $this->tpv(VendorStatus::PENDING_APPROVAL);

        Sanctum::actingAs($login);

        // The gate must never block onboarding itself, or no vendor could ever
        // reach approval. A 403 here would mean the allowlist is wrong; any
        // other status is this route's own business (a 404 for a missing
        // onboarding is fine -- it is not a refusal by the gate).
        $status = $this->postJson('/api/portal/onboarding/1/profile', [])->getStatusCode();
        $this->assertNotSame(403, $status, 'onboarding writes must survive the gate');
    }

    /* ── Purchase ────────────────────────────────────────────────────── */

    public function test_an_unapproved_purchase_vendor_cannot_add_a_worker(): void
    {
        // portal_status is 'active' — the login works. That is a different
        // question from whether the company has been approved to operate, and
        // conflating the two is what left this open.
        $vendor = $this->purchase('Draft');

        Sanctum::actingAs($vendor);
        $this->postJson('/api/portal/purchase/workers', ['full_name' => 'Ravi Kumar'])
            ->assertStatus(403);

        $this->assertSame(0, \App\Models\Purchase\PurchaseWorker::count());
    }

    public function test_an_approved_purchase_vendor_can_add_a_worker(): void
    {
        $vendor = $this->purchase('Active');

        Sanctum::actingAs($vendor);
        $this->postJson('/api/portal/purchase/workers', ['full_name' => 'Ravi Kumar'])
            ->assertSuccessful();
    }

    public function test_an_unapproved_purchase_vendor_can_still_reach_onboarding(): void
    {
        $vendor = $this->purchase('Draft');

        Sanctum::actingAs($vendor);
        $this->getJson('/api/portal/purchase/onboarding')->assertSuccessful();
    }
}
