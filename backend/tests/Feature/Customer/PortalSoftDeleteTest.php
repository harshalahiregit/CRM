<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Sales\SalesInvoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A customer must never be shown a record staff have deleted.
 *
 * Every portal read used a bare DB::table(), which bypasses the SoftDeletes
 * global scope. So deleting an invoice removed it from the staff screens and
 * left it on the customer's — the customer was told they owed money for a
 * document that, as far as the business was concerned, no longer existed.
 *
 * That is worse than a display bug. The statement is what a customer pays
 * against, and the two sides of the same account disagreeing is the kind of
 * thing that ends in an argument nobody can win.
 */
class PortalSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private ClientContact $contact;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $t = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $this->client = Client::create(['tenant_id' => $t->id, 'company' => 'Widget Ltd', 'active' => true]);
        $this->staff = User::create([
            'tenant_id' => $t->id, 'name' => 'Admin', 'email' => 'a@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]);
        $this->contact = ClientContact::create([
            'tenant_id' => $t->id, 'client_id' => $this->client->id,
            'first_name' => 'Anil', 'last_name' => 'K', 'email' => 'anil@widget.test',
            'active' => true, 'portal_status' => 'active',
            'password' => Hash::make('secret123'),
            'permissions' => ['invoice', 'estimate', 'proposal', 'contract', 'support', 'project'],
        ]);
    }

    private function invoice(string $number, float $balance): SalesInvoice
    {
        return SalesInvoice::create([
            'tenant_id' => $this->client->tenant_id, 'client_id' => $this->client->id,
            'number' => $number, 'date' => now()->subDays(10), 'due_date' => now()->subDays(3),
            'status' => 'Unpaid', 'total' => $balance, 'paid' => 0, 'balance' => $balance,
            'created_by' => $this->staff->id,
        ]);
    }

    private function asContact(): void
    {
        Sanctum::actingAs($this->contact, ['*']);
    }

    public function test_a_deleted_invoice_disappears_from_the_customers_list(): void
    {
        $keep = $this->invoice('INV-1', 3000);
        $gone = $this->invoice('INV-2', 5000);
        $gone->delete();   // soft delete — staff no longer see it

        $this->asContact();
        $numbers = collect($this->getJson('/api/portal/client/invoices')->assertOk()->json())
            ->pluck('number');

        $this->assertEquals(['INV-1'], $numbers->all());
    }

    public function test_the_customers_outstanding_matches_what_staff_see(): void
    {
        $this->invoice('INV-1', 3000);
        $this->invoice('INV-2', 5000)->delete();

        $this->asContact();
        $fin = $this->getJson('/api/portal/client/dashboard')->assertOk()->json('finance');

        // 3,000 — not 8,000. The deleted invoice must not be billed for.
        $this->assertSame(3000.0, (float) $fin['outstanding']);
    }

    public function test_a_deleted_overdue_invoice_stops_raising_an_alert(): void
    {
        $this->invoice('INV-1', 5000)->delete();

        $this->asContact();
        $d = $this->getJson('/api/portal/client/dashboard')->assertOk()->json();

        $this->assertSame(0, $d['finance']['overdue']);
        $this->assertSame([], collect($d['actions'])->where('key', 'overdue_invoices')->values()->all());
    }

    public function test_the_statement_excludes_deleted_invoices(): void
    {
        $this->invoice('INV-1', 3000);
        $this->invoice('INV-2', 5000)->delete();

        $this->asContact();
        $rows = collect($this->getJson('/api/portal/client/statement')->assertOk()->json('rows') ?? []);

        $this->assertFalse(
            $rows->contains(fn ($r) => ($r['number'] ?? $r['reference'] ?? null) === 'INV-2'),
            'a deleted invoice appeared on the customer statement'
        );
    }

    public function test_live_records_are_untouched(): void
    {
        $this->invoice('INV-1', 3000);
        $this->invoice('INV-2', 2000);

        $this->asContact();
        $this->assertCount(2, $this->getJson('/api/portal/client/invoices')->assertOk()->json());
        $this->assertSame(5000.0, (float) $this->getJson('/api/portal/client/dashboard')->json('finance.outstanding'));
    }
}
