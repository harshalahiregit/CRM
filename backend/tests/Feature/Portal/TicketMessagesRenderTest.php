<?php

namespace Tests\Feature\Portal;

use App\Models\Helpdesk\Ticket;
use App\Models\Helpdesk\TicketReply;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Purchase\PurchaseVendorStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A ticket thread reads as a conversation, in both directions.
 *
 * One column, two kinds of author. An agent composes in a rich editor, so
 * `ticket_replies.message` holds HTML and every staff screen renders it as
 * HTML. A vendor types into a plain input on their portal, so the same column
 * holds plain text. Nothing reconciled the two, and it failed both ways:
 *
 *  - The portal printed the stored string as text, so an agent's "hii" reached
 *    the vendor as the literal characters `<p>hii</p><p><br></p>`.
 *
 *  - The portals bypassed TicketReplyController — the only place HtmlSanitizer
 *    ran — so portal text was stored raw and then rendered with
 *    dangerouslySetInnerHTML in the agent console. That is a stored XSS from an
 *    account anyone can register into the staff console, and the console
 *    carried a comment asserting the opposite.
 *
 * Both engines are covered because a Purchase vendor and a TPV vendor use the
 * same screen against different controllers.
 */
class TicketMessagesRenderTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function tpvUser(): User
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Acme', 'role' => 'third_party_vendor',
            'email' => 'v-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme',
            'status' => VendorStatus::ACTIVE, 'user_id' => $user->id,
        ]);

        return $user;
    }

    private function purchaseVendor(): PurchaseVendor
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Bolt Supplies',
            'purchase_vendor_code' => 'PV-'.Str::random(8),
            'status' => PurchaseVendorStatus::ACTIVE, 'portal_status' => 'active',
            'email' => 'bolt@vendor.test',
        ]);
        $this->markOnboarded($vendor);

        return $vendor->fresh();
    }

    private function ticketFor(User $user): Ticket
    {
        return Ticket::create([
            'tenant_id' => self::TENANT, 'subject' => 'issue', 'description' => '<p>the original</p>',
            'status' => 'open', 'priority' => 'medium', 'source' => 'portal',
            'created_by' => $user->id, 'requester_email' => $user->email,
        ]);
    }

    private function agentReply(Ticket $t, string $html): TicketReply
    {
        return TicketReply::create([
            'tenant_id' => self::TENANT, 'ticket_id' => $t->id,
            'sender_type' => 'agent', 'sender_id' => null, 'message' => $html,
        ]);
    }

    /** The exact body from the report: an agent's "hii" out of a rich editor. */
    public function test_an_agents_reply_reaches_the_vendor_as_words_not_tags(): void
    {
        $user = $this->tpvUser();
        $ticket = $this->ticketFor($user);
        $this->agentReply($ticket, '<p>hii</p><p><br></p>');

        Sanctum::actingAs($user);
        $body = $this->getJson("/api/portal/my-work/tickets/{$ticket->id}")->assertOk()->json();

        $reply = $body['replies'][0];

        // What the screen renders: real markup, not characters that look like it.
        $this->assertStringContainsString('<p>hii</p>', $reply['message_html']);

        // And the plain-text twin is the words alone — no angle brackets at all.
        $this->assertSame('hii', $reply['message']);
        $this->assertStringNotContainsString('<', $reply['message']);
    }

    /** The same, for the description an agent may have edited. */
    public function test_the_ticket_description_is_carried_both_ways(): void
    {
        $user = $this->tpvUser();
        $ticket = $this->ticketFor($user);

        Sanctum::actingAs($user);
        $body = $this->getJson("/api/portal/my-work/tickets/{$ticket->id}")->assertOk()->json();

        $this->assertStringContainsString('<p>the original</p>', $body['description_html']);
        $this->assertSame('the original', $body['description']);
    }

    /**
     * A vendor's own plain text survives as plain text.
     *
     * "a < b" must still read "a < b" — escaped so it renders literally, never
     * swallowed as the start of a tag.
     */
    public function test_a_vendors_plain_text_is_escaped_not_interpreted(): void
    {
        $user = $this->tpvUser();
        $ticket = $this->ticketFor($user);

        Sanctum::actingAs($user);
        $this->postJson("/api/portal/my-work/tickets/{$ticket->id}/reply", [
            'message' => "a < b and R&D\nsecond line",
        ])->assertCreated();

        $body = $this->getJson("/api/portal/my-work/tickets/{$ticket->id}")->assertOk()->json();
        $reply = $body['replies'][0];

        $this->assertStringContainsString('a &lt; b', $reply['message_html'], 'the < must be escaped, not a tag');
        $this->assertStringContainsString('R&amp;D', $reply['message_html']);
        $this->assertStringContainsString('<br>', $reply['message_html'], 'the newline must survive as a break');
        $this->assertStringContainsString('a < b', $reply['message'], 'the plain-text twin reads as typed');
    }

    /**
     * A portal reply cannot smuggle script into the agent console.
     *
     * The console renders replies with dangerouslySetInnerHTML. Before this,
     * nothing on the portal write path sanitized — so this payload was stored
     * verbatim and executed for whichever agent opened the ticket.
     */
    public function test_a_portal_reply_cannot_inject_script_into_the_agent_console(): void
    {
        $user = $this->tpvUser();
        $ticket = $this->ticketFor($user);

        Sanctum::actingAs($user);
        $this->postJson("/api/portal/my-work/tickets/{$ticket->id}/reply", [
            'message' => '<img src=x onerror="alert(1)"><script>alert(2)</script>ok',
        ])->assertCreated();

        $stored = TicketReply::where('ticket_id', $ticket->id)->latest('id')->first()->message;

        $this->assertStringNotContainsString('onerror', $stored, 'event handlers must never be stored');
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('alert(2)', $stored, 'script CONTENT must go too, not just its tag');
    }

    /** And a raised ticket's body is normalised the same way. */
    public function test_a_raised_ticket_body_is_sanitized_on_the_way_in(): void
    {
        $user = $this->tpvUser();

        Sanctum::actingAs($user);
        $id = $this->postJson('/api/portal/my-work/tickets', [
            'subject' => 'Need help',
            'body' => '<script>alert(1)</script><b>bold</b> please',
        ])->assertCreated()->json('id');

        $stored = Ticket::find($id)->description;

        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('alert(1)', $stored);
        $this->assertStringContainsString('<b>bold</b>', $stored, 'harmless formatting is kept');
    }

    /* ── Purchase — the same screen, the other controller ─────────────────── */

    public function test_the_purchase_portal_behaves_identically(): void
    {
        $vendor = $this->purchaseVendor();

        Sanctum::actingAs($vendor);
        $id = $this->postJson('/api/portal/purchase/work-tickets', [
            'subject' => 'issue', 'body' => 'plain body',
        ])->assertCreated()->json('id');

        // An agent answers from the rich editor.
        TicketReply::create([
            'tenant_id' => self::TENANT, 'ticket_id' => $id,
            'sender_type' => 'agent', 'sender_id' => null, 'message' => '<p>hii</p><p><br></p>',
        ]);

        $body = $this->getJson("/api/portal/purchase/work-tickets/{$id}")->assertOk()->json();

        $this->assertStringContainsString('<p>hii</p>', $body['replies'][0]['message_html']);
        $this->assertSame('hii', $body['replies'][0]['message']);

        // And the vendor's own reply is sanitized on the way in here too.
        $this->postJson("/api/portal/purchase/work-tickets/{$id}/reply", [
            'message' => '<img src=x onerror="alert(1)">fine',
        ])->assertCreated();

        $stored = TicketReply::where('ticket_id', $id)->latest('id')->first()->message;
        $this->assertStringNotContainsString('onerror', $stored);
    }
}
