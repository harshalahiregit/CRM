<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Customer\ClientExpense;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Saving a per-customer record, and the tenant scoping of what it may point at.
 *
 * ClientExpenseController::rules() referenced $request, which does not exist in
 * that scope — rules() takes no arguments. Every save of a customer expense
 * threw before the payload was even inspected: 100% of writes, every tenant.
 * index() and destroy() never call rules(), so the tab listed and deleted
 * happily and looked entirely healthy until somebody pressed Save.
 *
 * rules() now receives the customer, which both fixes that and makes the
 * tenant-scoped exists() the easy thing to write. Three rules were accepting
 * any row in the table — a contact or a staff member belonging to another
 * tenant entirely.
 */
class ClientRecordWritesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Client $client;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $this->client = Client::create([
            'tenant_id' => $this->tenant->id, 'company' => 'Widget Ltd', 'active' => true,
        ]);
        $this->staff = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'a@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]);
        Sanctum::actingAs($this->staff);
    }

    private function url(string $p): string
    {
        return "/api/customers/{$this->client->id}/{$p}";
    }

    public function test_a_customer_expense_can_actually_be_saved(): void
    {
        $this->postJson($this->url('expenses'), [
            'name' => 'Site visit travel', 'amount' => 2400, 'date' => '2026-08-20',
        ])->assertCreated();

        $this->assertSame(1, ClientExpense::where('client_id', $this->client->id)->count());
    }

    public function test_an_expense_can_be_edited(): void
    {
        $id = $this->postJson($this->url('expenses'), ['name' => 'Travel', 'amount' => 100])
            ->assertCreated()->json('id');

        $this->putJson($this->url("expenses/{$id}"), ['name' => 'Travel and parking', 'amount' => 150])
            ->assertOk();

        $this->assertSame('Travel and parking', ClientExpense::find($id)->name);
    }

    public function test_every_record_tab_accepts_a_write(): void
    {
        // The bug was invisible on read, so each writable tab is exercised.
        $payloads = [
            'contracts'       => ['subject' => 'MSA'],
            'subscriptions'   => ['name' => 'Monthly QC'],
            'expenses'        => ['name' => 'Courier'],
            'domains'         => ['domain' => 'widget.test'],
            'purchase-orders' => ['po_number' => 'PO-1', 'value' => 1000],
            'complaints'      => ['kind' => 'Complaint', 'subject' => 'Late', 'raised_at' => '2026-08-01 09:00:00'],
            'activities'      => ['type' => 'Call', 'subject' => 'Chased', 'occurred_at' => '2026-08-01 09:00:00'],
            'feedback'        => ['metric' => 'CSAT', 'score' => 4, 'responded_at' => '2026-08-01 09:00:00'],
        ];

        foreach ($payloads as $slug => $body) {
            $this->postJson($this->url($slug), $body)
                ->assertCreated("POST {$slug} failed");
        }
    }

    // ── tenant scoping of exists() ───────────────────────────────────────────

    public function test_a_contact_from_another_customer_is_refused(): void
    {
        $other = Client::create(['tenant_id' => $this->tenant->id, 'company' => 'Other Ltd', 'active' => true]);
        $theirs = ClientContact::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $other->id,
            'first_name' => 'Not', 'last_name' => 'Ours', 'active' => true,
        ]);

        $this->postJson($this->url('activities'), [
            'type' => 'Call', 'subject' => 'x', 'occurred_at' => '2026-08-01 09:00:00',
            'client_contact_id' => $theirs->id,
        ])->assertStatus(422)->assertJsonValidationErrors('client_contact_id');
    }

    public function test_a_staff_member_from_another_tenant_cannot_own_a_complaint(): void
    {
        $other = Tenant::create([
            'name' => 'Globex', 'slug' => 'globex', 'subdomain' => 'globex',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $them = User::create([
            'tenant_id' => $other->id, 'name' => 'Them', 'email' => 't@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]);

        $this->postJson($this->url('complaints'), [
            'kind' => 'Complaint', 'subject' => 'x', 'raised_at' => '2026-08-01 09:00:00',
            'owner_id' => $them->id,
        ])->assertStatus(422)->assertJsonValidationErrors('owner_id');
    }

    public function test_our_own_contact_and_staff_are_still_accepted(): void
    {
        $ours = ClientContact::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
            'first_name' => 'Ours', 'active' => true,
        ]);

        $this->postJson($this->url('activities'), [
            'type' => 'Call', 'subject' => 'x', 'occurred_at' => '2026-08-01 09:00:00',
            'client_contact_id' => $ours->id,
        ])->assertCreated();

        $this->postJson($this->url('complaints'), [
            'kind' => 'Complaint', 'subject' => 'x', 'raised_at' => '2026-08-01 09:00:00',
            'owner_id' => $this->staff->id,
        ])->assertCreated();
    }
}
