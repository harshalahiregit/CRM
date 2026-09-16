<?php

namespace Tests\Feature\Contract;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Naming the counterparty, and reaching them.
 *
 * Raised in review: the service used `??` where it meant `?:`, so a caller that
 * sent an id with a blank name saved a blank counterparty. Chasing that turned
 * up three more, all of which the dropdown hid because it fills the name in on
 * the client:
 *
 *  - `clients` has NO email column. The picker selected one anyway, which on
 *    MySQL is a hard SQL error (and the form swallows the failure, so all three
 *    dropdowns just come up empty) and on SQLite returns the literal string
 *    "email". A customer's address is on their contact person.
 *  - `$out += resolveParty(...)` -- union keeps the LEFT side, so a null the
 *    attribute loop had already written for party_email beat the address that
 *    had just been looked up. The fallback could never fire.
 *  - the list took no party filter, so "every contract with this customer" had
 *    to be asked of a different endpoint that composes with nothing else.
 *
 * These are all the same failure: everything answers 200 and the contract looks
 * saved, and the gap only shows up on the printed page or in an empty To field.
 */
class ContractCounterpartyTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Sangoe OS', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /** A customer, with the contact who would actually sign. */
    private function customer(string $company = 'Northwind Traders', ?string $email = 'ap@northwind.local'): Client
    {
        $c = Client::create(['tenant_id' => self::TENANT, 'company' => $company]);

        if ($email) {
            ClientContact::create([
                'tenant_id' => self::TENANT, 'client_id' => $c->id,
                'first_name' => 'Asha', 'last_name' => 'Rao',
                'email' => $email, 'is_primary' => true,
            ]);
        }

        return $c;
    }

    /* ── the blank counterparty ─────────────────────────────────── */

    public function test_a_blank_name_falls_back_to_the_real_one(): void
    {
        $c = $this->customer();
        Sanctum::actingAs($this->admin());

        // Exactly what the form posts when the name field was never touched.
        $res = $this->postJson('/api/contracts', [
            'title' => 'AMC', 'party_type' => 'customer', 'party_id' => $c->id,
            'party_name' => '', 'party_email' => '',
        ])->assertSuccessful();

        // A blank here prints as a blank line on the PDF, under "between".
        $this->assertSame('Northwind Traders', $res->json('party_name'));
    }

    public function test_a_name_the_caller_supplies_still_wins(): void
    {
        $c = $this->customer();
        Sanctum::actingAs($this->admin());

        // The snapshot is deliberate: the agreement was with the name on the
        // page, so a caller that says who it was with must be believed.
        $res = $this->postJson('/api/contracts', [
            'title' => 'AMC', 'party_type' => 'customer', 'party_id' => $c->id,
            'party_name' => 'Northwind Traders (Bangalore Division)',
        ])->assertSuccessful();

        $this->assertSame('Northwind Traders (Bangalore Division)', $res->json('party_name'));
    }

    /* ── the address the signing request goes to ────────────────── */

    public function test_a_customers_email_comes_from_their_contact(): void
    {
        $c = $this->customer();
        Sanctum::actingAs($this->admin());

        $res = $this->postJson('/api/contracts', [
            'title' => 'AMC', 'party_type' => 'customer', 'party_id' => $c->id,
            'party_name' => '', 'party_email' => '',
        ])->assertSuccessful();

        // Without this the Send-for-signature modal opens with an empty To
        // field for the commonest party type there is.
        $this->assertSame('ap@northwind.local', $res->json('party_email'));
    }

    public function test_a_vendor_still_uses_its_own_email(): void
    {
        $v = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate Industrial',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'contracts@southgate.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
        Sanctum::actingAs($this->admin());

        $res = $this->postJson('/api/contracts', [
            'title' => 'Supply', 'party_type' => 'purchase_vendor', 'party_id' => $v->id,
            'party_name' => '', 'party_email' => '',
        ])->assertSuccessful();

        $this->assertSame('Southgate Industrial', $res->json('party_name'));
        $this->assertSame('contracts@southgate.local', $res->json('party_email'));
    }

    public function test_a_customer_with_no_contact_is_not_an_error(): void
    {
        $c = $this->customer('Quiet Co', null);
        Sanctum::actingAs($this->admin());

        // Nobody to write to yet is a normal state, not a failure -- the
        // contract saves and the address is filled in before it is sent.
        $res = $this->postJson('/api/contracts', [
            'title' => 'AMC', 'party_type' => 'customer', 'party_id' => $c->id,
        ])->assertSuccessful();

        $this->assertNull($res->json('party_email'));
        $this->assertSame('Quiet Co', $res->json('party_name'));
    }

    /* ── the picker the form is built from ──────────────────────── */

    public function test_the_party_picker_offers_a_usable_email(): void
    {
        $this->customer();
        Sanctum::actingAs($this->admin());

        $body = $this->getJson('/api/contracts/parties')->assertOk()->json();

        $this->assertCount(1, $body['customer']);
        $this->assertSame('Northwind Traders', $body['customer'][0]['name']);
        $this->assertSame('ap@northwind.local', $body['customer'][0]['email'],
            'the picker cannot pre-fill an address it never fetched');

        // The old query selected a column that does not exist. SQLite answered
        // with the literal string "email" under a key of the same name, so the
        // shape looked plausible while carrying nothing.
        $this->assertArrayNotHasKey('"email"', $body['customer'][0]);
        $this->assertNotSame('email', $body['customer'][0]['email']);
    }

    /* ── the list filter ────────────────────────────────────────── */

    public function test_the_list_can_be_filtered_to_one_counterparty(): void
    {
        $a = $this->customer('Northwind Traders');
        $b = $this->customer('Southwind Ltd', 'ap@southwind.local');
        Sanctum::actingAs($this->admin());

        foreach ([[$a, 'North AMC'], [$b, 'South AMC']] as [$party, $title]) {
            $this->postJson('/api/contracts', [
                'title' => $title, 'party_type' => 'customer', 'party_id' => $party->id,
            ])->assertSuccessful();
        }

        $rows = $this->getJson("/api/contracts?party_type=customer&party_id={$a->id}")
            ->assertOk()->json();
        $rows = $rows['data'] ?? $rows;

        $this->assertCount(1, $rows);
        $this->assertSame('North AMC', $rows[0]['title']);
    }

    public function test_the_party_filter_composes_with_the_others(): void
    {
        $a = $this->customer();
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/contracts', [
            'title' => 'Draft one', 'party_type' => 'customer', 'party_id' => $a->id,
            'status' => 'draft',
        ])->assertSuccessful();
        $this->postJson('/api/contracts', [
            'title' => 'Cancelled one', 'party_type' => 'customer', 'party_id' => $a->id,
            'status' => 'cancelled',
        ])->assertSuccessful();

        // This is the whole reason to put it on the list rather than leave it
        // to /contracts/for/{type}/{id} -- it stacks with what is beside it.
        $rows = $this->getJson("/api/contracts?party_type=customer&party_id={$a->id}&status=draft")
            ->assertOk()->json();
        $rows = $rows['data'] ?? $rows;

        $this->assertCount(1, $rows);
        $this->assertSame('Draft one', $rows[0]['title']);
    }

    public function test_an_unknown_party_type_is_refused_not_ignored(): void
    {
        Sanctum::actingAs($this->admin());

        // Quietly ignoring it would answer with every contract in the tenant to
        // a caller that asked for one customer's.
        $this->getJson('/api/contracts?party_type=user&party_id=1')
            ->assertStatus(422);
    }
}
