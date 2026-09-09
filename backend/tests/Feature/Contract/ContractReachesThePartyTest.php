<?php

namespace Tests\Feature\Contract;

use App\Models\Contract\Contract;
use App\Models\Customer\Client;
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
 * A contract linked to somebody has to reach that somebody.
 *
 * Before this, the module knew who each agreement was with and showed it only to
 * staff. The other party could reach their own contract solely through the
 * signing link they were e-mailed — so anybody who lost that e-mail lost the
 * document, and there was nowhere in their portal to find it again.
 *
 * ── The path is `agreements`, and that is not cosmetic ──────────────────
 * The client and purchase portals already serve /contracts from their own older
 * tables (client_contracts, purchase_contracts — the latter has live rows).
 * Laravel matches the first registration, so mounting these at /contracts would
 * have been silently shadowed: the routes would exist, return 200, and answer
 * with the OLD module's list. The test below asserts the new data actually comes
 * back, which is what catches that.
 */
class ContractReachesThePartyTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function staff(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /** A contract for a given party, already sent (not a draft). */
    private function contractFor(string $type, int $id, string $name, string $title = 'Supply Agreement'): Contract
    {
        return Contract::create([
            'tenant_id' => self::TENANT, 'title' => $title,
            'party_type' => $type, 'party_id' => $id, 'party_name' => $name,
            'value' => 100000, 'currency' => 'INR', 'status' => 'sent',
        ]);
    }

    /* ── The TPV vendor's portal ────────────────────────────────────── */

    private function tpvVendor(string $status = VendorStatus::ACTIVE): array
    {
        $login = User::create([
            'tenant_id' => self::TENANT, 'name' => 'AlphaCo', 'role' => 'third_party_vendor',
            'email' => 'alpha-'.Str::random(6).'@login.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'AlphaCo',
            'email' => 'alpha-'.Str::random(6).'@vendor.local',
            'status' => $status, 'user_id' => $login->id,
        ]);

        return [$login, $vendor];
    }

    public function test_a_tpv_vendor_sees_the_contract_linked_to_them(): void
    {
        [$login, $vendor] = $this->tpvVendor();
        $this->contractFor(Vendor::class, $vendor->id, 'AlphaCo', 'Labour Supply Agreement');

        Sanctum::actingAs($login);
        $rows = $this->getJson('/api/portal/agreements')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Labour Supply Agreement', $rows[0]['title']);
        // The reference is what they quote back on the phone.
        $this->assertNotNull($rows[0]['reference_no']);
    }

    public function test_a_vendor_cannot_see_another_vendors_contract(): void
    {
        [$loginA] = $this->tpvVendor();
        [, $vendorB] = $this->tpvVendor();
        $b = $this->contractFor(Vendor::class, $vendorB->id, 'BetaCo');

        Sanctum::actingAs($loginA);

        $this->assertCount(0, $this->getJson('/api/portal/agreements')->assertOk()->json('data'));
        // And they cannot reach it by naming it either. There is no detail route
        // to try — the only endpoint that takes an id is the comment POST, and
        // it checks the id against the caller's own party.
        $this->postJson('/api/portal/agreements/comment', [
            'contract_id' => $b->id, 'body' => 'Let me in',
        ])->assertStatus(404);
    }

    public function test_a_draft_is_not_shown_to_the_other_side(): void
    {
        [$login, $vendor] = $this->tpvVendor();
        $draft = $this->contractFor(Vendor::class, $vendor->id, 'AlphaCo');
        $draft->update(['status' => 'draft']);

        Sanctum::actingAs($login);

        // A draft is ours until we send it. Showing a half-written agreement —
        // or one we decided not to send — is worse than showing nothing.
        $this->assertCount(0, $this->getJson('/api/portal/agreements')->assertOk()->json('data'));
        $this->postJson('/api/portal/agreements/comment', [
            'contract_id' => $draft->id, 'body' => 'About this draft',
        ])->assertStatus(404);
    }

    public function test_a_vendor_awaiting_onboarding_approval_can_still_read_their_contract(): void
    {
        [$login, $vendor] = $this->tpvVendor(VendorStatus::PENDING_APPROVAL);
        $this->contractFor(Vendor::class, $vendor->id, 'AlphaCo');

        Sanctum::actingAs($login);

        // Reading a contract they have been sent is exactly what an unapproved
        // vendor needs to do — often it is what they are being approved FOR.
        // The onboarding gate blocks writes, not reads.
        $this->assertCount(1, $this->getJson('/api/portal/agreements')->assertOk()->json('data'));
    }

    public function test_the_vendor_sees_the_terms_and_both_signature_slots(): void
    {
        [$login, $vendor] = $this->tpvVendor();
        $c = $this->contractFor(Vendor::class, $vendor->id, 'AlphaCo');
        $c->pages()->create([
            'tenant_id' => self::TENANT, 'sort_order' => 0,
            'title' => 'Scope', 'content' => 'Quarterly inspection.',
        ]);

        Sanctum::actingAs($login);
        $row = $this->getJson('/api/portal/agreements')->assertOk()->json('data.0');

        // The LIST carries the terms — there is no detail route to fetch them
        // from, because the client portal forbids ids in its URLs and all three
        // portals share this shape.
        $this->assertSame('Supply Agreement', $row['title']);
        $this->assertSame('Scope', $row['pages'][0]['title']);
        $this->assertTrue($row['awaiting_me']);
        // Their own copy and signing page, through the token e-mailed to them.
        $this->assertStringContainsString('/pdf', $row['pdf_url']);
    }

    /* ── The customer's portal ──────────────────────────────────────── */

    public function test_a_customer_sees_their_contract_and_not_the_legacy_empty_list(): void
    {
        $client = Client::create([
            'tenant_id' => self::TENANT, 'company' => 'Northwind Traders',
            'email' => 'ap-'.Str::random(4).'@northwind.local',
        ]);
        $contact = \App\Models\Customer\ClientContact::create([
            'tenant_id' => self::TENANT, 'client_id' => $client->id,
            'first_name' => 'Sunita', 'last_name' => 'Rao',
            'email' => 'sunita-'.Str::random(4).'@northwind.local',
            // active AND portal_status are separate gates in
            // EnsureClientPortalAccess — being a contact is not being a portal user.
            'active' => true, 'portal_status' => 'active',
            'permissions' => ['contract'],
        ]);
        $this->contractFor(Client::class, $client->id, 'Northwind Traders', 'Annual Maintenance');

        // The client portal authenticates as the ClientContact itself, not as a
        // User — the same multi-identity pattern PurchaseVendor uses.
        Sanctum::actingAs($contact);
        $rows = $this->getJson('/api/portal/client/agreements')->assertOk()->json('data');

        // If this route had been mounted at /contracts it would have been
        // shadowed by the legacy client_contracts endpoint and returned [].
        $this->assertCount(1, $rows);
        $this->assertSame('Annual Maintenance', $rows[0]['title']);
    }

    /* ── The purchase vendor's portal ───────────────────────────────── */

    public function test_a_purchase_vendor_sees_their_contract(): void
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sg-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
        $this->contractFor(PurchaseVendor::class, $vendor->id, 'Southgate', 'Rate Contract');

        Sanctum::actingAs($vendor);
        $rows = $this->getJson('/api/portal/purchase/agreements')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Rate Contract', $rows[0]['title']);
    }


    public function test_no_portal_agreements_route_takes_an_id_in_the_url(): void
    {
        // The client portal's whole model is that no route accepts an identifier
        // from the caller — ClientPortalTest asserts it for the whole prefix, and
        // the first version of these routes broke it. Pinned here too so a future
        // detail route is caught in THIS module rather than in somebody else's
        // test.
        $offenders = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'agreements'))
            ->filter(fn ($r) => str_contains($r->uri(), '{'))
            ->map(fn ($r) => $r->uri())->values()->all();

        $this->assertSame([], $offenders,
            'a portal agreements route must not take an id from the caller');
    }

    /* ── The admin side ─────────────────────────────────────────────── */

    public function test_the_admin_sees_a_partys_contracts_on_their_record(): void
    {
        $client = Client::create([
            'tenant_id' => self::TENANT, 'company' => 'Northwind Traders',
            'email' => 'ap-'.Str::random(4).'@northwind.local',
        ]);
        $this->contractFor(Client::class, $client->id, 'Northwind Traders', 'Annual Maintenance');
        $draft = $this->contractFor(Client::class, $client->id, 'Northwind Traders', 'Still drafting');
        $draft->update(['status' => 'draft']);

        Sanctum::actingAs($this->staff());
        $rows = $this->getJson("/api/contracts/for/customer/{$client->id}")->assertOk()->json('data');

        // Drafts DO appear here: this is the internal side, and somebody looking
        // at a customer's record should see the agreement being drafted for them.
        $this->assertCount(2, $rows);
    }

    public function test_the_admin_party_lookup_refuses_an_unknown_type(): void
    {
        Sanctum::actingAs($this->staff());

        // The type is a stable key, never a class name — one that could name the
        // class could enumerate any model in the application.
        $this->getJson('/api/contracts/for/user/1')->assertStatus(404);
    }
}
