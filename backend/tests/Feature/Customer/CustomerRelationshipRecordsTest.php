<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientActivity;
use App\Models\Customer\ClientComplaint;
use App\Models\Customer\ClientDomain;
use App\Models\Customer\ClientPurchaseOrder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Activities (§4), Complaints and Escalations (§17 SERVICE), Domain Manager and
 * customer Purchase Orders.
 *
 * These are Customer's own tables, so the interesting cases are the ones where
 * a wrong value would mislead somebody making a decision: a PO that looks like
 * it has budget left when it does not, a domain expiry that fails to warn, a
 * complaint that never reaches Health.
 */
class CustomerRelationshipRecordsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Client $client;

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
        Sanctum::actingAs(User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'a@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]));
    }

    private function url(string $path): string
    {
        return "/api/customers/{$this->client->id}/{$path}";
    }

    // ── §4 Activities ────────────────────────────────────────────────────────

    public function test_an_activity_can_be_logged_and_listed(): void
    {
        $this->postJson($this->url('activities'), [
            'type' => 'Call', 'direction' => 'Outbound', 'subject' => 'Chased the PO',
            'occurred_at' => '2026-08-20 10:00:00', 'outcome' => 'Connected', 'duration_minutes' => 12,
        ])->assertCreated();

        $this->getJson($this->url('activities'))->assertOk()->assertJsonCount(1);
    }

    public function test_an_unknown_activity_type_is_refused(): void
    {
        // Free-text types would become rows the timeline cannot group.
        $this->postJson($this->url('activities'), [
            'type' => 'Telepathy', 'subject' => 'x', 'occurred_at' => '2026-08-20 10:00:00',
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_a_due_follow_up_is_found_and_a_future_one_is_not(): void
    {
        $base = [
            'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
            'type' => 'Call', 'subject' => 'x', 'occurred_at' => now()->subDay(),
        ];
        ClientActivity::create($base + ['follow_up_on' => now()->subDay()->toDateString()]);
        ClientActivity::create($base + ['follow_up_on' => now()->addWeek()->toDateString()]);
        // Already dealt with — must not count as owed.
        ClientActivity::create($base + ['follow_up_on' => now()->subDay()->toDateString(), 'follow_up_done' => true]);

        $this->assertSame(1, ClientActivity::forTenant($this->tenant->id)->followUpDue()->count());
    }

    // ── §17 SERVICE — Complaints ─────────────────────────────────────────────

    public function test_a_complaint_can_be_escalated_without_creating_a_second_record(): void
    {
        $res = $this->postJson($this->url('complaints'), [
            'kind' => 'Complaint', 'subject' => 'Late delivery', 'severity' => 'Medium',
            'raised_at' => '2026-08-01 09:00:00',
        ])->assertCreated();

        $id = $res->json('id');

        $this->putJson($this->url("complaints/{$id}"), [
            'kind' => 'Escalation', 'subject' => 'Late delivery', 'severity' => 'High',
            'status' => 'Investigating', 'raised_at' => '2026-08-01 09:00:00',
        ])->assertOk();

        $this->assertSame(1, ClientComplaint::forTenant($this->tenant->id)->count());
        $this->assertSame('Escalation', ClientComplaint::first()->kind);
    }

    public function test_resolving_before_it_was_raised_is_refused(): void
    {
        $this->postJson($this->url('complaints'), [
            'kind' => 'Complaint', 'subject' => 'x',
            'raised_at' => '2026-08-10 09:00:00', 'resolved_at' => '2026-08-01 09:00:00',
        ])->assertStatus(422)->assertJsonValidationErrors('resolved_at');
    }

    public function test_resolution_hours_are_measured_from_raised_to_resolved(): void
    {
        $c = ClientComplaint::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
            'kind' => 'Complaint', 'subject' => 'x',
            'raised_at' => '2026-08-01 09:00:00', 'resolved_at' => '2026-08-02 15:00:00',
        ]);

        $this->assertSame(30.0, $c->resolution_hours);
    }

    // ── Domain Manager ───────────────────────────────────────────────────────

    public function test_a_domain_expiring_inside_the_window_is_flagged_and_a_distant_one_is_not(): void
    {
        $base = ['tenant_id' => $this->tenant->id, 'client_id' => $this->client->id, 'status' => 'Active'];
        ClientDomain::create($base + ['domain' => 'soon.test',  'expires_on' => now()->addDays(10)]);
        ClientDomain::create($base + ['domain' => 'later.test', 'expires_on' => now()->addDays(200)]);

        $found = ClientDomain::forTenant($this->tenant->id)->expiringSoon()->pluck('domain');

        $this->assertEquals(['soon.test'], $found->all());
    }

    public function test_an_auto_renewing_domain_still_warns(): void
    {
        // Auto-renew fails often enough that suppressing the warning is how a
        // domain gets lost.
        ClientDomain::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
            'domain' => 'auto.test', 'expires_on' => now()->addDays(5),
            'auto_renew' => true, 'status' => 'Active',
        ]);

        $this->assertSame(1, ClientDomain::forTenant($this->tenant->id)->expiringSoon()->count());
    }

    public function test_a_cancelled_domain_does_not_warn(): void
    {
        ClientDomain::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
            'domain' => 'gone.test', 'expires_on' => now()->addDays(5), 'status' => 'Cancelled',
        ]);

        $this->assertSame(0, ClientDomain::forTenant($this->tenant->id)->expiringSoon()->count());
    }

    // ── Customer Purchase Orders ─────────────────────────────────────────────

    public function test_remaining_headroom_never_goes_negative(): void
    {
        $po = ClientPurchaseOrder::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
            'po_number' => 'PO-1', 'value' => 1000, 'consumed' => 1250,
        ]);

        // Over-billing is a data problem, not negative budget.
        $this->assertSame(0.0, $po->remaining);
    }

    public function test_consumed_cannot_be_set_through_the_api(): void
    {
        $res = $this->postJson($this->url('purchase-orders'), [
            'po_number' => 'PO-2', 'value' => 5000, 'consumed' => 4999,
        ])->assertCreated();

        // What has been billed is Sales' fact, not a number a form may assert.
        $this->assertEquals(0, ClientPurchaseOrder::find($res->json('id'))->consumed);
    }

    public function test_an_expired_po_with_budget_left_is_flagged_but_a_spent_one_is_not(): void
    {
        $base = [
            'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
            'status' => 'Open', 'valid_until' => now()->subDay()->toDateString(),
        ];
        ClientPurchaseOrder::create($base + ['po_number' => 'PO-LIVE', 'value' => 1000, 'consumed' => 200]);
        ClientPurchaseOrder::create($base + ['po_number' => 'PO-SPENT', 'value' => 1000, 'consumed' => 1000]);

        $found = ClientPurchaseOrder::forTenant($this->tenant->id)->lapsedWithHeadroom()->pluck('po_number');

        $this->assertEquals(['PO-LIVE'], $found->all());
    }

    // ── isolation ────────────────────────────────────────────────────────────

    public function test_another_tenants_customer_is_refused(): void
    {
        $other = Tenant::create([
            'name' => 'Other', 'slug' => 'other', 'subdomain' => 'other',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $theirs = Client::create(['tenant_id' => $other->id, 'company' => 'Theirs', 'active' => true]);

        // 403 rather than 404: AssertsClientTenant answers this way everywhere
        // in the module, and one endpoint disagreeing would be the surprise.
        $this->getJson("/api/customers/{$theirs->id}/activities")->assertStatus(403);
        $this->getJson("/api/customers/{$theirs->id}/complaints")->assertStatus(403);
        $this->getJson("/api/customers/{$theirs->id}/domains")->assertStatus(403);
        $this->getJson("/api/customers/{$theirs->id}/timeline")->assertStatus(403);
        $this->getJson("/api/customers/{$theirs->id}/linked/projects")->assertStatus(403);
    }
}
