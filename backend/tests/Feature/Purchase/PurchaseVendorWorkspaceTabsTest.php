<?php

namespace Tests\Feature\Purchase;

use App\Models\Customer\Client;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Purchase vendor workspace tabs that TPV has and Purchase does not.
 *
 * Reported from the running system: on a Purchase vendor, Add Contact, Add
 * Customer and Workforce misbehave — and the same three work on a TPV vendor.
 *
 * The Customer tab is the clearest case. Both modules render the SAME component
 * (VendorCustomersPanel, which lives in the TPV module) and hand it their own
 * api client. That component calls four methods; the TPV client defines all
 * four and the Purchase client defines two. Calling a method that is not there
 * throws a TypeError inside the promise chain, so the panel never leaves
 * "Searching…" — no error toast, nothing in the network tab, just a spinner
 * that runs for ever.
 *
 * These tests are written against the PURCHASE endpoints on purpose: a passing
 * TPV suite is exactly what let this drift.
 */
class PurchaseVendorWorkspaceTabsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private PurchaseVendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Sangoe OS', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'nexfore',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'nex-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /* ── Tab: Contacts ──────────────────────────────────────────────── */

    public function test_a_contact_can_be_added_listed_and_removed(): void
    {
        Sanctum::actingAs($this->admin());
        $v = $this->vendor->id;

        $created = $this->postJson("/api/purchase/vendors/{$v}/contacts", [
            'first_name' => 'Zaid', 'last_name' => 'Farooque',
            'email' => 'zaid@nexfore.local', 'phone' => '+91 98200 11111',
            'designation' => 'Accounts Head',
        ]);

        if ($created->getStatusCode() >= 400) {
            $this->fail('Add Contact refused: '.$created->getStatusCode().' — '
                .json_encode($created->json('errors') ?? $created->json('message')));
        }

        // The header counts "Contacts · 0" from this list, so a create that does
        // not show up here reads as a save that silently failed.
        $rows = $this->getJson("/api/purchase/vendors/{$v}/contacts")->assertOk()->json();
        $rows = $rows['data'] ?? $rows;
        $this->assertCount(1, $rows);
        $this->assertSame('zaid@nexfore.local', $rows[0]['email'] ?? null);

        $id = $created->json('id') ?? $rows[0]['id'];
        $this->deleteJson("/api/purchase/vendors/{$v}/contacts/{$id}")->assertSuccessful();
    }

    /* ── Tab: Customer ──────────────────────────────────────────────── */

    public function test_the_customer_search_endpoint_exists(): void
    {
        Sanctum::actingAs($this->admin());

        Client::create([
            'tenant_id' => self::TENANT, 'company' => 'Northwind Traders',
            'email' => 'ap@northwind.local',
        ]);

        // Without this the panel calls an undefined client method and hangs on
        // "Searching…" for ever.
        $res = $this->getJson("/api/purchase/vendors/{$this->vendor->id}/customers/search?q=North")
            ->assertOk();

        $rows = $res->json();
        $rows = $rows['data'] ?? $rows;

        $this->assertNotEmpty($rows, 'searching for an existing customer returns nothing');
        $this->assertSame('Northwind Traders', $rows[0]['company'] ?? $rows[0]['name'] ?? null);
    }

    public function test_an_existing_customer_can_be_linked_to_the_vendor(): void
    {
        Sanctum::actingAs($this->admin());

        $client = Client::create([
            'tenant_id' => self::TENANT, 'company' => 'Northwind Traders',
            'email' => 'ap@northwind.local',
        ]);

        $this->postJson("/api/purchase/vendors/{$this->vendor->id}/customers/link", [
            'client_id' => $client->id,
        ])->assertSuccessful();

        $listed = $this->getJson("/api/purchase/vendors/{$this->vendor->id}/customers")->assertOk()->json();
        $listed = $listed['data'] ?? $listed;

        $this->assertCount(1, $listed, 'the linked customer does not appear on the vendor');
    }

    public function test_a_new_customer_can_be_created_from_the_vendor(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/purchase/vendors/{$this->vendor->id}/customers", [
            'company' => 'Southwind Ltd', 'email' => 'ap@southwind.local',
        ])->assertSuccessful();

        $listed = $this->getJson("/api/purchase/vendors/{$this->vendor->id}/customers")->assertOk()->json();
        $this->assertCount(1, $listed['data'] ?? $listed);
    }

    /* ── Tab: Workforce ─────────────────────────────────────────────── */

    public function test_the_workforce_tab_lists_only_this_vendors_workers(): void
    {
        Sanctum::actingAs($this->admin());

        $other = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sg-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);

        foreach ([[$this->vendor, 'Ravi Kumar'], [$other, 'Someone Else']] as [$v, $name]) {
            $this->postJson('/api/purchase/workforce/workers', [
                'purchase_vendor_id' => $v->id, 'full_name' => $name,
                'dob' => now()->subYears(30)->toDateString(), 'gender' => 'Male',
            ])->assertSuccessful();
        }

        // The tab passes ?vendor_id=. If the filter is ignored the tab shows
        // every worker in the tenant; if it is misnamed it shows none, which is
        // what "no workers registered yet" looks like on a vendor that has one.
        $rows = $this->getJson("/api/purchase/workforce/workers?vendor_id={$this->vendor->id}")
            ->assertOk()->json();
        $rows = $rows['data'] ?? $rows;

        $this->assertCount(1, $rows, 'the vendor_id filter on the Workforce tab is not applied');
        $this->assertSame('Ravi Kumar', $rows[0]['full_name'] ?? null);
    }
}
