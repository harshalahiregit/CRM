<?php

namespace Tests\Feature\Helpdesk;

use App\Models\Helpdesk\Ticket;
use App\Models\Helpdesk\TicketTag;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Everything the create form offers has to survive the round trip.
 *
 * Service, Tags, Cc and attachments all existed in the backend — tables,
 * endpoints, even pickers of their own — and none of them were reachable from
 * the create form. Tickets arrived untagged, with no service, with nobody
 * copied and with no file, because there was no way to supply any of it.
 *
 * Wiring a field to a form is easy to do halfway: the input appears, the value
 * is posted, and the server quietly drops it because the request never allowed
 * the key. So each field is asserted on the stored row, not on the response.
 */
class TicketCreateCarriesEveryFieldTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->admin = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Shivam', 'email' => 'a@helpdesk.test',
            'password' => Hash::make('x'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function tag(string $name): TicketTag
    {
        return TicketTag::create(['tenant_id' => self::TENANT, 'name' => $name]);
    }

    public function test_tags_chosen_on_the_form_are_attached(): void
    {
        $a = $this->tag('billing');
        $b = $this->tag('urgent-customer');

        $res = $this->actingAs($this->admin)->postJson('/api/helpdesk/tickets', [
            'subject' => 'Invoice is wrong',
            'tags'    => [$a->id, $b->id],
        ]);

        $res->assertCreated();
        $ticket = Ticket::latest('id')->first();

        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $ticket->tags()->pluck('ticket_tags.id')->all(),
            'tags posted with the ticket were dropped — they are attached after the insert, so a '
            .'silent failure there leaves the ticket unfiled',
        );
    }

    public function test_a_tag_from_another_tenant_is_refused(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();

        $foreign = TicketTag::create(['tenant_id' => 2, 'name' => 'someone-elses']);

        $this->actingAs($this->admin)
            ->postJson('/api/helpdesk/tickets', ['subject' => 'x', 'tags' => [$foreign->id]])
            ->assertStatus(422);
    }

    public function test_the_cc_list_is_stored_on_the_ticket(): void
    {
        $this->actingAs($this->admin)->postJson('/api/helpdesk/tickets', [
            'subject'         => 'Copy my manager',
            'requester_email' => 'customer@example.test',
            'cc'              => ['Manager@Example.test', 'qa@example.test'],
        ])->assertCreated();

        $this->assertSame(
            ['manager@example.test', 'qa@example.test'],
            Ticket::latest('id')->first()->cc,
            'Cc is lower-cased on the way in so the same person typed two ways is one address',
        );
    }

    /**
     * The requester is the To of every message on the ticket. Cc'ing them their
     * own mail is how one thread becomes two.
     */
    public function test_the_requester_is_never_cc_of_their_own_ticket(): void
    {
        $this->actingAs($this->admin)->postJson('/api/helpdesk/tickets', [
            'subject'         => 'Self cc',
            'requester_email' => 'customer@example.test',
            'cc'              => ['CUSTOMER@example.test', 'real-cc@example.test'],
        ])->assertCreated();

        $this->assertSame(['real-cc@example.test'], Ticket::latest('id')->first()->cc);
    }

    public function test_an_empty_cc_list_reads_as_unset_rather_than_an_empty_array(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/helpdesk/tickets', ['subject' => 'No cc', 'cc' => []])
            ->assertCreated();

        $this->assertNull(Ticket::latest('id')->first()->cc);
    }

    public function test_a_malformed_cc_address_is_refused_rather_than_stored(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/helpdesk/tickets', ['subject' => 'x', 'cc' => ['not-an-address']])
            ->assertStatus(422);
    }

    /**
     * A file raised WITH the ticket belongs to the ticket.
     *
     * Before ticket_attachments.reply_id became nullable, the only way in was to
     * reply to yourself — which stamps first_responded_at and shows the SLA
     * clock stopped before anyone had read the ticket.
     */
    public function test_a_file_can_be_attached_when_the_ticket_is_raised(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin)->post('/api/helpdesk/tickets', [
            'subject'     => 'Screenshot attached',
            'attachments' => [UploadedFile::fake()->image('broken.png')],
        ])->assertCreated();

        $ticket = Ticket::latest('id')->first();

        $this->assertDatabaseHas('ticket_attachments', [
            'tenant_id' => self::TENANT,
            'ticket_id' => $ticket->id,
            'reply_id'  => null,
            'file_name' => 'broken.png',
        ]);

        $this->assertNull($ticket->first_responded_at,
            'attaching a file must not look like somebody answered the ticket');
    }

    public function test_service_and_the_rest_of_the_form_are_saved(): void
    {
        $service = \App\Models\Helpdesk\TicketService::create([
            'tenant_id' => self::TENANT, 'name' => 'Hardware', 'order' => 0,
        ]);

        $this->actingAs($this->admin)->postJson('/api/helpdesk/tickets', [
            'subject'         => 'Laptop will not boot',
            'description'     => '<p>Dead on arrival.</p>',
            'service_id'      => $service->id,
            'requester_name'  => 'Asha Rao',
            'requester_email' => 'asha@example.test',
        ])->assertCreated();

        $this->assertDatabaseHas('tickets', [
            'subject'         => 'Laptop will not boot',
            'service_id'      => $service->id,
            'requester_name'  => 'Asha Rao',
            'requester_email' => 'asha@example.test',
        ]);
    }

    /** The Contact picker reads this; an empty list makes the field unusable. */
    public function test_the_contacts_endpoint_answers_for_the_picker(): void
    {
        $res = $this->actingAs($this->admin)->getJson('/api/helpdesk/contacts');

        $res->assertOk();
        $this->assertIsArray($res->json('data'));
    }
}
