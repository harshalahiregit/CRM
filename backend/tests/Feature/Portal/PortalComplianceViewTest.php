<?php

namespace Tests\Feature\Portal;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseVendorCompliance;
use App\Models\Tenant;
use App\Models\Tpv\TpvVendorCompliance;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Purchase\PurchaseVendorStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * §32 "View compliance" — a vendor sees ONLY their own compliance register on
 * their portal, on both the TPV and Purchase portals (separate identities/tables).
 */
class PortalComplianceViewTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    public function test_tpv_vendor_sees_own_compliance(): void
    {
        $vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE, 'email' => 'acme@test.local']);
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Acme Portal', 'role' => 'third_party_vendor',
            'email' => 'acme@test.local', 'password' => bcrypt('secret'), 'status' => 'active',
        ]);
        $vendor->update(['user_id' => $user->id]);
        TpvVendorCompliance::create(['tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'category' => 'Legal', 'status' => 'Compliant']);

        Sanctum::actingAs($user);
        $res = $this->getJson('/api/portal/compliance');

        $res->assertOk()
            ->assertJsonStructure(['matrix', 'score' => ['percent', 'ok', 'problems']]);
        $this->assertSame(1, $res->json('score.ok'));
        $this->assertGreaterThan(0, $res->json('score.percent'));
    }

    public function test_purchase_vendor_sees_own_compliance(): void
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Bolt Supplies',
            'purchase_vendor_code' => 'PV-'.uniqid(), 'status' => PurchaseVendorStatus::ACTIVE,
            'portal_status' => 'active',
        ]);
        PurchaseVendorCompliance::create(['tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id, 'category' => 'Legal', 'status' => 'Compliant']);

        Sanctum::actingAs($vendor);
        $res = $this->getJson('/api/portal/purchase/compliance');

        $res->assertOk()
            ->assertJsonStructure(['matrix', 'score' => ['percent', 'ok', 'problems']]);
        $this->assertSame(1, $res->json('score.ok'));
    }
}
