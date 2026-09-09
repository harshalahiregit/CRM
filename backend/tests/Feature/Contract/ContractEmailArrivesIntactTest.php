<?php

namespace Tests\Feature\Contract;

use App\Models\Customer\Client;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * What actually lands in the recipient's inbox.
 *
 * Reported live, all three at once: the logo was a broken image, there was no
 * usable link, and CC looked like it did nothing.
 *
 * Every assertion here is made against the real Symfony message the array
 * transport collected, NOT against Mailable::render(). That distinction is the
 * whole reason this file exists: `Mailer::render()` runs
 * replaceEmbeddedAttachments(), which rewrites every `cid:` reference back into
 * an inline data: URI so the preview shows up in a browser. Rendering the
 * mailable to check the logo therefore reports a data: URI whichever way the
 * template is written -- it agrees with you either way, which makes it useless
 * as evidence.
 */
class ContractEmailArrivesIntactTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private const LIVE = 'https://crm.nexforeconsulting.com';

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Sangoe OS', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        config(['app.frontend_url' => self::LIVE]);

        Sanctum::actingAs(User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]));
    }

    private function contractId(): int
    {
        $c = Client::create(['tenant_id' => self::TENANT, 'company' => 'Northwind Traders']);

        return (int) $this->postJson('/api/contracts', [
            'title' => 'Annual Maintenance Agreement',
            'party_type' => 'customer', 'party_id' => $c->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'pages' => [['title' => 'Scope', 'content' => '<p>As agreed.</p>']],
        ])->assertSuccessful()->json('id');
    }

    /** The message the transport was actually handed. */
    private function sentEmail(): Email
    {
        $messages = Mail::mailer(config('mail.default'))->getSymfonyTransport()->messages();
        $this->assertNotEmpty($messages, 'nothing was handed to the mail transport at all');

        return $messages[0]->getOriginalMessage();
    }

    private function send(array $payload = []): void
    {
        $id = $this->contractId();

        $res = $this->postJson("/api/contracts/{$id}/send", array_merge([
            'to' => 'accounts@northwind.local',
        ], $payload));

        if ($res->getStatusCode() >= 400) {
            $this->fail('the send was refused: '.$res->getStatusCode().' — '
                .json_encode($res->json('errors') ?? $res->json('message')));
        }
    }

    /* ── the link ───────────────────────────────────────────────── */

    public function test_the_signing_link_is_in_the_body_twice_over(): void
    {
        $this->send();
        $html = $this->sentEmail()->getHtmlBody();

        // Once as the button's href, once as plain text underneath it. A client
        // that strips the styled button must still leave a way through.
        $this->assertGreaterThanOrEqual(2, substr_count($html, self::LIVE.'/contracts/sign/'),
            'the recipient has no way to reach the contract');
        $this->assertStringNotContainsString('localhost', $html);
    }

    /* ── the logo ───────────────────────────────────────────────── */

    public function test_the_logo_is_embedded_as_cid_not_as_a_data_uri(): void
    {
        $this->send();
        $email = $this->sentEmail();
        $html = $email->getHtmlBody();

        // Gmail, Outlook and Apple Mail all strip `data:` image sources -- that
        // is exactly what turned the header into a broken-image icon.
        $this->assertStringNotContainsString('src="data:image', $html,
            'the logo is a data: URI again, which mail clients refuse to render');

        if (str_contains($html, '<img')) {
            $this->assertStringContainsString('src="cid:', $html,
                'an image with no cid: reference cannot resolve to an attachment');

            $inline = collect($email->getAttachments())
                ->first(fn ($p) => str_starts_with((string) $p->getContentType(), 'image/'));

            $this->assertNotNull($inline, 'the cid: points at an attachment that was never added');
            $this->assertNotEmpty($inline->getBody(), 'the embedded logo is empty');
        }
    }

    /* ── CC ─────────────────────────────────────────────────────── */

    public function test_cc_addresses_reach_the_message(): void
    {
        $this->send(['cc' => ['finance@northwind.local', 'legal@northwind.local']]);

        $cc = collect($this->sentEmail()->getCc())->map(fn ($a) => $a->getAddress())->all();

        $this->assertContains('finance@northwind.local', $cc);
        $this->assertContains('legal@northwind.local', $cc);
        $this->assertCount(2, $cc);
    }

    public function test_the_cc_is_recorded_on_the_contract_thread(): void
    {
        $this->send(['cc' => ['finance@northwind.local']]);

        // Who else saw it is part of the record, not just a header nobody keeps.
        $this->assertDatabaseHas('contract_discussions', [
            'body' => 'Contract sent to accounts@northwind.local (cc: finance@northwind.local).',
        ]);
    }

    public function test_a_bad_cc_address_is_refused_before_anything_is_sent(): void
    {
        $id = $this->contractId();

        $this->postJson("/api/contracts/{$id}/send", [
            'to' => 'accounts@northwind.local',
            'cc' => ['not-an-address'],
        ])->assertStatus(422);

        $this->assertEmpty(
            Mail::mailer(config('mail.default'))->getSymfonyTransport()->messages(),
            'a message went out despite the payload being rejected',
        );
    }

    /* ── the attachment ─────────────────────────────────────────── */

    public function test_the_pdf_rides_along(): void
    {
        $this->send();

        $pdf = collect($this->sentEmail()->getAttachments())
            ->first(fn ($p) => str_contains((string) $p->getContentType(), 'pdf'));

        // A link alone leaves them nothing to keep or to forward to whoever
        // approves it on their side.
        $this->assertNotNull($pdf, 'the contract PDF was not attached');
        $this->assertStringStartsWith('%PDF', $pdf->getBody());
    }
}
