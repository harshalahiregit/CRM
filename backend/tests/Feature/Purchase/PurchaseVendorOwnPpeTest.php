<?php

namespace Tests\Feature\Purchase;

use App\Models\Inventory\Category;
use App\Models\Inventory\Movement;
use App\Models\Inventory\Product;
use App\Models\Inventory\Warehouse;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseVendorPpeItem;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Purchase worker PPE, end to end, over HTTP.
 *
 * Two things are pinned here.
 *
 * 1. "I issued PPE as admin and it doesn't show in the worker's PPE section."
 *    The worker wizard's PPE step is ONE component that reads `issues` and
 *    `compliance`. The admin route answered that shape; the portal route
 *    answered a bare array, so on the portal every worker read "No PPE issued".
 *    And the catalogue's "Issued" column counted TPV's table, so every
 *    Purchase hand-out read as zero.
 *
 * 2. A vendor's OWN PPE list: the vendor adds items (its stock, never the
 *    company's Inventory), issues them to its own workers, and the worker's
 *    PPE and the admin's view of the vendor both show them.
 */
class PurchaseVendorOwnPpeTest extends TestCase
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

    /* ── Fixtures ─────────────────────────────────────────────────────── */

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(string $name): PurchaseVendor
    {
        $v = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => strtolower($name).'-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);

        return $this->markOnboarded($v);
    }

    private function worker(PurchaseVendor $v, string $name = 'Ravi Kumar'): PurchaseWorker
    {
        return PurchaseWorker::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $v->id,
            'full_name' => $name, 'worker_code' => 'PW-'.Str::random(5),
            'gender' => 'Male', 'status' => 'Pending', 'current_step' => 3,
        ]);
    }

    /** A central Inventory product in the PPE category, with stock. */
    private function inventoryPpe(float $qty = 50): Product
    {
        $cat = Category::firstOrCreate(['tenant_id' => self::TENANT, 'name' => 'PPE']);
        $wh = Warehouse::firstOrCreate(
            ['tenant_id' => self::TENANT, 'code' => 'WH1'],
            ['name' => 'Main', 'is_default' => true],
        );
        $p = Product::create([
            'tenant_id' => self::TENANT, 'name' => 'Safety Helmet', 'category_id' => $cat->id,
            'sku' => 'PPE-'.Str::random(5), 'status' => 'Active',
        ]);
        app(StockService::class)->adjustTo($p->id, $wh->id, $qty, self::TENANT, null, 'baseline');

        return $p;
    }

    private function addOwnItem(PurchaseVendor $v, array $over = []): int
    {
        Sanctum::actingAs($v);

        return (int) $this->postJson('/api/portal/purchase/ppe/my-items', array_merge([
            'name' => 'Vendor Gloves', 'category' => 'gloves', 'size' => 'L',
            'unit' => 'pairs', 'qty_in_stock' => 10,
        ], $over))->assertCreated()->json('id');
    }

    /* ── Bug A — admin-issued PPE reaches the worker's PPE section ─────── */

    public function test_portal_worker_ppe_answers_the_same_contract_as_admin_and_shows_admin_issues(): void
    {
        $p = $this->inventoryPpe(20);
        $v = $this->vendor('Southgate');
        $w = $this->worker($v);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/purchase/workforce/workers/{$w->id}/ppe/issue", [
            'product_id' => $p->id, 'qty' => 2,
        ])->assertCreated();

        // The admin side, as before.
        $admin = $this->getJson("/api/purchase/workforce/workers/{$w->id}/ppe")->assertOk()->json();
        $this->assertCount(1, $admin['issues']);

        // The vendor's portal — the SAME shape, the SAME row.
        Sanctum::actingAs($v);
        $portal = $this->getJson("/api/portal/purchase/workers/{$w->id}/ppe")->assertOk()->json();

        $this->assertArrayHasKey('issues', $portal, 'the wizard reads data.issues — a bare array reads as "No PPE issued"');
        $this->assertArrayHasKey('compliance', $portal);
        $this->assertCount(1, $portal['issues']);
        $this->assertSame('Safety Helmet', $portal['issues'][0]['item']);
        $this->assertSame('inventory', $portal['issues'][0]['source']);
        $this->assertEqualsWithDelta(2, (float) $portal['issues'][0]['qty'], 0.001);
        $this->assertSame(1, $portal['compliance']['held_count']);
        $this->assertSame(array_keys($admin), array_keys($portal));
    }

    public function test_purchase_catalogue_counts_purchase_issues(): void
    {
        $p = $this->inventoryPpe(20);
        $v = $this->vendor('Counter');
        $w = $this->worker($v);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/purchase/workforce/workers/{$w->id}/ppe/issue", [
            'product_id' => $p->id, 'qty' => 3,
        ])->assertCreated();

        $adminRow = collect($this->getJson('/api/purchase/workforce/ppe/catalogue')->assertOk()->json('data'))
            ->firstWhere('product_id', $p->id);
        $this->assertEqualsWithDelta(3, (float) $adminRow['issued'], 0.001, 'admin catalogue counted TPV issues only');

        Sanctum::actingAs($v);
        $portalRow = collect($this->getJson('/api/portal/purchase/ppe')->assertOk()->json())
            ->firstWhere('product_id', $p->id);
        $this->assertEqualsWithDelta(3, (float) $portalRow['issued'], 0.001, 'portal catalogue counted TPV issues only');
        $this->assertEqualsWithDelta(17, (float) $portalRow['available'], 0.001);
    }

    /* ── Feature B — the vendor's own PPE list ─────────────────────────── */

    public function test_vendor_creates_and_lists_its_own_ppe_item(): void
    {
        $v = $this->vendor('Maker');
        $id = $this->addOwnItem($v);

        $rows = $this->getJson('/api/portal/purchase/ppe/my-items')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($id, $rows[0]['id']);
        $this->assertSame('Vendor Gloves', $rows[0]['name']);
        $this->assertEqualsWithDelta(10, (float) $rows[0]['qty_in_stock'], 0.001);

        $this->assertDatabaseHas('purchase_vendor_ppe_items', [
            'id' => $id, 'purchase_vendor_id' => $v->id, 'tenant_id' => self::TENANT,
        ]);
        // Its stock, not the company's: nothing enters the Inventory ledger.
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Movement::count());
    }

    public function test_vendor_can_edit_and_deactivate_its_own_item(): void
    {
        $v = $this->vendor('Editor');
        $id = $this->addOwnItem($v);

        $this->postJson("/api/portal/purchase/ppe/my-items/{$id}", ['qty_in_stock' => 25, 'size' => 'XL'])
            ->assertOk()->assertJsonPath('size', 'XL');
        $this->assertEqualsWithDelta(25, (float) PurchaseVendorPpeItem::find($id)->qty_in_stock, 0.001);

        $this->patchJson("/api/portal/purchase/ppe/my-items/{$id}/status", ['is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false);
    }

    public function test_a_vendor_cannot_see_or_edit_another_vendors_items(): void
    {
        $a = $this->vendor('Alpha');
        $b = $this->vendor('Beta');
        $bItem = $this->addOwnItem($b, ['name' => 'Beta Helmet']);

        Sanctum::actingAs($a);
        $this->assertSame([], $this->getJson('/api/portal/purchase/ppe/my-items')->assertOk()->json('data'));
        $this->postJson("/api/portal/purchase/ppe/my-items/{$bItem}", ['qty_in_stock' => 0])->assertNotFound();
        $this->patchJson("/api/portal/purchase/ppe/my-items/{$bItem}/status", ['is_active' => false])->assertNotFound();

        $this->assertEqualsWithDelta(10, (float) PurchaseVendorPpeItem::find($bItem)->qty_in_stock, 0.001);
        $this->assertTrue((bool) PurchaseVendorPpeItem::find($bItem)->is_active);
    }

    public function test_vendor_issues_own_item_stock_decrements_and_worker_ppe_shows_it(): void
    {
        $v = $this->vendor('Issuer');
        $w = $this->worker($v);
        $id = $this->addOwnItem($v);

        $this->postJson("/api/portal/purchase/workers/{$w->id}/ppe/issue", [
            'vendor_ppe_item_id' => $id, 'qty' => 4,
        ])->assertCreated()->assertJsonPath('vendor_ppe_item_id', $id);

        $this->assertEqualsWithDelta(6, (float) PurchaseVendorPpeItem::find($id)->qty_in_stock, 0.001);
        $this->assertSame(0, Movement::count(), 'vendor stock must never write the company ledger');
        $this->assertSame(4, (int) $w->fresh()->current_step, 'issuing PPE completes step 4');

        // The worker's PPE section, on the portal …
        $portal = $this->getJson("/api/portal/purchase/workers/{$w->id}/ppe")->assertOk()->json();
        $this->assertSame('Vendor Gloves', $portal['issues'][0]['item']);
        $this->assertSame('vendor', $portal['issues'][0]['source']);
        $this->assertSame(1, $portal['compliance']['held_count']);

        // … and on the admin side.
        Sanctum::actingAs($this->admin());
        $admin = $this->getJson("/api/purchase/workforce/workers/{$w->id}/ppe")->assertOk()->json();
        $this->assertSame('Vendor Gloves', $admin['issues'][0]['item']);

        // Admin reads the vendor's list, with what is out on workers.
        $list = $this->getJson("/api/purchase/ppe/vendors/{$v->id}/items")->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertEqualsWithDelta(6, (float) $list[0]['qty_in_stock'], 0.001);
        $this->assertEqualsWithDelta(4, (float) $list[0]['issued'], 0.001);
    }

    public function test_over_issue_of_own_item_is_refused_and_stock_is_untouched(): void
    {
        $v = $this->vendor('Short');
        $w = $this->worker($v);
        $id = $this->addOwnItem($v, ['qty_in_stock' => 2]);

        $this->postJson("/api/portal/purchase/workers/{$w->id}/ppe/issue", [
            'vendor_ppe_item_id' => $id, 'qty' => 3,
        ])->assertStatus(422);

        $this->assertEqualsWithDelta(2, (float) PurchaseVendorPpeItem::find($id)->qty_in_stock, 0.001);
        $this->assertDatabaseCount('purchase_worker_ppe_issues', 0);
    }

    public function test_a_vendor_cannot_issue_another_vendors_item(): void
    {
        $a = $this->vendor('Alpha');
        $b = $this->vendor('Beta');
        $wa = $this->worker($a);
        $bItem = $this->addOwnItem($b);

        Sanctum::actingAs($a);
        $this->postJson("/api/portal/purchase/workers/{$wa->id}/ppe/issue", [
            'vendor_ppe_item_id' => $bItem, 'qty' => 1,
        ])->assertNotFound();

        $this->assertEqualsWithDelta(10, (float) PurchaseVendorPpeItem::find($bItem)->qty_in_stock, 0.001);
    }

    public function test_returning_own_item_restocks_it_and_write_off_does_not(): void
    {
        $v = $this->vendor('Returner');
        $w = $this->worker($v);
        $id = $this->addOwnItem($v);

        $issue = $this->postJson("/api/portal/purchase/workers/{$w->id}/ppe/issue", [
            'vendor_ppe_item_id' => $id, 'qty' => 3,
        ])->assertCreated()->json('id');

        // No warehouse exists in this tenant at all — a vendor-stock return
        // must not need one.
        $this->postJson("/api/portal/purchase/ppe/issues/{$issue}/return", [
            'condition' => 'returned', 'qty' => 2,
        ])->assertOk();
        $this->assertEqualsWithDelta(9, (float) PurchaseVendorPpeItem::find($id)->qty_in_stock, 0.001);

        $this->postJson("/api/portal/purchase/ppe/issues/{$issue}/return", [
            'condition' => 'lost', 'qty' => 1,
        ])->assertOk();
        $this->assertEqualsWithDelta(9, (float) PurchaseVendorPpeItem::find($id)->qty_in_stock, 0.001);
    }

    public function test_deactivated_item_cannot_be_issued(): void
    {
        $v = $this->vendor('Retired');
        $w = $this->worker($v);
        $id = $this->addOwnItem($v);
        $this->patchJson("/api/portal/purchase/ppe/my-items/{$id}/status", ['is_active' => false])->assertOk();

        $this->postJson("/api/portal/purchase/workers/{$w->id}/ppe/issue", [
            'vendor_ppe_item_id' => $id, 'qty' => 1,
        ])->assertStatus(422);
    }

    public function test_admin_cannot_read_another_tenants_vendor_list(): void
    {
        $v = $this->vendor('Local');
        $this->addOwnItem($v);

        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'Tenant 2', 'slug' => 'tenant-2', 'subdomain' => 'tenant2', 'status' => 'active',
        ])->save();
        $foreign = User::create([
            'tenant_id' => 2, 'name' => 'Other', 'role' => 'admin',
            'email' => 'o-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        Sanctum::actingAs($foreign);
        $this->getJson("/api/purchase/ppe/vendors/{$v->id}/items")->assertNotFound();
    }
}
