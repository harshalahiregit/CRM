<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContract;
use App\Models\Sales\SalesInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Customer\CustomerHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * §9 — Customer Risk. Six indicators, separate from Customer Health.
 *
 *   Payment · Contract · Service · Project   — derived from real signals
 *   Relationship · Compliance                — a human judgement
 *
 * The last two exist because neither has an honest signal in the system, and a
 * number invented from proxies would be worse than an admitted blank. That
 * reasoning only holds if somebody can actually record the judgement: the
 * columns shipped with no validation rule and no form field, so they stayed
 * null forever and the panel showed two permanent dashes. Half these tests
 * exist to keep the write path alive.
 */
class CustomerRiskTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'a@x.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]);
        Sanctum::actingAs($this->user);
    }

    private function client(array $attrs = []): Client
    {
        return Client::create(array_merge([
            'tenant_id' => $this->tenant->id, 'company' => 'Widget Ltd', 'active' => true,
        ], $attrs));
    }

    private function risk(Client $c): array
    {
        return app(CustomerHealthService::class)->risk($c->fresh());
    }

    private function invoice(Client $c, string $due, float $balance, string $status = 'Unpaid'): void
    {
        SalesInvoice::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $c->id,
            'number' => 'INV-'.uniqid(), 'date' => '2026-01-01', 'due_date' => $due,
            'status' => $status, 'total' => 10000, 'paid' => 10000 - $balance,
            'balance' => $balance, 'created_by' => $this->user->id,
        ]);
    }

    // ── the six indicators exist and are separate from Health ────────────────

    public function test_risk_reports_all_six_indicators_and_is_separate_from_health(): void
    {
        $c = $this->client();
        $risk = $this->risk($c);

        $this->assertSame(
            ['payment', 'contract', 'service', 'project'],
            array_keys($risk['derived'])
        );
        $this->assertSame(['relationship', 'compliance'], array_keys($risk['manual']));

        // Separate from Health: the overview returns both, and neither is
        // computed from the other's output.
        $res = $this->getJson("/api/customers/{$c->id}/overview")->assertOk();
        $this->assertNotNull($res->json('risk'));
        $this->assertNotNull($res->json('health'));
        $this->assertArrayNotHasKey('risk', $res->json('health'));
    }

    public function test_nothing_assessed_reads_unknown_rather_than_low(): void
    {
        // "We have not looked" must never render as "this is safe".
        $this->assertSame('Unknown', $this->risk($this->client())['overall']);
    }

    // ── derived indicators ───────────────────────────────────────────────────

    public function test_payment_risk_rises_with_overdue_invoices(): void
    {
        $c = $this->client(['payment_terms' => 'Net 30']);
        $this->invoice($c, '2020-01-01', 5000);   // overdue
        $this->invoice($c, '2099-01-01', 5000);   // not

        // 1 of 2 overdue → score 50 → Medium (>=50 and <75).
        $this->assertSame('Medium', $this->risk($c)['derived']['payment']);

        $this->invoice($c, '2020-01-01', 5000);   // 2 of 3 overdue → 33 → High
        $this->assertSame('High', $this->risk($c)['derived']['payment']);
    }

    public function test_contract_risk_is_low_while_a_contract_is_active(): void
    {
        $c = $this->client();
        ClientContract::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $c->id,
            'subject' => 'MSA', 'status' => 'Active',
            'start_date' => '2026-01-01', 'end_date' => now()->addYear()->toDateString(),
        ]);

        $this->assertSame('Low', $this->risk($c)['derived']['contract']);
    }

    public function test_an_indicator_with_no_data_stays_null_rather_than_guessing(): void
    {
        // Project risk has no signal for a customer with no projects. Null is
        // the honest answer; 'Low' would be a fabrication.
        $this->assertNull($this->risk($this->client())['derived']['project']);
    }

    // ── manual indicators: the write path ────────────────────────────────────

    public function test_relationship_and_compliance_risk_can_be_recorded(): void
    {
        $c = $this->client();

        $this->putJson("/api/customers/{$c->id}", [
            'company' => 'Widget Ltd',
            'risk_relationship' => 'High',
            'risk_compliance'   => 'Low',
        ])->assertOk();

        $risk = $this->risk($c);
        $this->assertSame('High', $risk['manual']['relationship']);
        $this->assertSame('Low', $risk['manual']['compliance']);
    }

    public function test_a_manual_risk_can_be_cleared_back_to_unassessed(): void
    {
        $c = $this->client(['risk_relationship' => 'High']);

        $this->putJson("/api/customers/{$c->id}", [
            'company' => 'Widget Ltd', 'risk_relationship' => null,
        ])->assertOk();

        $this->assertNull($this->risk($c)['manual']['relationship']);
    }

    public function test_an_invented_risk_level_is_refused(): void
    {
        $c = $this->client();

        $this->putJson("/api/customers/{$c->id}", [
            'company' => 'Widget Ltd', 'risk_relationship' => 'Catastrophic',
        ])->assertStatus(422)->assertJsonValidationErrors('risk_relationship');
    }

    // ── the overall rating ───────────────────────────────────────────────────

    public function test_overall_risk_is_the_worst_indicator_not_an_average(): void
    {
        $c = $this->client(['risk_relationship' => 'Low', 'risk_compliance' => 'Low']);
        $this->assertSame('Low', $this->risk($c)['overall']);

        // One High among Lows must surface. An average would bury it.
        $c->update(['risk_compliance' => 'High']);
        $this->assertSame('High', $this->risk($c)['overall']);
    }

    public function test_a_manual_judgement_alone_is_enough_to_rate_a_customer(): void
    {
        // Even with no invoices, contracts, tickets or projects, a recorded
        // judgement must move the customer off Unknown.
        $c = $this->client(['risk_compliance' => 'Medium']);

        $this->assertSame('Medium', $this->risk($c)['overall']);
    }
}
