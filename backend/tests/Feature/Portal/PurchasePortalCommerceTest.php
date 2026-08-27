<?php

namespace Tests\Feature\Portal;

use App\Models\Purchase\PurchaseDebitNote;
use App\Models\Purchase\PurchaseInvoice;
use App\Models\Purchase\PurchaseInvoiceItem;
use App\Models\Purchase\PurchaseInvoicePayment;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Support\Purchase\PurchaseVendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Purchase Vendor Portal — Commercial (read-only). A vendor sees ONLY its own
 * documents; detail carries line items (+ invoice payments); the statement is a
 * running ledger of invoices (debit) vs payments/debit-notes (credit).
 */
class PurchasePortalCommerceTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function vendor(string $name = 'Bolt Supplies'): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.uniqid(), 'status' => PurchaseVendorStatus::ACTIVE,
            'portal_status' => 'active',
        ]);
    }

    private function invoice(PurchaseVendor $v, float $total, string $date): PurchaseInvoice
    {
        $inv = PurchaseInvoice::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $v->id,
            'invoice_date' => $date, 'currency' => 'INR',
            'subtotal' => $total, 'tax_total' => 0, 'total' => $total, 'balance' => $total,
            'status' => 'approved',
        ]);
        PurchaseInvoiceItem::create([
            'tenant_id' => self::TENANT, 'purchase_invoice_id' => $inv->id,
            'description' => 'Steel plate', 'qty' => 2, 'unit' => 'nos', 'rate' => $total / 2, 'tax' => 0, 'amount' => $total, 'sort_order' => 1,
        ]);

        return $inv;
    }

    public function test_vendor_lists_only_its_own_invoices(): void
    {
        $a = $this->vendor('AlphaCo');
        $b = $this->vendor('BetaCo');
        $mine = $this->invoice($a, 1000, '2026-01-10');
        $this->invoice($b, 500, '2026-01-11');

        Sanctum::actingAs($a);
        $res = $this->getJson('/api/portal/purchase/invoices')->assertOk()->json();
        $ids = collect($res)->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_invoice_detail_includes_items_and_payments(): void
    {
        $a = $this->vendor('AlphaCo');
        $inv = $this->invoice($a, 1000, '2026-01-10');
        PurchaseInvoicePayment::create([
            'tenant_id' => self::TENANT, 'purchase_invoice_id' => $inv->id,
            'amount' => 400, 'payment_date' => '2026-01-15', 'payment_mode' => 'bank', 'reference' => 'UTR-1',
        ]);

        Sanctum::actingAs($a);
        $this->getJson("/api/portal/purchase/invoices/{$inv->id}")
            ->assertOk()
            ->assertJsonPath('items.0.description', 'Steel plate')
            ->assertJsonPath('payments.0.amount', '400.00');
    }

    public function test_statement_runs_a_balance(): void
    {
        $a = $this->vendor('AlphaCo');
        $inv = $this->invoice($a, 1000, '2026-01-10');
        PurchaseInvoicePayment::create([
            'tenant_id' => self::TENANT, 'purchase_invoice_id' => $inv->id,
            'amount' => 400, 'payment_date' => '2026-01-15', 'payment_mode' => 'bank', 'reference' => 'UTR-1',
        ]);
        PurchaseDebitNote::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $a->id, 'debit_number' => 'PDN-'.uniqid(),
            'debit_date' => '2026-01-20', 'currency' => 'INR', 'subtotal' => 100, 'tax_total' => 0, 'total' => 100, 'balance' => 100, 'status' => 'issued',
        ]);

        Sanctum::actingAs($a);
        $res = $this->getJson('/api/portal/purchase/statement')->assertOk()->json();
        // 1000 debit − 400 − 100 credit = 500 closing.
        $this->assertEquals(500.0, $res['closing_balance']);
        $this->assertCount(3, $res['lines']);
    }

    public function test_another_vendors_invoice_is_unreachable(): void
    {
        $a = $this->vendor('AlphaCo');
        $b = $this->vendor('BetaCo');
        $invB = $this->invoice($b, 500, '2026-01-11');

        Sanctum::actingAs($a);
        $this->getJson("/api/portal/purchase/invoices/{$invB->id}")->assertStatus(404);
    }
}
