<?php

namespace Tests\Feature\Contract;

use App\Models\Contract\Contract;
use App\Models\Customer\Client;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Contract\ContractParty;
use App\Support\Contract\ContractStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Contract module, end to end.
 *
 * A standalone module: its own tables, its own routes. Sales, Purchase and TPV
 * keep the contract features they already had — this one stands beside them and
 * links out to their customers and vendors.
 *
 * The test that matters most is dual signing. A contract needs BOTH parties'
 * names on it, and the older single-signature design in Sales could hold only
 * one: whoever signed second either overwrote the first or, on the public path,
 * was refused with "this contract has already been signed". Every assertion
 * about two signatures below exists so that cannot happen here.
 */
class ContractModuleTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function staff(string $role = 'staff'): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Priya '.Str::random(4), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@sangoe.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function client(): Client
    {
        return Client::create([
            'tenant_id' => self::TENANT, 'company' => 'Northwind Traders',
            'email' => 'ap-'.Str::random(4).'@northwind.local',
        ]);
    }

    /** A contract created through the API, as the form does it. */
    private function make(array $overrides = []): array
    {
        $client = $this->client();

        $body = array_merge([
            'title' => 'Annual Maintenance Agreement',
            'description' => 'Cover for the dockside cranes.',
            'party_type' => 'customer',
            'party_id' => $client->id,
            'value' => 250000,
            'currency' => 'INR',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'pages' => [
                ['title' => 'Scope of Work', 'content' => '<p>Quarterly inspection of all lifting equipment.</p>'],
                ['title' => 'Payment Terms', 'content' => '<p>Net 45 from invoice date.</p>'],
            ],
        ], $overrides);

        $res = $this->postJson('/api/contracts', $body)->assertStatus(201);

        return [Contract::findOrFail($res->json('id')), $client];
    }

    /* ── The module exists and is company-wide ──────────────────────── */

    public function test_every_internal_role_can_reach_the_module(): void
    {
        foreach (['admin', 'staff', 'manager', 'hr', 'doctor'] as $role) {
            Sanctum::actingAs($this->staff($role));
            $this->getJson('/api/contracts')->assertOk();
            $this->getJson('/api/contracts/stats')->assertOk();
        }
    }

    public function test_an_external_role_cannot(): void
    {
        foreach (['third_party_vendor', 'client', 'vendor'] as $role) {
            Sanctum::actingAs($this->staff($role));
            $this->getJson('/api/contracts')->assertStatus(403);
        }
    }

    public function test_it_does_not_touch_the_sales_contract_tables(): void
    {
        Sanctum::actingAs($this->staff('admin'));
        $this->make();

        // The whole point of a separate module: the existing feature is left
        // exactly where it was.
        $this->assertSame(1, Contract::count());
        $this->assertSame(0, \App\Models\Sales\SalesContract::count());
    }

    /* ── Creation ───────────────────────────────────────────────────── */

    public function test_a_contract_is_created_with_its_pages_and_a_reference(): void
    {
        Sanctum::actingAs($this->staff('admin'));
        [$contract, $client] = $this->make();

        $this->assertMatchesRegularExpression('/^CTR-\d{4}-\d{4}$/', $contract->reference_no);
        $this->assertCount(2, $contract->pages);
        $this->assertSame('Scope of Work', $contract->pages->first()->title);

        // The counterparty is a pointer AND a name snapshot — the agreement was
        // with the name printed on the page, so it must survive a rename.
        $this->assertSame(Client::class, $contract->party_type);
        $this->assertSame($client->id, $contract->party_id);
        $this->assertSame('Northwind Traders', $contract->party_name);
    }

    public function test_the_signing_token_never_rides_along_in_a_payload(): void
    {
        Sanctum::actingAs($this->staff('admin'));
        [$contract] = $this->make();

        // Possession of the token is authority to sign, so it must not appear in
        // the list or the detail response — only in the endpoint that exists to
        // hand it over.
        $this->assertStringNotContainsString($contract->public_token,
            $this->getJson('/api/contracts')->getContent());
        $this->assertStringNotContainsString($contract->public_token,
            $this->getJson("/api/contracts/{$contract->id}")->getContent());

        $this->assertStringContainsString($contract->public_token,
            $this->getJson("/api/contracts/{$contract->id}/signing-link")->assertOk()->getContent());
    }

    /* ── Dual signing — the reason this module was rebuilt ──────────── */

    public function test_both_parties_sign_and_neither_erases_the_other(): void
    {
        $staff = $this->staff('admin');
        Sanctum::actingAs($staff);
        [$contract] = $this->make();

        // Our side signs first.
        $this->postJson("/api/contracts/{$contract->id}/sign", [
            'method' => 'type', 'name' => 'Priya Sharma', 'email' => 'priya@sangoe.local',
        ])->assertOk();

        $this->assertNull($contract->fresh()->fully_signed_at, 'one signature is an offer, not an agreement');

        // Then the counterparty, through the public link — which used to be
        // refused outright once anybody had signed.
        $token = $contract->public_token;
        $this->postJson("/api/public/contracts/{$token}/sign", [
            'method' => 'draw', 'name' => 'Ravi Kumar',
            'image' => 'data:image/png;base64,iVBORw0KGgo=',
            'latitude' => 19.0760, 'longitude' => 72.8777,
        ])->assertOk();

        $contract->refresh()->load('signatures');

        // BOTH survive, with their own evidence.
        $this->assertCount(2, $contract->signatures);
        $company = $contract->signatureFor(ContractParty::COMPANY);
        $party   = $contract->signatureFor(ContractParty::PARTY);

        $this->assertSame('Priya Sharma', $company->signer_name);
        $this->assertSame('Ravi Kumar', $party->signer_name);
        $this->assertNotNull($company->signed_at);
        $this->assertNotNull($party->signed_at);
        $this->assertSame('19.0760000', (string) $party->latitude);

        $this->assertNotNull($contract->fully_signed_at, 'both signed, so it is in force');
        $this->assertSame(ContractStatus::ACTIVE, $contract->status);
    }

    public function test_the_same_party_cannot_sign_twice(): void
    {
        Sanctum::actingAs($this->staff('admin'));
        [$contract] = $this->make();

        $this->postJson("/api/contracts/{$contract->id}/sign", [
            'method' => 'type', 'name' => 'Priya Sharma',
        ])->assertOk();

        // Refused rather than replaced: the first signature is evidence, and
        // evidence is not editable.
        $this->postJson("/api/contracts/{$contract->id}/sign", [
            'method' => 'type', 'name' => 'Somebody Else',
        ])->assertStatus(422);

        $this->assertSame('Priya Sharma',
            $contract->fresh()->signatureFor(ContractParty::COMPANY)->signer_name);
    }

    public function test_a_drawn_signature_with_no_image_is_refused(): void
    {
        Sanctum::actingAs($this->staff('admin'));
        [$contract] = $this->make();

        // Otherwise an empty canvas marks the contract signed.
        $this->postJson("/api/contracts/{$contract->id}/sign", [
            'method' => 'draw', 'name' => 'Priya Sharma',
        ])->assertStatus(422);

        $this->assertNull($contract->fresh()->signatureFor(ContractParty::COMPANY)?->signed_at);
    }

    public function test_a_signature_in_march_for_an_april_start_is_signed_but_not_active(): void
    {
        Sanctum::actingAs($this->staff('admin'));
        [$contract] = $this->make(['start_date' => now()->addMonth()->toDateString()]);

        $this->postJson("/api/contracts/{$contract->id}/sign", ['method' => 'type', 'name' => 'Priya'])->assertOk();
        $this->postJson("/api/public/contracts/{$contract->public_token}/sign",
            ['method' => 'type', 'name' => 'Ravi'])->assertOk();

        // "Signed" and "in force today" are different questions, and anything
        // asking whether work may proceed means the second.
        $this->assertSame(ContractStatus::SIGNED, $contract->fresh()->status);
        $this->assertNotNull($contract->fresh()->fully_signed_at);
    }

    /* ── The audit trail ────────────────────────────────────────────── */

    public function test_opening_the_public_page_records_the_view_before_any_signature(): void
    {
        Sanctum::actingAs($this->staff('admin'));
        [$contract] = $this->make();

        $this->getJson("/api/public/contracts/{$contract->public_token}")->assertOk();

        $row = $contract->fresh()->signatureFor(ContractParty::PARTY);
        $this->assertNotNull($row->viewed_at, 'when they first opened it is half the audit trail');
        $this->assertNull($row->signed_at, 'viewing is not signing');
    }

    public function test_signing_mints_a_certificate_number(): void
    {
        Sanctum::actingAs($this->staff('admin'));
        [$contract] = $this->make();

        $this->postJson("/api/contracts/{$contract->id}/sign", ['method' => 'type', 'name' => 'Priya'])->assertOk();

        $cert = $contract->fresh()->signatureFor(ContractParty::COMPANY)->certificate_no;
        $this->assertNotNull($cert);
        $this->assertStringStartsWith($contract->reference_no, $cert);
    }

    /* ── The public surface gives away only what it must ────────────── */

    public function test_the_verify_endpoint_says_it_is_real_and_nothing_else(): void
    {
        Sanctum::actingAs($this->staff('admin'));
        [$contract] = $this->make();
        $this->postJson("/api/contracts/{$contract->id}/sign", ['method' => 'type', 'name' => 'Priya'])->assertOk();

        $body = $this->getJson("/api/public/contracts/{$contract->public_token}/verify")->assertOk();

        $body->assertJsonPath('reference_no', $contract->reference_no);

        // Somebody scanning a QR from a printed page has proved only that they
        // hold the paper. That is not grounds to hand over the value or terms.
        $raw = $body->getContent();
        $this->assertStringNotContainsString('250000', $raw);
        $this->assertStringNotContainsString('Quarterly inspection', $raw);
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->getJson('/api/public/contracts/'.Str::random(48))->assertStatus(404);
    }

    /* ── Editing rules ──────────────────────────────────────────────── */

    public function test_terms_cannot_be_edited_once_both_sides_have_signed(): void
    {
        Sanctum::actingAs($this->staff('admin'));
        [$contract] = $this->make();

        $this->postJson("/api/contracts/{$contract->id}/sign", ['method' => 'type', 'name' => 'Priya'])->assertOk();
        $this->postJson("/api/public/contracts/{$contract->public_token}/sign",
            ['method' => 'type', 'name' => 'Ravi'])->assertOk();

        // The words are the agreement. Editing them under two signatures would
        // make the signed PDF and the record say different things.
        $this->putJson("/api/contracts/{$contract->id}", [
            'pages' => [['title' => 'Rewritten', 'content' => 'Something else entirely']],
        ])->assertStatus(422);

        $this->assertSame('Scope of Work', $contract->fresh()->pages->first()->title);
    }

    /* ── Renewal ────────────────────────────────────────────────────── */

    public function test_renewing_makes_a_new_contract_and_leaves_the_old_one_alone(): void
    {
        Sanctum::actingAs($this->staff('admin'));
        [$contract] = $this->make();
        $originalEnd = $contract->end_date->toDateString();

        $res = $this->postJson("/api/contracts/{$contract->id}/renew", [
            'start_date' => now()->addYear()->toDateString(),
            'end_date'   => now()->addYears(2)->toDateString(),
        ])->assertStatus(201);

        $fresh = Contract::findOrFail($res->json('id'));

        $this->assertSame($contract->id, $fresh->renewed_from_id);
        $this->assertSame(ContractStatus::DRAFT, $fresh->status);
        // The terms carry over, or somebody has to retype the whole agreement.
        $this->assertCount(2, $fresh->pages);
        // And the expired one still records what was in force last year.
        $this->assertSame($originalEnd, $contract->fresh()->end_date->toDateString());
    }

    /* ── Sending it out ─────────────────────────────────────────────── */

    public function test_sending_mails_the_contract_and_marks_it_sent(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        Sanctum::actingAs($this->staff('admin'));
        [$contract] = $this->make();

        $this->postJson("/api/contracts/{$contract->id}/send", [
            'to' => 'accounts@northwind.local',
            'cc' => ['finance@northwind.local'],
            'subject' => 'Please sign',
        ])->assertOk()->assertJsonPath('status', 'sent');

        \Illuminate\Support\Facades\Mail::assertSent(
            \App\Mail\Contract\ContractDispatchMail::class,
            fn ($m) => $m->hasTo('accounts@northwind.local')
                && $m->hasCc('finance@northwind.local')
                // The signing link has to travel WITH the PDF. A copy they
                // cannot sign is not much use, and a link with no attachment
                // leaves them nothing to keep or forward for approval.
                && str_contains($m->signUrl, $contract->public_token),
        );

        $contract->refresh();
        $this->assertSame(ContractStatus::SENT, $contract->status);
        $this->assertNotNull($contract->sent_at);
    }

    public function test_the_send_is_recorded_on_the_thread(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        Sanctum::actingAs($this->staff('admin'));
        [$contract] = $this->make();

        $this->postJson("/api/contracts/{$contract->id}/send", ['to' => 'accounts@northwind.local'])->assertOk();

        // Whoever picks this up next needs to see that it went, and where to.
        $this->assertStringContainsString('accounts@northwind.local',
            $contract->discussions()->latest()->first()->body);
    }

    public function test_a_contract_with_no_terms_is_not_sent(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        Sanctum::actingAs($this->staff('admin'));
        [$contract] = $this->make(['pages' => [], 'description' => null]);

        // An empty page with a signature box on it is not a contract.
        $this->postJson("/api/contracts/{$contract->id}/send", ['to' => 'accounts@northwind.local'])
            ->assertStatus(422);

        \Illuminate\Support\Facades\Mail::assertNothingSent();
        $this->assertSame(ContractStatus::DRAFT, $contract->fresh()->status);
    }

    public function test_a_failed_send_does_not_mark_the_contract_sent(): void
    {
        // The SMTP server refusing is the realistic failure — a wrong password,
        // an unreachable host. Marking it Sent first would leave a contract
        // claiming it went out when nobody received it.
        \Illuminate\Support\Facades\Mail::shouldReceive('mailer')->andThrow(new \RuntimeException('smtp down'));

        Sanctum::actingAs($this->staff('admin'));
        [$contract] = $this->make();

        try {
            $this->postJson("/api/contracts/{$contract->id}/send", ['to' => 'a@b.local']);
        } catch (\Throwable $e) {
            // The transport blew up; what matters is the state below.
        }

        $this->assertSame(ContractStatus::DRAFT, $contract->fresh()->status);
        $this->assertNull($contract->fresh()->sent_at);
    }

    /* ── Categories, created inline from the form ───────────────────── */

    public function test_a_category_can_be_created_from_the_form_and_repeats_are_tolerated(): void
    {
        Sanctum::actingAs($this->staff('admin'));

        $first = $this->postJson('/api/contracts/categories', ['name' => 'Service Agreement'])->assertStatus(201);
        // Two people adding the same type from two forms must not be an error
        // the second one has to understand.
        $again = $this->postJson('/api/contracts/categories', ['name' => 'Service Agreement'])->assertStatus(201);

        $this->assertSame($first->json('id'), $again->json('id'));
        $this->assertSame(1, \App\Models\Contract\ContractCategory::count());
    }

    /* ── Tenancy ────────────────────────────────────────────────────── */

    public function test_another_tenants_contract_is_a_404(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();

        Sanctum::actingAs($this->staff('admin'));
        [$contract] = $this->make();

        $other = User::create([
            'tenant_id' => 2, 'name' => 'Other', 'role' => 'admin',
            'email' => 'o-'.Str::random(4).'@t2.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        Sanctum::actingAs($other);
        $this->getJson("/api/contracts/{$contract->id}")->assertStatus(404);
    }
}
