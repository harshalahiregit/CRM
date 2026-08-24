<?php

namespace Tests\Feature\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Tenant;
use App\Services\Customer\Contracts\TicketIntakeContract;
use App\Services\Customer\TicketIntakeUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The customer raises a support ticket from the portal.
 *
 * Customer owns the contract and this call site; Helpdesk owns the
 * implementation and its binding. So what is testable from this side is the
 * SEAM, not the ticket: that the right facts cross it, that nothing crosses it
 * that should not, and that the portal behaves sanely both before and after
 * Helpdesk registers a real intake.
 *
 * The one thing deliberately NOT asserted here is anything about numbering,
 * SLA, routing or the acknowledgement email. Those are Helpdesk's, and a test
 * on this side pinning them would freeze decisions that are Shivam's to change.
 */
class PortalRaiseTicketTest extends TestCase
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
            'password' => Hash::make('secret123'),
            'permissions' => ['support'],
        ]);
        Sanctum::actingAs($this->contact, ['*']);
    }

    /** A recording double standing in for Helpdesk's adapter. */
    private function spyIntake(int $returns = 4242): object
    {
        $spy = new class($returns) implements TicketIntakeContract {
            public array $calls = [];

            public function __construct(private int $returns) {}

            public function createFromCustomerPortal(
                int $tenantId, int $clientId, int $clientContactId,
                string $requesterName, string $requesterEmail,
                string $subject, string $body, ?string $priority = null,
            ): int {
                $this->calls[] = compact(
                    'tenantId', 'clientId', 'clientContactId',
                    'requesterName', 'requesterEmail', 'subject', 'body', 'priority',
                );

                return $this->returns;
            }
        };

        $this->app->instance(TicketIntakeContract::class, $spy);

        return $spy;
    }

    public function test_raising_a_ticket_hands_helpdesk_the_facts_it_cannot_look_up(): void
    {
        $spy = $this->spyIntake();

        $this->postJson('/api/portal/client/tickets', [
            'subject' => 'Login fails on the invoices page',
            'body'    => 'Since Tuesday it returns a 500.',
        ])->assertCreated()->assertJsonPath('id', 4242);

        $this->assertCount(1, $spy->calls);
        $call = $spy->calls[0];

        $this->assertSame($this->tenant->id, $call['tenantId']);
        $this->assertSame($this->client->id, $call['clientId']);
        $this->assertSame($this->contact->id, $call['clientContactId']);
        // Helpdesk cannot read client_contacts, and it needs both of these to
        // address the acknowledgement and show who asked.
        $this->assertSame('Anil Kumar', $call['requesterName']);
        $this->assertSame('anil@widget.test', $call['requesterEmail']);
        $this->assertSame('Login fails on the invoices page', $call['subject']);
    }

    public function test_the_client_id_comes_from_the_session_not_the_request(): void
    {
        $spy = $this->spyIntake();

        $other = Client::create([
            'tenant_id' => $this->tenant->id, 'company' => 'Someone Else', 'active' => true,
        ]);

        $this->postJson('/api/portal/client/tickets', [
            'subject'   => 'Trying it on',
            'body'      => 'Raise this against another customer please.',
            'client_id' => $other->id,
            'tenant_id' => 999,
        ])->assertCreated();

        // "Raise a ticket for customer 12" is not expressible here — the ids are
        // read off the authenticated contact, so a supplied one is simply ignored.
        $this->assertSame($this->client->id, $spy->calls[0]['clientId']);
        $this->assertSame($this->tenant->id, $spy->calls[0]['tenantId']);
    }

    public function test_priority_is_passed_through_as_a_hint_when_given(): void
    {
        $spy = $this->spyIntake();

        $this->postJson('/api/portal/client/tickets', [
            'subject' => 'Printer', 'body' => 'Jammed again.', 'priority' => 'high',
        ])->assertCreated();

        $this->assertSame('high', $spy->calls[0]['priority']);
    }

    public function test_omitting_priority_lets_helpdesk_choose(): void
    {
        $spy = $this->spyIntake();

        $this->postJson('/api/portal/client/tickets', ['subject' => 'X', 'body' => 'Y'])
            ->assertCreated();

        $this->assertNull($spy->calls[0]['priority'],
            'null means "your default", which is Helpdesk\'s decision to make');
    }

    public function test_a_priority_outside_the_list_is_rejected_rather_than_coerced(): void
    {
        $this->spyIntake();

        $this->postJson('/api/portal/client/tickets', [
            'subject' => 'X', 'body' => 'Y', 'priority' => 'urgent',
        ])->assertStatus(422)->assertJsonValidationErrors('priority');
    }

    public function test_subject_and_body_are_required(): void
    {
        $this->spyIntake();

        $this->postJson('/api/portal/client/tickets', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject', 'body']);
    }

    public function test_a_contact_without_the_support_permission_cannot_raise_one(): void
    {
        $spy = $this->spyIntake();
        $this->contact->update(['permissions' => ['invoice']]);

        $this->postJson('/api/portal/client/tickets', ['subject' => 'X', 'body' => 'Y'])
            ->assertStatus(403);

        $this->assertCount(0, $spy->calls, 'the seam must not be crossed at all');
    }

    public function test_raising_requires_authentication(): void
    {
        app()['auth']->forgetGuards();

        $this->postJson('/api/portal/client/tickets', ['subject' => 'X', 'body' => 'Y'])
            ->assertUnauthorized();
    }

    // ── Before Helpdesk's adapter lands ──────────────────────────────────────

    public function test_the_placeholder_refuses_rather_than_inventing_a_ticket_id(): void
    {
        $this->app->instance(TicketIntakeContract::class, new TicketIntakeUnavailable());

        // A stub returning a fake id would tell the customer their request was
        // logged when no ticket exists and no agent will ever see it.
        $this->postJson('/api/portal/client/tickets', ['subject' => 'X', 'body' => 'Y'])
            ->assertStatus(503);
    }

    public function test_the_portal_advertises_the_feature_only_once_it_is_real(): void
    {
        $this->app->instance(TicketIntakeContract::class, new TicketIntakeUnavailable());
        $this->getJson('/api/portal/client/me')->assertOk()->assertJsonPath('can_raise_ticket', false);

        $this->spyIntake();
        $this->getJson('/api/portal/client/me')->assertOk()->assertJsonPath('can_raise_ticket', true);
    }

    public function test_the_portal_never_writes_to_the_tickets_table_itself(): void
    {
        $this->spyIntake();

        $before = \Illuminate\Support\Facades\Schema::hasTable('tickets')
            ? \Illuminate\Support\Facades\DB::table('tickets')->count()
            : null;

        $this->postJson('/api/portal/client/tickets', ['subject' => 'X', 'body' => 'Y'])
            ->assertCreated();

        if ($before !== null) {
            $this->assertSame($before, \Illuminate\Support\Facades\DB::table('tickets')->count(),
                'Customer must not insert tickets — the row is Helpdesk\'s to create');
        }
    }
}
