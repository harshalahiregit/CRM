<?php

namespace Tests\Feature\Portal;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorShipment;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Compliance & HSSE › Pre Alert / Packages / Shipping — a TPV vendor creates a
 * dispatch notice with packages, advances its status, and sees only its own.
 */
class PortalShipmentsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function portalVendor(string $name = 'Acme'): Vendor
    {
        $vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => $name, 'status' => VendorStatus::ACTIVE, 'email' => strtolower($name).'@test.local']);
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => $name.' Portal', 'role' => 'third_party_vendor',
            'email' => strtolower($name).'@test.local', 'password' => bcrypt('secret'), 'status' => 'active',
        ]);
        $vendor->update(['user_id' => $user->id]);

        return $vendor->fresh();
    }

    public function test_vendor_creates_a_pre_alert_with_packages(): void
    {
        $vendor = $this->portalVendor();

        Sanctum::actingAs($vendor->user);
        $res = $this->postJson('/api/portal/shipments', [
            'courier' => 'BlueDart', 'tracking_number' => 'BD123', 'expected_date' => '2026-03-01',
            'packages' => [
                ['description' => 'Steel frame', 'qty' => 2, 'weight' => '120kg'],
                ['description' => 'Bolts box', 'qty' => 1],
            ],
        ])->assertCreated();

        $res->assertJsonPath('status', 'Pre-Alert');
        $this->assertStringStartsWith('SHP-', $res->json('reference'));

        $list = $this->getJson('/api/portal/shipments')->assertOk();
        $list->assertJsonPath('data.0.packages_count', 2);

        $this->getJson('/api/portal/shipment-packages')->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_vendor_advances_shipment_status(): void
    {
        $vendor = $this->portalVendor();
        $ship = VendorShipment::create(['tenant_id' => self::TENANT, 'vendor_id' => $vendor->id, 'status' => 'Pre-Alert']);

        Sanctum::actingAs($vendor->user);
        $this->patchJson("/api/portal/shipments/{$ship->id}/status", ['status' => 'Dispatched'])
            ->assertOk()->assertJsonPath('status', 'Dispatched');

        $this->assertNotNull($ship->fresh()->dispatched_on);
    }

    public function test_vendor_cannot_touch_another_vendors_shipment(): void
    {
        $a = $this->portalVendor('Acme');
        $b = $this->portalVendor('BetaCo');
        $shipB = VendorShipment::create(['tenant_id' => self::TENANT, 'vendor_id' => $b->id, 'status' => 'Pre-Alert']);

        Sanctum::actingAs($a->user);
        $this->patchJson("/api/portal/shipments/{$shipB->id}/status", ['status' => 'Dispatched'])->assertNotFound();
        $this->getJson('/api/portal/shipments')->assertOk()->assertJsonCount(0, 'data');
    }
}
