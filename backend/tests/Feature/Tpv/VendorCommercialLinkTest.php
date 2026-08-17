<?php

namespace Tests\Feature\Tpv;

use App\Models\Purchase\PurchaseInvoice;
use App\Models\Purchase\PurchaseInvoicePayment;
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
 * The Commercial group on the TPV vendor screen.
 *
 * Commercial documents stay Purchase-owned and keyed to purchase_vendors. The TPV
 * side gains only an OPTIONAL vendors.purchase_vendor_id, set by an explicit admin
 * action, through which the existing Purchase lists are read.
 *
 * What these hold in place:
 *  - unlinked is empty, never someone else's data;
 *  - the link is validated against the tenant, so another workspace's Purchase
 *    record cannot be attached;
 *  - the derived Payments and Statement views resolve through the link only;
 *  - nothing about Purchase's own ownership changed.
 */
class VendorCommercialLinkTest extends TestCase
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

    private function user(string $role, int $tenant = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenant, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(8).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function vendor(string $name): Vendor
    {
        return Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'email' => strtolower($name).'@vendor.local',
            'status' => VendorStatus::ACTIVE,
        ]);
    }

    private function purchaseVendor(string $name, int $tenant = self::TENANT): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => $tenant, 'company_name' => $name,
            'email' => strtolower($name).'-'.Str::random(4).'@pv.local',
            'purchase_vendor_code' => 'PUR-'.Str::random(5),
            'status' => 'Active',
        ]);
    }

    /* ── The link ─────────────────────────────────────────────────────── */

    public function test_a_vendor_starts_unlinked_and_shows_nothing_commercial(): void
    {
        $vendor = $this->vendor('AlphaCo');
        Sanctum::actingAs($this->user('staff'));

        $this->getJson("/api/tpv/vendors/{$vendor->id}/purchase-matches")
            ->assertOk()
            ->assertJsonPath('linked_id', null);

        // Unlinked must be empty — never a fallback to "all vendors".
        $this->getJson("/api/tpv/vendors/{$vendor->id}/purchase-payments")->assertOk()->assertJsonCount(0);
        $this->getJson("/api/tpv/vendors/{$vendor->id}/purchase-statement")
            ->assertOk()
            ->assertJsonPath('closing_balance', 0)
            ->assertJsonCount(0, 'lines');
    }

    public function test_an_admin_can_link_and_unlink_the_purchase_record(): void
    {
        $vendor = $this->vendor('AlphaCo');
        $pv     = $this->purchaseVendor('Alpha Supplies');

        Sanctum::actingAs($this->user('staff'));

        $this->patchJson("/api/tpv/vendors/{$vendor->id}/purchase-link", ['purchase_vendor_id' => $pv->id])
            ->assertOk();
        $this->assertSame($pv->id, $vendor->fresh()->purchase_vendor_id);

        $this->patchJson("/api/tpv/vendors/{$vendor->id}/purchase-link", ['purchase_vendor_id' => null])
            ->assertOk();
        $this->assertNull($vendor->fresh()->purchase_vendor_id);
    }

    public function test_another_tenants_purchase_record_cannot_be_linked(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'Tenant 2', 'slug' => 'tenant-2',
            'subdomain' => 'tenant2', 'status' => 'active',
        ])->save();

        $vendor  = $this->vendor('AlphaCo');
        $foreign = $this->purchaseVendor('Foreign Supplies', 2);

        Sanctum::actingAs($this->user('staff'));

        $this->patchJson("/api/tpv/vendors/{$vendor->id}/purchase-link", ['purchase_vendor_id' => $foreign->id])
            ->assertStatus(404);

        $this->assertNull($vendor->fresh()->purchase_vendor_id);
    }

    public function test_the_match_helper_suggests_but_never_auto_links(): void
    {
        // Same email on both sides is the strongest hint there is — and it still
        // only produces a suggestion, because linking is a human decision.
        $vendor = $this->vendor('AlphaCo');
        $pv     = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Alpha Supplies',
            'email' => $vendor->email, 'purchase_vendor_code' => 'PUR-MATCH', 'status' => 'Active',
        ]);

        Sanctum::actingAs($this->user('staff'));

        $this->getJson("/api/tpv/vendors/{$vendor->id}/purchase-matches")
            ->assertOk()
            ->assertJsonPath('suggested_id', $pv->id)
            ->assertJsonPath('linked_id', null);

        $this->assertNull($vendor->fresh()->purchase_vendor_id);
    }

    /* ── Creating the Purchase counterpart ────────────────────────────── */

    public function test_the_purchase_record_can_be_created_from_the_vendor_and_is_linked(): void
    {
        // The common case: the company is not in Purchase at all, so there is
        // nothing to link and commercial work cannot start.
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'AlphaCo', 'legal_name' => 'Alpha Pvt Ltd',
            'email' => 'alpha@vendor.local', 'phone' => '9876543210', 'city' => 'Pune',
            'gst_number' => '27AAAAA0000A1Z5', 'status' => VendorStatus::ACTIVE,
        ]);

        Sanctum::actingAs($this->user('staff'));

        $body = $this->postJson("/api/tpv/vendors/{$vendor->id}/purchase-record")
            ->assertStatus(201)->json();

        // Created through Purchase's own service, so it carries a real code.
        $this->assertNotEmpty($body['purchase_vendor_code']);
        $this->assertSame('AlphaCo', $body['company_name']);

        // …and the TPV side now points at it, with no second step.
        $this->assertSame($body['id'], $vendor->fresh()->purchase_vendor_id);

        // Details carried across rather than being retyped.
        $pv = PurchaseVendor::find($body['id']);
        $this->assertSame('alpha@vendor.local', $pv->email);
        $this->assertSame('Pune', $pv->city);
        $this->assertSame('27AAAAA0000A1Z5', $pv->gst_number);
    }

    public function test_it_refuses_to_create_a_second_record_for_the_same_company(): void
    {
        // Duplicating a supplier is the exact mistake this is meant to avoid.
        $vendor = $this->vendor('AlphaCo');
        PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Something Else',
            'email' => $vendor->email, 'purchase_vendor_code' => 'PUR-DUP', 'status' => 'Active',
        ]);

        Sanctum::actingAs($this->user('staff'));

        $this->postJson("/api/tpv/vendors/{$vendor->id}/purchase-record")->assertStatus(422);
        $this->assertNull($vendor->fresh()->purchase_vendor_id);
        $this->assertSame(1, PurchaseVendor::count());
    }

    public function test_an_already_linked_vendor_cannot_create_another_record(): void
    {
        $vendor = $this->vendor('AlphaCo');
        $vendor->update(['purchase_vendor_id' => $this->purchaseVendor('Alpha Supplies')->id]);

        Sanctum::actingAs($this->user('staff'));

        $this->postJson("/api/tpv/vendors/{$vendor->id}/purchase-record")->assertStatus(422);
        $this->assertSame(1, PurchaseVendor::count());
    }

    public function test_a_vendor_login_cannot_create_a_purchase_record(): void
    {
        $user   = $this->user('third_party_vendor');
        $vendor = $this->vendor('AlphaCo');
        $vendor->update(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $this->postJson("/api/tpv/vendors/{$vendor->id}/purchase-record")->assertStatus(403);
        $this->assertSame(0, PurchaseVendor::count());
    }

    /* ── RFQs for one vendor ──────────────────────────────────────────── */

    public function test_the_rfq_list_can_be_narrowed_to_one_vendors_invitations(): void
    {
        $mine  = $this->purchaseVendor('Alpha Supplies');
        $other = $this->purchaseVendor('Beta Supplies');

        Sanctum::actingAs($this->user('staff'));

        $mineRfq = $this->postJson('/api/purchase/rfqs', [
            'title' => 'Site helmets', 'items' => [['description' => 'Helmet', 'qty' => 10]],
            'vendor_ids' => [$mine->id],
        ])->assertStatus(201)->json('id');

        $this->postJson('/api/purchase/rfqs', [
            'title' => 'Office chairs', 'items' => [['description' => 'Chair', 'qty' => 4]],
            'vendor_ids' => [$other->id],
        ])->assertStatus(201);

        // Unfiltered stays tenant-wide, exactly as before.
        $this->getJson('/api/purchase/rfqs')->assertOk()->assertJsonCount(2);

        // Filtered returns only the RFQs this vendor was invited to.
        $rows = $this->getJson("/api/purchase/rfqs?purchase_vendor_id={$mine->id}")->assertOk()->json();
        $this->assertCount(1, $rows);
        $this->assertSame($mineRfq, $rows[0]['id']);
    }

    /* ── Reading through the link ─────────────────────────────────────── */

    public function test_payments_and_statement_resolve_through_the_link(): void
    {
        $vendor = $this->vendor('AlphaCo');
        $pv     = $this->purchaseVendor('Alpha Supplies');
        $vendor->update(['purchase_vendor_id' => $pv->id]);

        $invoice = PurchaseInvoice::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $pv->id,
            'invoice_number' => 'INV-1', 'invoice_date' => now()->subDays(5)->toDateString(),
            'total' => 1000, 'status' => 'Approved',
        ]);
        PurchaseInvoicePayment::create([
            'tenant_id' => self::TENANT, 'purchase_invoice_id' => $invoice->id,
            'amount' => 400, 'payment_date' => now()->subDay()->toDateString(),
            'payment_mode' => 'bank_transfer', 'reference' => 'NEFT-9',
        ]);

        Sanctum::actingAs($this->user('staff'));

        $this->getJson("/api/tpv/vendors/{$vendor->id}/purchase-payments")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.reference', 'NEFT-9');

        // 1000 invoiced − 400 paid = 600 outstanding.
        $this->getJson("/api/tpv/vendors/{$vendor->id}/purchase-statement")
            ->assertOk()
            ->assertJsonCount(2, 'lines')
            ->assertJsonPath('closing_balance', 600);
    }

    public function test_one_vendors_link_never_leaks_another_vendors_documents(): void
    {
        $alpha = $this->vendor('AlphaCo');
        $beta  = $this->vendor('BetaCo');

        $pvAlpha = $this->purchaseVendor('Alpha Supplies');
        $pvBeta  = $this->purchaseVendor('Beta Supplies');

        $alpha->update(['purchase_vendor_id' => $pvAlpha->id]);
        $beta->update(['purchase_vendor_id' => $pvBeta->id]);

        $inv = PurchaseInvoice::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $pvAlpha->id,
            'invoice_number' => 'INV-A', 'invoice_date' => now()->toDateString(),
            'grand_total' => 500, 'status' => 'Approved',
        ]);
        PurchaseInvoicePayment::create([
            'tenant_id' => self::TENANT, 'purchase_invoice_id' => $inv->id,
            'amount' => 500, 'payment_date' => now()->toDateString(), 'payment_mode' => 'cash',
        ]);

        Sanctum::actingAs($this->user('staff'));

        $this->getJson("/api/tpv/vendors/{$alpha->id}/purchase-payments")->assertOk()->assertJsonCount(1);
        // Beta is linked too, but to a different Purchase record.
        $this->getJson("/api/tpv/vendors/{$beta->id}/purchase-payments")->assertOk()->assertJsonCount(0);
        $this->getJson("/api/tpv/vendors/{$beta->id}/purchase-statement")
            ->assertOk()->assertJsonPath('closing_balance', 0);
    }

    public function test_a_vendor_login_cannot_reach_the_commercial_endpoints(): void
    {
        $user   = $this->user('third_party_vendor');
        $vendor = $this->vendor('AlphaCo');
        $vendor->update(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        foreach ([
            "/api/tpv/vendors/{$vendor->id}/purchase-matches",
            "/api/tpv/vendors/{$vendor->id}/purchase-payments",
            "/api/tpv/vendors/{$vendor->id}/purchase-statement",
        ] as $url) {
            $this->getJson($url)->assertStatus(403, "{$url} must refuse a vendor login");
        }

        $this->patchJson("/api/tpv/vendors/{$vendor->id}/purchase-link", ['purchase_vendor_id' => 1])
            ->assertStatus(403);
    }
}
