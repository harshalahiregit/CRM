<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\GoodsReceipt;
use App\Models\Purchase\PurchaseOrder;
use App\Models\Purchase\PurchaseOrderItem;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Purchase\GoodsReceiptStatus;
use App\Support\Purchase\PurchaseOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Goods Received register — GET /api/purchase/receipts.
 *
 * Receipts are RAISED from inside a purchase order and always were; what did
 * not exist was a way to look one up when you know the GRN number or the
 * delivery note but not which PO it came from. The sidebar advertised that
 * screen and opened a construction stub instead.
 *
 * The cases that matter for a register: it crosses purchase orders, it is
 * scoped to the tenant, its filters actually filter, and its headline figures
 * count confirmed stock only — a draft GRN has moved nothing.
 */
class GoodsReceiptRegisterTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private const OTHER  = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT, self::OTHER] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => "Tenant $id", 'slug' => "tenant-$id",
                'subdomain' => "tenant$id", 'status' => 'active',
            ])->save();
        }
    }

    private function user(int $tenant = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenant, 'name' => 'Stores', 'role' => 'admin',
            'email' => 'u-'.Str::random(6).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function vendor(int $tenant = self::TENANT, string $name = 'AppCo'): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => $tenant, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => strtolower(Str::random(5)).'@test.local',
            'status' => 'Draft', 'portal_status' => 'active',
        ]);
    }

    private function order(PurchaseVendor $vendor, int $tenant = self::TENANT): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'tenant_id' => $tenant, 'purchase_vendor_id' => $vendor->id,
            'title' => 'Cement', 'order_date' => now()->toDateString(),
            'status' => PurchaseOrderStatus::ISSUED, 'currency' => 'INR',
            'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
        ]);

        // goods_receipt_items.purchase_order_item_id is NOT NULL — a receipt
        // line always points at the ordered line it fulfils.
        PurchaseOrderItem::create([
            'tenant_id' => $tenant, 'purchase_order_id' => $po->id,
            'description' => 'Cement bags', 'qty' => 10, 'received_qty' => 0,
            'unit' => 'bag', 'rate' => 100, 'tax' => 0, 'amount' => 1000,
            'sort_order' => 0,
        ]);

        return $po->fresh('items');
    }

    /** A receipt with one line, created directly — the register is read-only. */
    private function receipt(
        PurchaseOrder $po,
        string $status = GoodsReceiptStatus::CONFIRMED,
        float $accepted = 10,
        float $rejected = 0,
        ?string $noteRef = null,
        ?string $date = null,
    ): GoodsReceipt {
        $grn = GoodsReceipt::create([
            'tenant_id'         => $po->tenant_id,
            'purchase_order_id' => $po->id,
            'purchase_vendor_id' => $po->purchase_vendor_id,
            'received_date'     => $date ?? now()->toDateString(),
            'delivery_note_ref' => $noteRef,
            'status'            => $status,
        ]);

        $grn->items()->create([
            'tenant_id' => $po->tenant_id,
            'purchase_order_item_id' => $po->items->first()->id,
            'description' => 'Cement bags',
            'ordered_qty' => 10, 'accepted_qty' => $accepted, 'rejected_qty' => $rejected,
        ]);

        return $grn;
    }

    public function test_the_register_returns_receipts_across_every_purchase_order(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $vendorA = $this->vendor(name: 'Alpha');
        $vendorB = $this->vendor(name: 'Beta');
        $this->receipt($this->order($vendorA));
        $this->receipt($this->order($vendorB));

        $res = $this->getJson('/api/purchase/receipts')->assertOk();

        $this->assertCount(2, $res->json(), 'the register must span purchase orders, not one of them');

        // The list is what a clerk reads, so the vendor and PO must be resolved
        // server-side rather than left as bare ids for the UI to look up.
        $this->assertNotNull($res->json('0.vendor.company_name'));
        $this->assertNotNull($res->json('0.purchase_order.po_number'));
    }

    public function test_the_register_never_shows_another_tenants_receipts(): void
    {
        Sanctum::actingAs($this->user(self::TENANT));

        $this->receipt($this->order($this->vendor(self::TENANT)));
        $this->receipt($this->order($this->vendor(self::OTHER), self::OTHER), );

        $res = $this->getJson('/api/purchase/receipts')->assertOk();

        $this->assertCount(1, $res->json(), 'a second tenant\'s receipts leaked into the register');
    }

    public function test_status_and_search_filters_actually_filter(): void
    {
        Sanctum::actingAs($this->user());
        $po = $this->order($this->vendor());

        $confirmed = $this->receipt($po, GoodsReceiptStatus::CONFIRMED, noteRef: 'DN-9001');
        $this->receipt($po, GoodsReceiptStatus::DRAFT, noteRef: 'DN-9002');

        $byStatus = $this->getJson('/api/purchase/receipts?status=Confirmed')->assertOk()->json();
        $this->assertCount(1, $byStatus);
        $this->assertSame($confirmed->id, $byStatus[0]['id']);

        // Searching the delivery note is the whole reason the register exists.
        $byNote = $this->getJson('/api/purchase/receipts?search=DN-9002')->assertOk()->json();
        $this->assertCount(1, $byNote);
        $this->assertSame('DN-9002', $byNote[0]['delivery_note_ref']);

        $byGrn = $this->getJson('/api/purchase/receipts?search='.$confirmed->grn_number)->assertOk()->json();
        $this->assertCount(1, $byGrn);
    }

    public function test_the_rejections_filter_returns_only_receipts_with_a_rejected_line(): void
    {
        Sanctum::actingAs($this->user());
        $po = $this->order($this->vendor());

        $this->receipt($po, accepted: 10, rejected: 0);
        $withRejects = $this->receipt($po, accepted: 7, rejected: 3);

        $res = $this->getJson('/api/purchase/receipts?has_rejections=1')->assertOk()->json();

        $this->assertCount(1, $res);
        $this->assertSame($withRejects->id, $res[0]['id']);
    }

    public function test_the_date_range_is_inclusive_at_both_ends(): void
    {
        Sanctum::actingAs($this->user());
        $po = $this->order($this->vendor());

        $this->receipt($po, date: '2026-03-01');
        $this->receipt($po, date: '2026-03-05');
        $this->receipt($po, date: '2026-03-09');

        $res = $this->getJson('/api/purchase/receipts?from=2026-03-01&to=2026-03-05')->assertOk()->json();

        $this->assertCount(2, $res, 'asking for the 1st to the 5th must include both the 1st and the 5th');
    }

    public function test_stats_count_confirmed_quantities_only(): void
    {
        Sanctum::actingAs($this->user());
        $po = $this->order($this->vendor());

        $this->receipt($po, GoodsReceiptStatus::CONFIRMED, accepted: 10, rejected: 2);
        $this->receipt($po, GoodsReceiptStatus::DRAFT,     accepted: 99, rejected: 99);
        $this->receipt($po, GoodsReceiptStatus::CANCELLED, accepted: 50, rejected: 50);

        $stats = $this->getJson('/api/purchase/receipts/stats')->assertOk()->json();

        $this->assertSame(3, $stats['total']);
        $this->assertSame(1, $stats['draft']);
        $this->assertSame(1, $stats['confirmed']);
        $this->assertSame(1, $stats['cancelled']);

        // A draft GRN has moved no stock and a cancelled one moved it back, so
        // neither may contribute — otherwise the register overstates the shelf.
        $this->assertEqualsWithDelta(10.0, $stats['accepted_qty'], 0.001);
        $this->assertEqualsWithDelta(2.0,  $stats['rejected_qty'], 0.001);
        $this->assertSame(1, $stats['with_rejections']);
    }

    public function test_stats_never_count_another_tenants_lines(): void
    {
        Sanctum::actingAs($this->user(self::TENANT));

        $this->receipt($this->order($this->vendor(self::TENANT)), accepted: 4);
        $this->receipt($this->order($this->vendor(self::OTHER), self::OTHER), accepted: 1000);

        $stats = $this->getJson('/api/purchase/receipts/stats')->assertOk()->json();

        $this->assertSame(1, $stats['total']);
        $this->assertEqualsWithDelta(4.0, $stats['accepted_qty'], 0.001,
            'the raw join in stats() bypasses the Eloquent tenant scope — it must filter tenant itself');
    }

    public function test_a_soft_deleted_receipt_leaves_both_the_list_and_the_totals(): void
    {
        Sanctum::actingAs($this->user());
        $po = $this->order($this->vendor());

        $this->receipt($po, accepted: 5);
        $this->receipt($po, accepted: 7)->delete();

        $this->assertCount(1, $this->getJson('/api/purchase/receipts')->assertOk()->json());

        $stats = $this->getJson('/api/purchase/receipts/stats')->assertOk()->json();
        $this->assertSame(1, $stats['total']);
        $this->assertEqualsWithDelta(5.0, $stats['accepted_qty'], 0.001,
            'the raw join must exclude soft-deleted receipts — Eloquent is not doing it here');
    }

    public function test_the_stats_route_is_not_swallowed_by_the_receipt_wildcard(): void
    {
        Sanctum::actingAs($this->user());

        // /receipts/{goodsReceipt} would match "stats" and 404 on the binding.
        $this->getJson('/api/purchase/receipts/stats')->assertOk()->assertJsonStructure(['total']);
    }

    public function test_the_register_requires_authentication(): void
    {
        $this->getJson('/api/purchase/receipts')->assertUnauthorized();
        $this->getJson('/api/purchase/receipts/stats')->assertUnauthorized();
    }
}
