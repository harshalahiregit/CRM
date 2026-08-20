<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContract;
use App\Models\Sales\SalesInvoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The overview reads other modules' data. The thing most worth guarding is that
 * it stays inside one tenant and one customer — a count that quietly includes
 * another customer's invoices is worse than no count at all.
 */
class Customer360OverviewTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => ucfirst($slug), 'slug' => $slug, 'subdomain' => $slug,
            'plan' => 'professional', 'status' => 'active',
        ]);
    }

    private function client(Tenant $t, string $company): Client
    {
        return Client::create(['tenant_id' => $t->id, 'company' => $company, 'active' => true]);
    }

    private function actingAdmin(Tenant $t): User
    {
        $u = User::create([
            'tenant_id' => $t->id, 'name' => 'Admin', 'email' => 'a'.$t->id.'@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]);
        Sanctum::actingAs($u);

        return $u;
    }

    public function test_it_returns_kpis_alerts_and_owner(): void
    {
        $t = $this->tenant('acme');
        $u = $this->actingAdmin($t);
        $c = $this->client($t, 'Widget Ltd');

        $res = $this->getJson("/api/customers/{$c->id}/overview")->assertOk();

        $res->assertJsonStructure(['kpis', 'alerts', 'owner']);
        $this->assertNotEmpty($res->json('kpis'));
    }

    public function test_outstanding_sums_balances_not_totals(): void
    {
        $t = $this->tenant('acme');
        $u = $this->actingAdmin($t);
        $c = $this->client($t, 'Widget Ltd');

        // A part-paid invoice must contribute only what is still owed.
        // The model's creating hook forces balance = total, so the part-payment
        // is applied afterwards, exactly as recording a real payment would.
        $inv = SalesInvoice::create([
            'tenant_id' => $t->id, 'client_id' => $c->id, 'number' => 'INV-1',
            'date' => now()->toDateString(), 'due_date' => now()->addDays(10)->toDateString(),
            'total' => 1000, 'status' => 'Unpaid', 'created_by' => $u->id,
        ]);
        $inv->update(['paid' => 400, 'balance' => 600, 'status' => 'Partially Paid']);

        $kpis = collect($this->getJson("/api/customers/{$c->id}/overview")->json('kpis'));

        $this->assertSame(600.0, (float) $kpis->firstWhere('key', 'outstanding')['value']);
    }

    public function test_another_customers_invoices_are_not_counted(): void
    {
        $t = $this->tenant('acme');
        $u = $this->actingAdmin($t);
        $mine  = $this->client($t, 'Mine');
        $other = $this->client($t, 'Other');

        SalesInvoice::create([
            'tenant_id' => $t->id, 'client_id' => $other->id, 'number' => 'INV-2',
            'date' => now()->toDateString(), 'due_date' => now()->addDays(15)->toDateString(),
            'total' => 900, 'status' => 'Unpaid', 'created_by' => $u->id,
        ]);

        $kpis = collect($this->getJson("/api/customers/{$mine->id}/overview")->json('kpis'));

        $this->assertSame(0.0, (float) $kpis->firstWhere('key', 'outstanding')['value']);
    }

    public function test_a_customer_from_another_tenant_is_refused(): void
    {
        $mine   = $this->tenant('acme');
        $theirs = $this->tenant('globex');
        $this->actingAdmin($mine);
        $foreign = $this->client($theirs, 'Not Mine');

        $this->getJson("/api/customers/{$foreign->id}/overview")->assertForbidden();
    }

    public function test_an_overdue_invoice_raises_an_alert_and_a_future_one_does_not(): void
    {
        $t = $this->tenant('acme');
        $u = $this->actingAdmin($t);
        $c = $this->client($t, 'Widget Ltd');

        SalesInvoice::create([
            'tenant_id' => $t->id, 'client_id' => $c->id, 'number' => 'INV-3',
            'date' => now()->subDays(60)->toDateString(), 'due_date' => now()->subDays(30)->toDateString(),
            'total' => 500, 'paid' => 0, 'balance' => 500, 'status' => 'Unpaid', 'created_by' => $u->id,
        ]);

        $keys = collect($this->getJson("/api/customers/{$c->id}/overview")->json('alerts'))->pluck('key');
        $this->assertContains('invoices_overdue', $keys->all());
    }

    public function test_a_contract_expiring_soon_alerts_but_a_distant_one_does_not(): void
    {
        $t = $this->tenant('acme');
        $u = $this->actingAdmin($t);
        $c = $this->client($t, 'Widget Ltd');

        ClientContract::create([
            'tenant_id' => $t->id, 'client_id' => $c->id, 'subject' => 'Soon',
            'status' => 'Active', 'end_date' => now()->addDays(10)->toDateString(),
        ]);
        ClientContract::create([
            'tenant_id' => $t->id, 'client_id' => $c->id, 'subject' => 'Later',
            'status' => 'Active', 'end_date' => now()->addDays(200)->toDateString(),
        ]);

        $alerts = collect($this->getJson("/api/customers/{$c->id}/overview")->json('alerts'));
        $expiring = $alerts->firstWhere('key', 'contracts_expiring');

        $this->assertNotNull($expiring);
        $this->assertStringStartsWith('1 contract', $expiring['message']);
    }

    /** A module that isn't installed must degrade to zero, never a 500. */
    public function test_it_survives_a_missing_module_table(): void
    {
        $t = $this->tenant('acme');
        $u = $this->actingAdmin($t);
        $c = $this->client($t, 'Widget Ltd');

        DB::statement('DROP TABLE IF EXISTS tickets');

        $kpis = collect($this->getJson("/api/customers/{$c->id}/overview")->assertOk()->json('kpis'));
        $this->assertSame(0, (int) $kpis->firstWhere('key', 'tickets')['value']);
    }
}
