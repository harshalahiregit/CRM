<?php

namespace Tests\Feature\Sales;

use App\Models\Customer\Client;
use App\Models\Sales\Lead;
use App\Models\Sales\Proposal;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Client column on the proposals list.
 *
 * A proposal points at either a customer or a lead through rel_type/rel_id.
 * That pair is not a relation Eloquent can eager load, so nothing ever set the
 * `client` key the column reads and it rendered blank on every single row —
 * while the record underneath knew perfectly well who it was addressed to.
 *
 * Both arms are covered, plus the fallback, because resolving only customers
 * would leave every lead proposal exactly as broken as before.
 */
class ProposalClientColumnTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'prop-client-t',
            'subdomain' => 'propclient', 'status' => 'active',
        ])->save();

        $this->actor = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'email' => 'a'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]);

        Sanctum::actingAs($this->actor);
    }

    private function proposal(array $over): Proposal
    {
        return Proposal::create(array_merge([
            'tenant_id'  => self::TENANT,
            'subject'    => 'Annual retainer',
            'date'       => '2026-09-03',
            'status'     => 'Draft',
            'created_by' => $this->actor->id,
        ], $over));
    }

    private function lead(array $over): Lead
    {
        return Lead::create(array_merge([
            'tenant_id' => self::TENANT, 'created_by' => $this->actor->id,
        ], $over));
    }

    private function rowFor(int $id): array
    {
        $rows = collect($this->getJson('/api/sales/proposals')->assertOk()->json());

        return (array) $rows->firstWhere('id', $id);
    }

    public function test_a_customer_proposal_carries_the_company_name(): void
    {
        $client = Client::create(['tenant_id' => self::TENANT, 'company' => 'Meridian Textiles Pvt Ltd']);
        $p = $this->proposal(['rel_type' => 'customer', 'rel_id' => $client->id]);

        $this->assertSame('Meridian Textiles Pvt Ltd', $this->rowFor($p->id)['client'] ?? null);
    }

    public function test_a_lead_proposal_carries_the_lead_name(): void
    {
        $lead = $this->lead(['name' => 'Ravi Iyer', 'company' => 'Iyer Traders']);
        $p = $this->proposal(['rel_type' => 'lead', 'rel_id' => $lead->id]);

        $this->assertSame('Ravi Iyer', $this->rowFor($p->id)['client'] ?? null);
    }

    /**
     * A lead with a blank contact name falls back to its company, which is the
     * order the proposal form's own lead picker uses. `leads.name` is NOT NULL,
     * so the real-world case is an empty string, not a missing column.
     */
    public function test_a_lead_with_a_blank_name_falls_back_to_its_company(): void
    {
        $lead = $this->lead(['name' => '', 'company' => 'Iyer Traders']);
        $p = $this->proposal(['rel_type' => 'lead', 'rel_id' => $lead->id]);

        $this->assertSame('Iyer Traders', $this->rowFor($p->id)['client'] ?? null);
    }

    /** The linked record is gone, so the free-text recipient is the honest answer. */
    public function test_a_dangling_reference_falls_back_to_the_typed_recipient(): void
    {
        $p = $this->proposal([
            'rel_type' => 'customer', 'rel_id' => 999999, 'proposal_to' => 'Walk-in buyer',
        ]);

        $this->assertSame('Walk-in buyer', $this->rowFor($p->id)['client'] ?? null);
    }

    /** Never another tenant's customer, even if its id is guessed correctly. */
    public function test_a_customer_from_another_tenant_is_not_resolved(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'Tenant 2', 'slug' => 'prop-client-t2',
            'subdomain' => 'propclient2', 'status' => 'active',
        ])->save();

        $foreign = Client::create(['tenant_id' => 2, 'company' => 'Someone Else Ltd']);
        $p = $this->proposal(['rel_type' => 'customer', 'rel_id' => $foreign->id]);

        $this->assertNotSame('Someone Else Ltd', $this->rowFor($p->id)['client'] ?? null);
    }
}
