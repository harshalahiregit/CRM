<?php

namespace Tests\Feature\Shared;

use App\Models\Purchase\PurchaseOnboarding;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\Tpv\TpvOnboarding;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An approved vendor can fetch its own work-start letter — on both engines.
 *
 * The letter is the document that says a vendor is cleared to start work, so the
 * one party who most needs a copy is the vendor. TPV has offered it in the portal
 * since the portal existed. Purchase generated it, stored it, and exposed it to
 * ADMINISTRATORS only — there was no Purchase portal route at all, so the vendor
 * it is about could not reach it.
 *
 * That is the shape of nearly every Purchase/TPV difference found so far: not a
 * deliberate policy, just a route that was never copied across. This holds the
 * two portals level so the next one is a failing test rather than a support call.
 */
class OnboardingWorkStartLetterTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $vendorUser;

    private PurchaseVendor $pVendor;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->vendorUser = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Rita Bose', 'role' => 'third_party_vendor',
            'email' => 'v-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->pVendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate Industrial',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sg-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    private function tpv(string $status): TpvOnboarding
    {
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme',
            'status' => VendorStatus::ACTIVE, 'user_id' => $this->vendorUser->id,
        ]);

        return TpvOnboarding::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id,
            'status' => $status, 'current_step' => 6,
        ]);
    }

    private function purchase(string $status): PurchaseOnboarding
    {
        return PurchaseOnboarding::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $this->pVendor->id,
            'status' => $status, 'current_step' => 6,
        ]);
    }

    private function fetchLetter(string $engine, string $status)
    {
        if ($engine === 'tpv') {
            $ob = $this->tpv($status);
            Sanctum::actingAs($this->vendorUser);

            return $this->get("/api/portal/onboarding/{$ob->id}/work-start-letter");
        }

        $ob = $this->purchase($status);
        Sanctum::actingAs($this->pVendor);

        return $this->get("/api/portal/purchase/onboarding/{$ob->id}/work-start-letter");
    }

    public static function engines(): array
    {
        return ['TPV' => ['tpv'], 'Purchase' => ['purchase']];
    }

    /**
     * The route Purchase never had.
     *
     * @dataProvider engines
     */
    public function test_an_approved_vendor_can_fetch_its_own_letter(string $engine): void
    {
        $this->fetchLetter($engine, 'Approved')->assertOk();
    }

    /**
     * And before approval there is nothing to issue — answered as a plain 404
     * with a sentence, not a server error.
     *
     * @dataProvider engines
     */
    public function test_it_is_not_available_before_approval(string $engine): void
    {
        $res = $this->fetchLetter($engine, 'In_Progress')->assertNotFound();

        $this->assertStringContainsString('approved', strtolower((string) $res->json('message')),
            'the vendor is told why, not just refused');
    }

    /** One vendor may not read another's letter, on either engine. */
    public function test_a_purchase_vendor_cannot_read_anothers_letter(): void
    {
        $ob = $this->purchase('Approved');

        $rival = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Rival Ltd',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'rv-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);

        Sanctum::actingAs($rival);
        $this->get("/api/portal/purchase/onboarding/{$ob->id}/work-start-letter")->assertNotFound();
    }

    public function test_a_tpv_vendor_cannot_read_anothers_letter(): void
    {
        $ob = $this->tpv('Approved');

        $other = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Rival', 'role' => 'third_party_vendor',
            'email' => 'rival-'.Str::random(4).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Rival Ltd',
            'status' => VendorStatus::ACTIVE, 'user_id' => $other->id,
        ]);

        Sanctum::actingAs($other);
        $this->get("/api/portal/onboarding/{$ob->id}/work-start-letter")->assertNotFound();
    }
}
