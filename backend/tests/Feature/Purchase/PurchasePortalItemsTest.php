<?php

namespace Tests\Feature\Purchase;

use App\Models\Inventory\Product;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseVendorItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The items a Purchase vendor is approved to supply, on their own portal.
 *
 * Admin has always been able to map Inventory items to a vendor
 * (purchase_vendor_items) and there was no portal endpoint at all, so the one
 * party the mapping is ABOUT could not see it. The mapping existed, was correct,
 * and was invisible.
 */
class PurchasePortalItemsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private PurchaseVendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->vendor = $this->vendorNamed('Acme');
    }

    private function vendorNamed(string $name): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => strtolower($name).'-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    private function product(string $name, string $sku): Product
    {
        return Product::create([
            'tenant_id' => self::TENANT, 'name' => $name, 'sku' => $sku, 'base_unit' => 'Nos',
        ]);
    }

    private function map(PurchaseVendor $vendor, Product $product, array $extra = []): PurchaseVendorItem
    {
        return PurchaseVendorItem::create(array_merge([
            'tenant_id'            => self::TENANT,
            'purchase_vendor_id'   => $vendor->id,
            'inventory_product_id' => $product->id,
            'status'               => 'Active',
        ], $extra));
    }

    public function test_a_mapped_item_reaches_the_vendors_portal(): void
    {
        $this->map($this->vendor, $this->product('Safety Goggles', 'SG-001'), [
            'effective_date' => '2026-01-15',
            'remarks'        => 'Clear lens only',
        ]);

        Sanctum::actingAs($this->vendor);
        $rows = $this->getJson('/api/portal/purchase/items')->assertOk()->json();

        $this->assertCount(1, $rows);
        $this->assertSame('Safety Goggles', $rows[0]['item']['name']);
        $this->assertSame('SG-001', $rows[0]['item']['sku']);
        $this->assertSame('Nos', $rows[0]['item']['unit']);
        $this->assertSame('2026-01-15', $rows[0]['effective_date']);
        $this->assertSame('Clear lens only', $rows[0]['remarks']);
    }

    /**
     * Item facts come from the Inventory product, never copied onto the mapping.
     * Renaming the item in Inventory must change what the vendor sees.
     */
    public function test_item_facts_follow_the_inventory_master(): void
    {
        $product = $this->product('Goggles', 'SG-001');
        $this->map($this->vendor, $product);

        $product->update(['name' => 'Safety Goggles (Clear)']);

        Sanctum::actingAs($this->vendor);
        $rows = $this->getJson('/api/portal/purchase/items')->assertOk()->json();

        $this->assertSame('Safety Goggles (Clear)', $rows[0]['item']['name']);
    }

    public function test_another_vendors_items_are_never_listed(): void
    {
        $rival = $this->vendorNamed('Rival');
        $this->map($rival, $this->product('Their Item', 'TI-001'));
        $this->map($this->vendor, $this->product('My Item', 'MI-001'));

        Sanctum::actingAs($this->vendor);
        $rows = $this->getJson('/api/portal/purchase/items')->assertOk()->json();

        $this->assertCount(1, $rows);
        $this->assertSame('My Item', $rows[0]['item']['name']);
    }

    public function test_a_vendor_with_no_mapped_items_gets_an_empty_list(): void
    {
        Sanctum::actingAs($this->vendor);

        $this->getJson('/api/portal/purchase/items')->assertOk()->assertJsonCount(0);
    }

    /**
     * Costs and stock levels are the buyer's, not the vendor's. The payload is
     * built field by field rather than serialising the product, so a column
     * added to Inventory later cannot leak here by default.
     */
    public function test_the_payload_carries_no_internal_pricing_or_stock(): void
    {
        $this->map($this->vendor, $this->product('Safety Goggles', 'SG-001'));

        Sanctum::actingAs($this->vendor);
        $body = $this->getJson('/api/portal/purchase/items')->assertOk()->getContent();

        foreach (['cost_price', 'purchase_price', 'sale_price', 'min_stock', 'reorder_point'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_an_admin_user_cannot_read_the_portal_item_list(): void
    {
        Sanctum::actingAs(User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]));

        $this->getJson('/api/portal/purchase/items')->assertStatus(403);
    }
}
