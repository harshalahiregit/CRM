<?php

namespace Tests\Feature\Purchase;

use App\Models\Inventory\Category;
use App\Models\Inventory\Product;
use App\Models\Inventory\Warehouse;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issuing PPE from the admin side, over HTTP.
 *
 * SIR-000013 — "PPE list is not showing to select". The worker's PPE tab could
 * only ever list kit that had been issued somewhere else, because nothing in the
 * UI called the catalogue or the issue route.
 *
 * Wiring the screen exposed why: the endpoint had never worked. It validates
 * `product_id` (what ppeCatalogue returns) and handed the array straight to
 * PurchasePpeService::issue, which reads `inventory_item_id` — so the product
 * resolved to null and every call answered 404. `issued_at` versus `issued_date`
 * was the same mistake, one field along.
 *
 * The service had good coverage and the route had none, which is exactly how a
 * translation layer between two tested halves goes unnoticed. These tests go
 * over HTTP for that reason.
 */
class AdminCanIssuePpeTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Sangoe OS', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->stock = app(StockService::class);
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function worker(): PurchaseWorker
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sg-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
        $this->markOnboarded($vendor);

        return PurchaseWorker::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id,
            'full_name' => 'Ravi Kumar', 'worker_code' => 'PW-'.Str::random(4),
            'gender' => 'Male', 'status' => 'Pending', 'current_step' => 1,
        ]);
    }

    /**
     * A product only reaches the catalogue through a category literally named
     * "PPE" — see PpeInventoryService::ppeProductIds.
     */
    private function stockedPpe(string $name = 'Safety Helmet', float $qty = 100): Product
    {
        $cat = Category::create(['tenant_id' => self::TENANT, 'name' => 'PPE']);
        $wh = Warehouse::firstOrCreate(
            ['tenant_id' => self::TENANT, 'code' => 'WH1'],
            ['name' => 'Main', 'is_default' => true],
        );

        $p = Product::create([
            'tenant_id' => self::TENANT, 'name' => $name, 'category_id' => $cat->id,
            'sku' => 'PPE-'.Str::random(5), 'status' => 'Active',
        ]);

        $this->stock->adjustTo($p->id, $wh->id, $qty, self::TENANT, null, 'baseline');

        return $p;
    }

    private function available(Product $p): float
    {
        return (float) ($this->stock->totalsFor($p->id, self::TENANT)['available'] ?? 0);
    }

    /* ── the list the screen selects from ────────────────────────────── */

    public function test_the_catalogue_lists_ppe_with_live_availability(): void
    {
        $p = $this->stockedPpe('Safety Helmet', 40);

        Sanctum::actingAs($this->admin());
        $rows = $this->getJson('/api/purchase/workforce/ppe/catalogue')->assertOk()->json('data');

        $this->assertNotEmpty($rows, 'nothing to select from is the reported symptom');

        $row = collect($rows)->firstWhere('product_id', $p->id);
        $this->assertNotNull($row, 'the stocked PPE item must appear in the catalogue');
        $this->assertSame('Safety Helmet', $row['name']);
        // The picker disables a row on this number, so it has to be real.
        $this->assertSame(40.0, (float) $row['available']);
    }

    /* ── issuing it ──────────────────────────────────────────────────── */

    public function test_admin_can_issue_a_catalogue_item_to_a_worker(): void
    {
        $p = $this->stockedPpe('Safety Helmet', 10);
        $w = $this->worker();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/purchase/workforce/workers/{$w->id}/ppe/issue", [
            'product_id' => $p->id,
            'qty' => 2,
            'size' => 'L',
        ])->assertCreated();

        $this->assertDatabaseHas('purchase_worker_ppe_issues', [
            'purchase_worker_id' => $w->id,
            'inventory_item_id' => $p->id,
            'qty' => 2,
            'size' => 'L',
            'status' => 'issued',
        ]);

        // Purchase keeps no stock of its own — an issue is an inventory movement.
        $this->assertSame(8.0, $this->available($p), 'issuing must draw down central stock');
    }

    /** The field that was being dropped one along from the one that 404'd. */
    public function test_the_issue_date_sent_by_the_form_is_stored(): void
    {
        $p = $this->stockedPpe();
        $w = $this->worker();
        $when = now()->subDays(3)->toDateString();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/purchase/workforce/workers/{$w->id}/ppe/issue", [
            'product_id' => $p->id, 'qty' => 1, 'issued_at' => $when,
        ])->assertCreated();

        // Compared as a date: the column is a datetime, so the stored value is
        // '<date> 00:00:00' and a raw string match would fail on the time.
        $issue = \App\Models\Purchase\PurchaseWorkerPpeIssue::where('purchase_worker_id', $w->id)->firstOrFail();
        $this->assertSame($when, $issue->issued_date->toDateString(),
            'the date the operator chose must survive, not be replaced by today');
    }

    /** Issued kit has to come back on the tab that issued it. */
    public function test_what_was_issued_is_listed_against_the_worker(): void
    {
        $p = $this->stockedPpe('Harness');
        $w = $this->worker();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/purchase/workforce/workers/{$w->id}/ppe/issue", [
            'product_id' => $p->id, 'qty' => 1,
        ])->assertCreated();

        $issues = $this->getJson("/api/purchase/workforce/workers/{$w->id}/ppe")->assertOk()->json('issues');

        $this->assertCount(1, $issues);
        $this->assertSame('Harness', $issues[0]['item']);
    }

    /* ── and the refusals the form promises ──────────────────────────── */

    public function test_more_than_is_on_the_shelf_is_refused_and_moves_nothing(): void
    {
        $p = $this->stockedPpe('Gloves', 1);
        $w = $this->worker();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/purchase/workforce/workers/{$w->id}/ppe/issue", [
            'product_id' => $p->id, 'qty' => 5,
        ])->assertStatus(422);

        $this->assertSame(1.0, $this->available($p), 'a refused issue must leave stock alone');
        $this->assertDatabaseCount('purchase_worker_ppe_issues', 0);
    }

    public function test_an_item_that_is_not_in_inventory_is_refused(): void
    {
        $w = $this->worker();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/purchase/workforce/workers/{$w->id}/ppe/issue", [
            'product_id' => 999999, 'qty' => 1,
        ])->assertStatus(404);
    }

    public function test_another_tenants_worker_cannot_be_issued_to(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'Other', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();

        $p = $this->stockedPpe();
        $w = $this->worker();

        $intruder = User::create([
            'tenant_id' => 2, 'name' => 'Other Admin', 'role' => 'admin',
            'email' => 'o-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);

        Sanctum::actingAs($intruder);
        $this->postJson("/api/purchase/workforce/workers/{$w->id}/ppe/issue", [
            'product_id' => $p->id, 'qty' => 1,
        ])->assertStatus(404);

        $this->assertDatabaseCount('purchase_worker_ppe_issues', 0);
    }
}
