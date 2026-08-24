<?php

namespace Tests\Feature\Helpdesk;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Helpdesk\Ticket;
use App\Models\Tenant;
use App\Services\Customer\Contracts\TicketIntakeContract;
use App\Services\Helpdesk\HelpdeskTicketIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A customer raises a ticket from the portal, end to end across both modules.
 *
 * Customer owns the contract and the portal call site; Helpdesk owns this
 * adapter and the defaults. The seam tests in PortalRaiseTicketTest assert that
 * the right facts cross it. These assert the other half: that what comes out is
 * a real ticket rather than a row that merely looks like one.
 */
class PortalTicketIntakeTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private ClientContact $contact;
    private Tenant $tenant;

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
        $this->contact = ClientContact::create([
            'tenant_id' => $this->tenant->id, 'client_id' => $this->client->id,
            'first_name' => 'Anil', 'last_name' => 'Kumar', 'email' => 'anil@widget.test',
            'active' => true, 'portal_status' => 'active',
            'password' => Hash::make('secret123'), 'permissions' => ['support'],
        ]);
        Sanctum::actingAs($this->contact, ['*']);
    }

    private function raise(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/portal/client/tickets', array_merge([
            'subject' => 'Invoices page returns a 500',
            'body'    => 'Since Tuesday, opening any invoice errors.',
        ], $over));
    }

    public function test_the_contract_resolves_to_helpdesks_adapter_not_the_placeholder(): void
    {
        $this->assertInstanceOf(
            HelpdeskTicketIntakeService::class,
            app(TicketIntakeContract::class),
            'the portal would return 503 if the placeholder were still bound',
        );
    }

    public function test_the_portal_now_advertises_the_feature(): void
    {
        $this->getJson('/api/portal/client/me')->assertOk()->assertJsonPath('can_raise_ticket', true);
    }

    public function test_raising_creates_a_real_ticket_against_the_customer(): void
    {
        $id = $this->raise()->assertCreated()->json('id');

        $ticket = Ticket::find($id);
        $this->assertNotNull($ticket, 'no ticket row was created');
        $this->assertSame($this->tenant->id, (int) $ticket->tenant_id);
        $this->assertSame($this->client->id, (int) $ticket->customer_id);
        $this->assertSame('Invoices page returns a 500', $ticket->subject);
        $this->assertStringContainsString('Since Tuesday', (string) $ticket->description);
    }

    public function test_it_is_stamped_as_portal_intake(): void
    {
        $ticket = Ticket::find($this->raise()->json('id'));

        // So these are reportable separately from internal / widget / email.
        $this->assertSame('portal', $ticket->source);
    }

    public function test_created_by_is_null_rather_than_the_contacts_id(): void
    {
        $ticket = Ticket::find($this->raise()->json('id'));

        // The portal contact is not a User. createTicket() falls back to
        // auth()->id() when created_by is ABSENT, which here would write the
        // ClientContact's id into a column that points at users — a wrong row.
        $this->assertNull($ticket->created_by);
    }

    public function test_the_requester_is_captured_for_the_acknowledgement(): void
    {
        $ticket = Ticket::find($this->raise()->json('id'));

        $this->assertSame('Anil Kumar', $ticket->requester_name);
        $this->assertSame('anil@widget.test', $ticket->requester_email);
    }

    public function test_it_lands_unassigned_in_the_open_queue(): void
    {
        $ticket = Ticket::find($this->raise()->json('id'));

        $this->assertNull($ticket->assigned_to, 'a portal ticket goes to triage, not to a person');
        $this->assertSame('open', $ticket->status);
    }

    public function test_a_customers_priority_hint_is_honoured_up_to_the_cap(): void
    {
        $ticket = Ticket::find($this->raise(['priority' => 'high'])->json('id'));

        $this->assertSame('high', $ticket->priority);
    }

    public function test_omitting_priority_uses_the_default(): void
    {
        $ticket = Ticket::find($this->raise()->json('id'));

        $this->assertSame('medium', $ticket->priority);
    }

    public function test_a_customer_cannot_self_assign_urgent(): void
    {
        // The portal validates the field, so this goes straight at the adapter —
        // the cap must hold even if something else ever calls it.
        $id = app(TicketIntakeContract::class)->createFromCustomerPortal(
            tenantId: $this->tenant->id,
            clientId: $this->client->id,
            clientContactId: $this->contact->id,
            requesterName: 'Anil Kumar',
            requesterEmail: 'anil@widget.test',
            subject: 'Everything is on fire',
            body: 'Please treat as urgent.',
            priority: 'urgent',
        );

        $this->assertSame('high', Ticket::find($id)->priority,
            'urgent must be clamped, or urgent stops meaning anything');
    }

    public function test_the_new_ticket_shows_up_on_the_customers_own_ticket_list(): void
    {
        $id = $this->raise()->json('id');

        // The round trip that matters to the customer: they raise it, then see it.
        $this->getJson('/api/portal/client/tickets')
             ->assertOk()
             ->assertJsonFragment(['id' => $id]);
    }
}
