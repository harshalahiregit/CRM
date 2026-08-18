<?php

namespace Tests\Feature\Sales;

use App\Models\Customer\Client;
use App\Models\Sales\SalesInvoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP-layer coverage for the invoice money path.
 *
 * Sales had no tests at all, and the gaps that surfaced in manual testing were
 * exactly the kind these catch: syncLineItems referenced an undefined $taxInfo so
 * every create with line items 500'd, and the list response never carried the
 * client name so the Client column rendered blank. Both are asserted here.
 */
class InvoiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private const OTHER = 999;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT, self::OTHER] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => 'Tenant '.$id, 'slug' => 'tenant-'.$id,
                'subdomain' => 'tenant'.$id, 'status' => 'active',
            ])->save();
        }
    }

    private function admin(int $tenantId = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => 'Admin', 'email' => 'a'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function client(int $tenantId = self::TENANT, string $company = 'Acme Pvt Ltd'): Client
    {
        return Client::create(['tenant_id' => $tenantId, 'company' => $company]);
    }

    private function payload(Client $client, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $client->id,
            'date'      => '2026-08-01',
            'due_date'  => '2026-08-31',
            'line_items' => [
                ['item_name' => 'Consulting', 'qty' => 2, 'rate' => 15000, 'tax' => 18, 'discount' => 0],
            ],
        ], $overrides);
    }

    public function test_creating_an_invoice_with_line_items_succeeds_and_computes_tax(): void
    {
        Sanctum::actingAs($this->admin());
        $client = $this->client();

        $res = $this->postJson('/api/sales/invoices', $this->payload($client))
            ->assertStatus(201);

        // 2 x 15000 = 30000, +18% = 35400. syncLineItems used to throw here.
        $line = $res->json('line_items.0');
        $this->assertSame('Consulting', $line['item_name']);
        $this->assertEquals(35400, round((float) $line['total'], 2));
    }

    public function test_a_percentage_line_discount_resolves_to_an_amount(): void
    {
        Sanctum::actingAs($this->admin());
        $client = $this->client();

        $res = $this->postJson('/api/sales/invoices', $this->payload($client, [
            'line_items' => [[
                'item_name' => 'Support', 'qty' => 12, 'rate' => 5000, 'tax' => 18,
                'discount' => 10, 'discount_mode' => 'percent',
            ]],
        ]))->assertStatus(201);

        // 10% of 60000 must be stored as 6000, not as the literal 10.
        $line = $res->json('line_items.0');
        $this->assertEquals(6000, round((float) $line['discount'], 2));
        $this->assertEquals(63720, round((float) $line['total'], 2)); // (60000-6000)*1.18
    }

    public function test_the_list_carries_the_client_company_name(): void
    {
        Sanctum::actingAs($this->admin());
        $client = $this->client(self::TENANT, 'Meridian Textiles');
        $this->postJson('/api/sales/invoices', $this->payload($client))->assertStatus(201);

        // The lists render `.client` as a string; it was undefined for a long time,
        // so every Client column showed blank.
        $this->getJson('/api/sales/invoices')
            ->assertStatus(200)
            ->assertJsonPath('0.client', 'Meridian Textiles');
    }

    public function test_marking_an_invoice_sent_sets_status_and_timestamp(): void
    {
        Sanctum::actingAs($this->admin());
        $client = $this->client();
        $id = $this->postJson('/api/sales/invoices', $this->payload($client))->json('id');

        $this->patchJson("/api/sales/invoices/{$id}/send")->assertStatus(200);

        $invoice = SalesInvoice::find($id);
        $this->assertSame('Unpaid', $invoice->status);
        $this->assertNotNull($invoice->sent_at);
    }

    public function test_invoices_are_scoped_to_the_callers_tenant(): void
    {
        // One invoice in each tenant, then read as tenant 1.
        Sanctum::actingAs($this->admin(self::OTHER));
        $this->postJson('/api/sales/invoices', $this->payload($this->client(self::OTHER, 'Foreign Co')))
            ->assertStatus(201);

        Sanctum::actingAs($this->admin(self::TENANT));
        $this->postJson('/api/sales/invoices', $this->payload($this->client(self::TENANT, 'Mine Ltd')))
            ->assertStatus(201);

        $companies = collect($this->getJson('/api/sales/invoices')->json())->pluck('client');
        $this->assertContains('Mine Ltd', $companies);
        $this->assertNotContains('Foreign Co', $companies);
    }

    public function test_another_tenants_invoice_cannot_be_read_or_modified(): void
    {
        Sanctum::actingAs($this->admin(self::OTHER));
        $foreignId = $this->postJson('/api/sales/invoices', $this->payload($this->client(self::OTHER)))
            ->json('id');

        Sanctum::actingAs($this->admin(self::TENANT));
        $this->getJson("/api/sales/invoices/{$foreignId}")->assertStatus(403);
        $this->patchJson("/api/sales/invoices/{$foreignId}/send")->assertStatus(403);
        $this->deleteJson("/api/sales/invoices/{$foreignId}")->assertStatus(403);
    }
}
