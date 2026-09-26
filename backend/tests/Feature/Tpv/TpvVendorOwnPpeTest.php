<?php

namespace Tests\Feature\Tpv;

use App\Models\Inventory\Movement;
use App\Models\Inventory\Product;
use App\Models\Inventory\Stock;
use App\Models\Inventory\Warehouse;
use App\Models\Tenant;
use App\Models\Tpv\TpvVendorPpeItem;
use App\Models\Tpv\TpvWorker;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TPV worker PPE end to end, and the vendor's OWN PPE list.
 *
 * Parity with Tests\Feature\Purchase\PurchaseVendorOwnPpeTest: the vendor adds
 * items to its own list (its stock, never the company's Inventory), issues them
 * to its own workers, the worker's PPE shows them beside admin-issued Inventory
 * kit, and admin can read the vendor's list from the vendor workspace.
 */
class TpvVendorOwnPpeTest extends TestCase
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

    private function user(string $role, int $tenant = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenant, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(8).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    /** @return array{0: User, 1: Vendor} */
    private function vendorWithLogin(string $name): array
    {
        $user = $this->user('third_party_vendor');

        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'email' => strtolower($name).'-'.Str::random(6).'@vendor.local',
            'status' => VendorStatus::ACTIVE, 'user_id' => $user->id,
        ]);
        $this->markOnboarded($vendor);

        return [$user, $vendor];
    }

    private function worker(Vendor $v, string $name = 'Worker'): TpvWorker
    {
        return TpvWorker::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id,
            'worker_code' => 'W-'.Str::random(6), 'name' => $name,
            'current_step' => 3, 'status' => 'Draft',
        ]);
    }

    private function inventoryPpe(float $qty = 50): Product
    {
        $wh = Warehouse::firstOrCreate(
            ['tenant_id' => self::TENANT, 'code' => 'MAIN'],
            ['name' => 'Main Store', 'type' => 'godown', 'is_default' => true, 'status' => 'active'],
        );
        $p = Product::create([
            'tenant_id' => self::TENANT, 'name' => 'Safety Helmet',
            'sku' => 'PPE-'.Str::random(6), 'base_unit' => 'pcs',
            'status' => 'active', 'without_checking_warehouse' => false,
        ]);
        Stock::create([
            'tenant_id' => self::TENANT, 'product_id' => $p->id,
            'warehouse_id' => $wh->id, 'quantity' => $qty, 'reserved_quantity' => 0,
        ]);

        return $p;
    }

    private function addOwnItem(User $login, array $over = []): int
    {
        Sanctum::actingAs($login);

        return (int) $this->postJson('/api/portal/ppe/my-items', array_merge([
            'name' => 'Own Helmet', 'category' => 'helmet', 'size' => 'M',
            'qty_in_stock' => 8,
        ], $over))->assertCreated()->json('id');
    }

    /* ── Admin-issued Inventory PPE reaches the worker on both surfaces ── */

    public function test_admin_issued_ppe_shows_on_the_portal_worker_ppe(): void
    {
        [$login, $vendor] = $this->vendorWithLogin('Alpha');
        $w = $this->worker($vendor);
        $p = $this->inventoryPpe(10);

        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/tpv/ppe/workers/{$w->id}/issue", [
            'inventory_item_id' => $p->id, 'qty' => 2,
        ])->assertCreated();

        $admin = $this->getJson("/api/tpv/ppe/workers/{$w->id}")->assertOk()->json();
        $this->assertSame('Safety Helmet', $admin[0]['item']);
        $this->assertSame('inventory', $admin[0]['source']);

        Sanctum::actingAs($login);
        $portal = $this->getJson("/api/portal/ppe/workers/{$w->id}")->assertOk()->json();
        $this->assertCount(1, $portal);
        $this->assertSame('Safety Helmet', $portal[0]['item']);
    }

    /* ── The vendor's own list ─────────────────────────────────────────── */

    public function test_vendor_creates_lists_and_edits_its_own_item(): void
    {
        [$login] = $this->vendorWithLogin('Maker');
        $id = $this->addOwnItem($login);

        $rows = $this->getJson('/api/portal/ppe/my-items')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Own Helmet', $rows[0]['name']);
        $this->assertSame('pcs', $rows[0]['unit']);
        $this->assertArrayNotHasKey('image_path', $rows[0], 'the private path is never sent');

        $this->postJson("/api/portal/ppe/my-items/{$id}", ['qty_in_stock' => 12])->assertOk();
        $this->assertEqualsWithDelta(12, (float) TpvVendorPpeItem::find($id)->qty_in_stock, 0.001);

        $this->patchJson("/api/portal/ppe/my-items/{$id}/status", ['is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false);

        $this->assertSame(0, Movement::count(), 'vendor stock never writes the company ledger');
    }

    public function test_vendor_cannot_see_edit_or_issue_another_vendors_item(): void
    {
        [$loginA, $vendorA] = $this->vendorWithLogin('Alpha');
        [$loginB] = $this->vendorWithLogin('Beta');
        $bItem = $this->addOwnItem($loginB);
        $wa = $this->worker($vendorA);

        Sanctum::actingAs($loginA);
        $this->assertSame([], $this->getJson('/api/portal/ppe/my-items')->assertOk()->json('data'));
        $this->postJson("/api/portal/ppe/my-items/{$bItem}", ['qty_in_stock' => 0])->assertNotFound();
        $this->patchJson("/api/portal/ppe/my-items/{$bItem}/status", ['is_active' => false])->assertNotFound();
        $this->getJson("/api/portal/ppe/my-items/{$bItem}/image")->assertNotFound();
        $this->postJson("/api/portal/ppe/workers/{$wa->id}/issue", [
            'vendor_ppe_item_id' => $bItem, 'qty' => 1,
        ])->assertNotFound();

        $this->assertEqualsWithDelta(8, (float) TpvVendorPpeItem::find($bItem)->qty_in_stock, 0.001);
    }

    public function test_vendor_issues_own_item_and_every_view_shows_it(): void
    {
        [$login, $vendor] = $this->vendorWithLogin('Issuer');
        $w = $this->worker($vendor);
        $id = $this->addOwnItem($login);

        $this->postJson("/api/portal/ppe/workers/{$w->id}/issue", [
            'vendor_ppe_item_id' => $id, 'qty' => 3,
        ])->assertCreated();

        $this->assertEqualsWithDelta(5, (float) TpvVendorPpeItem::find($id)->qty_in_stock, 0.001);
        $this->assertSame(0, Movement::count());
        $fresh = $w->fresh();
        $this->assertSame(1, (int) $fresh->ppe_status, 'PPE step is derived from what is held');
        $this->assertSame(4, (int) $fresh->current_step);

        // Worker PPE on the portal.
        $portal = $this->getJson("/api/portal/ppe/workers/{$w->id}")->assertOk()->json();
        $this->assertSame('Own Helmet', $portal[0]['item']);
        $this->assertSame('vendor', $portal[0]['source']);

        // Worker PPE on the admin side, and the vendor's list from the workspace.
        Sanctum::actingAs($this->user('admin'));
        $admin = $this->getJson("/api/tpv/ppe/workers/{$w->id}")->assertOk()->json();
        $this->assertSame('Own Helmet', $admin[0]['item']);

        $list = $this->getJson("/api/tpv/ppe/vendors/{$vendor->id}/items")->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertEqualsWithDelta(5, (float) $list[0]['qty_in_stock'], 0.001);
        $this->assertEqualsWithDelta(3, (float) $list[0]['issued'], 0.001);

        // A genuine return goes back on the vendor's shelf — no warehouse needed.
        Sanctum::actingAs($login);
        $issueId = $portal[0]['id'];
        $this->postJson("/api/portal/ppe/issues/{$issueId}/return", [
            'condition' => 'returned', 'qty' => 3,
        ])->assertOk();
        $this->assertEqualsWithDelta(8, (float) TpvVendorPpeItem::find($id)->qty_in_stock, 0.001);
    }

    public function test_over_issue_is_refused(): void
    {
        [$login, $vendor] = $this->vendorWithLogin('Short');
        $w = $this->worker($vendor);
        $id = $this->addOwnItem($login, ['qty_in_stock' => 1]);

        $this->postJson("/api/portal/ppe/workers/{$w->id}/issue", [
            'vendor_ppe_item_id' => $id, 'qty' => 2,
        ])->assertStatus(422);

        $this->assertEqualsWithDelta(1, (float) TpvVendorPpeItem::find($id)->qty_in_stock, 0.001);
        $this->assertDatabaseCount('tpv_worker_ppe_issues', 0);
    }

    public function test_issue_needs_exactly_one_source(): void
    {
        [$login, $vendor] = $this->vendorWithLogin('Picky');
        $w = $this->worker($vendor);
        Sanctum::actingAs($login);

        $this->postJson("/api/portal/ppe/workers/{$w->id}/issue", ['qty' => 1])
            ->assertStatus(422);
    }

    public function test_admin_of_another_tenant_cannot_read_the_list(): void
    {
        [$login, $vendor] = $this->vendorWithLogin('Local');
        $this->addOwnItem($login);

        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'Tenant 2', 'slug' => 'tenant-2', 'subdomain' => 'tenant2', 'status' => 'active',
        ])->save();

        Sanctum::actingAs($this->user('admin', 2));
        $this->getJson("/api/tpv/ppe/vendors/{$vendor->id}/items")->assertNotFound();
    }
}
