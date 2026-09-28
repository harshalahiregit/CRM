<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrOffer;
use App\Models\Hr\HrOnboarding;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\OfferPortalToken;
use App\Services\Hr\OfferService;
use App\Services\Hr\OnboardingPortalToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The candidate's offer link, and what it is now made of.
 *
 * Whoever holds this link sees the offered CTC and the full salary breakup, and
 * can ACCEPT the offer — an acceptance writes a typed name, a drawn signature,
 * an IP and a device against that person. It used to be a 48-character string
 * kept in plaintext, returned in nine API responses, and resolved by two
 * separate implementations. The properties worth defending are therefore blunt:
 *
 *   THE RAW TOKEN IS NEVER STORED. Tests go looking for it in the row, in the
 *   audit trail and in the responses rather than trusting it was never put
 *   there.
 *
 *   EVERY ENTRY POINT SHARES ONE RESOLVER. Proving the landing page rejects a
 *   stale token says nothing about the accept route, so all six public routes
 *   are driven with a re-keyed token, not just the first.
 *
 *   THE OFFER'S OWN CLOCK IS UNTOUCHED. A large block below exists only to
 *   prove that hardening the credential changed nothing about validity_date,
 *   the Expired status, or what a candidate sees after accepting, declining or
 *   being withdrawn.
 */
class OfferPortalTokenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('hr_documents');
        // The careers portal resolves the letter off the local/public disks
        // rather than hr_documents — a pre-existing inconsistency in that
        // service, not something this phase changes. Faked so its download
        // path can be exercised.
        Storage::fake('local');
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'opt', 'status' => 'active']);
        $this->other  = Tenant::create(['name' => 'Two', 'slug' => 'opt2', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function hrUser(?Tenant $t = null): User
    {
        return User::create([
            'tenant_id' => ($t ?: $this->tenant)->id, 'name' => 'HR',
            'email' => uniqid().'@opt.test', 'password' => Hash::make('Password123!'),
            'role' => 'admin', 'status' => 'active',
        ]);
    }

    /** A staff account with no HR-queue rights. */
    private function plainUser(?Tenant $t = null): User
    {
        return User::create([
            'tenant_id' => ($t ?: $this->tenant)->id, 'name' => 'Staff',
            'email' => uniqid().'@opt.test', 'password' => Hash::make('Password123!'),
            'role' => 'staff', 'status' => 'active',
        ]);
    }

    /**
     * whatsapp_opt_in defaults to TRUE in the schema, which decides whether
     * extend() delivers a link — and therefore whether it re-keys. Stated
     * explicitly here so no test depends on a column default staying put.
     */
    private function candidate(?Tenant $t = null, bool $whatsApp = false): HrCandidate
    {
        $t = $t ?: $this->tenant;

        return HrCandidate::create([
            'tenant_id' => $t->id, 'name' => 'C'.substr(uniqid(), -5),
            'email' => uniqid().'@cand.test', 'phone' => '9000000000',
            'whatsapp_opt_in' => $whatsApp,
            'stage' => 'Offer', 'status' => 'Active',
        ]);
    }

    private function offer(?Tenant $t = null, array $attrs = []): HrOffer
    {
        $t = $t ?: $this->tenant;

        return HrOffer::create(array_merge([
            'tenant_id'     => $t->id,
            'candidate_id'  => $this->candidate($t)->id,
            'position'      => 'Analyst',
            'department'    => 'Operations',
            'offered_ctc'   => 900000,
            'joining_date'  => now()->addDays(30)->toDateString(),
            'validity_date' => now()->addDays(7)->toDateString(),
            'status'        => 'Sent',
            'sent_at'       => now(),
        ], $attrs));
    }

    private function tokens(): OfferPortalToken
    {
        return app(OfferPortalToken::class);
    }

    private function offers(): OfferService
    {
        return app(OfferService::class);
    }

    /** An offer with a live credential, returning [offer, raw]. */
    private function live(?Tenant $t = null, array $attrs = []): array
    {
        $offer = $this->offer($t, $attrs);
        $raw   = $this->tokens()->issue($offer);

        return [$offer->fresh(), $raw];
    }

    /** A stored letter file, so the download routes have something to serve. */
    private function withLetter(HrOffer $offer): HrOffer
    {
        $path = "hr/documents/offers/tenant_{$offer->tenant_id}/offer_{$offer->id}.pdf";
        Storage::disk('hr_documents')->put($path, '%PDF-1.4 test');
        // Both, because the offer portal reads hr_documents and the careers
        // portal reads local. See the note in setUp().
        Storage::disk('local')->put($path, '%PDF-1.4 test');
        $offer->update(['letter_path' => $path]);

        return $offer->fresh();
    }

    /* ═══════════════════ 1. ISSUANCE ═══════════════════════════════════ */

    /** @test */
    public function issuing_returns_a_raw_token_that_opens_the_portal(): void
    {
        [, $raw] = $this->live();

        $this->assertSame(64, strlen($raw));
        $this->getJson('/api/offer/'.$raw)->assertOk();
    }

    /** @test */
    public function only_the_hash_is_persisted_and_the_raw_token_is_nowhere_in_the_row(): void
    {
        [$offer, $raw] = $this->live();

        $row = DB::table('hr_offers')->where('id', $offer->id)->first();

        $this->assertSame(hash('sha256', $raw), $row->token_hash);
        $this->assertNull($row->access_token, 'The legacy plaintext column must stay empty.');
        $this->assertStringNotContainsString($raw, json_encode((array) $row));
    }

    /** @test */
    public function the_raw_token_never_reaches_the_audit_trail(): void
    {
        [$offer, $raw] = $this->live();
        $this->tokens()->revoke($offer->fresh(), $this->hrUser(), 'leaked');

        $audits = DB::table('audit_logs')->get()->toJson();

        $this->assertStringNotContainsString($raw, $audits);
    }

    /**
     * Creating an offer mints NO credential.
     *
     * A deviation from the brief's test 1, and a deliberate one. create() used
     * to mint a token, which made sense when the plaintext could be read back
     * later to build the link. It cannot be now — the raw value would exist for
     * the length of the method and then be gone — so a token issued at creation
     * could never reach anybody. An offer gets its credential when it is first
     * DELIVERED, which is what the next test covers.
     *
     * @test
     */
    public function creating_an_offer_does_not_mint_a_credential(): void
    {
        $candidate = $this->candidate();
        HrOnboarding::create([
            'tenant_id' => $this->tenant->id, 'candidate_id' => $candidate->id,
            'candidate_name' => $candidate->name, 'position' => 'Analyst',
            'status' => 'Pending', 'verification_status' => 'Approved',
        ]);

        $offer = $this->offers()->create([
            'candidate_id' => $candidate->id, 'position' => 'Analyst',
            'department' => 'Operations', 'offered_ctc' => 900000, 'joining_date' => now()->addDays(30)->toDateString(),
            'validity_date' => now()->addDays(7)->toDateString(),
        ], $this->tenant->id);

        $row = DB::table('hr_offers')->where('id', $offer->id)->first();

        $this->assertNull($row->token_hash);
        $this->assertNull($row->access_token);
    }

    /** @test */
    public function the_first_send_issues_a_working_credential(): void
    {
        $offer = $this->offer(null, ['status' => 'Generated', 'sent_at' => null]);

        $this->assertNull($offer->token_hash);

        $this->offers()->send($offer);

        $this->assertNotNull($offer->fresh()->token_hash);
        $this->assertSame('Sent', $offer->fresh()->status);
    }

    /** @test */
    public function every_send_re_keys_and_the_previous_link_stops_working(): void
    {
        [$offer, $first] = $this->live();

        $this->getJson('/api/offer/'.$first)->assertOk();

        $this->offers()->send($offer->fresh());

        // The old one is dead …
        $this->getJson('/api/offer/'.$first)->assertStatus(404);

        // … and the row now holds a different hash.
        $this->assertNotSame(hash('sha256', $first), $offer->fresh()->token_hash);
    }

    /** @test */
    public function regenerate_kills_the_old_link_without_minting_an_unusable_one(): void
    {
        [$offer, $raw] = $this->live(null, ['status' => 'Expired']);

        $this->offers()->regenerate($offer->fresh(), now()->addDays(10)->toDateString());

        $this->getJson('/api/offer/'.$raw)->assertStatus(404);
        $this->assertNotNull($offer->fresh()->token_revoked_at);
    }

    /** @test */
    public function regenerate_keeps_its_other_side_effects(): void
    {
        [$offer, ] = $this->live(null, [
            'status' => 'Declined', 'declined_at' => now(), 'viewed_at' => now(),
            'accepted_ip' => '1.2.3.4',
        ]);

        $this->offers()->regenerate($offer->fresh(), now()->addDays(10)->toDateString());
        $fresh = $offer->fresh();

        $this->assertSame('Generated', $fresh->status);
        $this->assertSame(now()->addDays(10)->toDateString(), $fresh->validity_date->toDateString());
        $this->assertNull($fresh->declined_at);
        $this->assertNull($fresh->viewed_at);
        $this->assertNull($fresh->accepted_ip);
    }

    /**
     * Revising does NOT re-key — the documented choice.
     *
     * Keeping the credential is still technically possible under hashing,
     * because nothing needs to reconstruct the raw value. It is also correct:
     * revising drops the offer to Draft, so the candidate cannot act until HR
     * sends again, and that send re-keys.
     *
     * @test
     */
    public function revising_preserves_the_existing_credential(): void
    {
        [$offer, $raw] = $this->live();
        $before = $offer->token_hash;

        $this->offers()->revise($offer->fresh(), ['offered_ctc' => 950000], 'Budget moved', $this->hrUser());

        $this->assertSame($before, $offer->fresh()->token_hash);
        $this->assertSame(hash('sha256', $raw), $offer->fresh()->token_hash);
    }

    /**
     * Extending re-keys ONLY when the new link actually goes out.
     *
     * extend()'s WhatsApp is its only delivery. If the candidate has not opted
     * in, no message is sent — and re-keying anyway would kill the link they
     * are holding and replace it with one nobody was told about.
     *
     * @test
     */
    public function extending_does_not_re_key_when_nothing_is_delivered(): void
    {
        [$offer, $raw] = $this->live(null, ['status' => 'Expired']);
        $before = $offer->token_hash;

        $this->offers()->extend($offer->fresh(), now()->addDays(5)->toDateString());

        $this->assertSame($before, $offer->fresh()->token_hash, 'No delivery means no re-key.');
        $this->getJson('/api/offer/'.$raw)->assertOk();
    }

    /** @test */
    public function extending_re_keys_when_the_new_link_is_delivered(): void
    {
        $candidate = $this->candidate($this->tenant, whatsApp: true);
        $offer     = $this->offer(null, ['candidate_id' => $candidate->id, 'status' => 'Expired']);
        $raw       = $this->tokens()->issue($offer);
        $before    = $offer->fresh()->token_hash;

        $this->offers()->extend($offer->fresh(), now()->addDays(5)->toDateString());

        $this->assertNotSame($before, $offer->fresh()->token_hash);
        $this->getJson('/api/offer/'.$raw)->assertStatus(404);
    }

    /* ═══════════════════ 2. RESOLUTION ════════════════════════════════ */

    /** @test */
    public function an_unknown_or_malformed_token_is_refused_identically(): void
    {
        foreach ([Str::random(64), Str::random(48), 'short', '', Str::random(64).'!'] as $bad) {
            $this->getJson('/api/offer/'.urlencode($bad) ?: '/api/offer/x')
                ->assertStatus(404);
        }
    }

    /** @test */
    public function a_revoked_token_stops_resolving(): void
    {
        [$offer, $raw] = $this->live();

        $this->tokens()->revoke($offer->fresh(), $this->hrUser());

        $this->getJson('/api/offer/'.$raw)->assertStatus(404);
    }

    /**
     * Issuing after a revocation produces a link that works.
     *
     * A fresh credential is live by definition, so the earlier revocation has
     * to be cleared in the same write — otherwise revoking a link once would
     * quietly kill every replacement for it, and HR would have no way back.
     *
     * @test
     */
    public function a_link_issued_after_a_revocation_works(): void
    {
        [$offer, $old] = $this->live();

        $this->tokens()->revoke($offer->fresh(), $this->hrUser(), 'leaked');
        $this->getJson('/api/offer/'.$old)->assertStatus(404);

        $new = $this->tokens()->issue($offer->fresh());

        $this->getJson('/api/offer/'.$new)->assertOk();
        $this->assertNull($offer->fresh()->token_revoked_at);
    }

    /** @test */
    public function a_link_re_sent_after_a_revocation_works(): void
    {
        [$offer, ] = $this->live();
        $this->tokens()->revoke($offer->fresh(), $this->hrUser());

        $this->offers()->send($offer->fresh());

        $this->assertNull($offer->fresh()->token_revoked_at);
        $this->assertTrue($this->tokens()->isLive($offer->fresh()));
    }

    /**
     * Mass assignment cannot put a plaintext token back.
     *
     * The column still exists — emptying it was safer than dropping it on a
     * live table — so the thing that keeps it empty is that no write path can
     * reach it. OfferPortalToken uses forceFill; everything else is blocked
     * here, and this test is what says so.
     *
     * @test
     */
    public function a_plaintext_token_cannot_be_mass_assigned_onto_an_offer(): void
    {
        $offer = $this->offer();

        $offer->update(['access_token' => 'plaintext-sneaking-back', 'position' => 'Senior Analyst']);

        $row = DB::table('hr_offers')->where('id', $offer->id)->first();

        $this->assertNull($row->access_token, 'access_token must not be mass-assignable.');
        $this->assertSame('Senior Analyst', $row->position, 'The rest of the update must still apply.');
    }

    /** @test */
    public function legacy_48_character_tokens_keep_working_after_the_migration(): void
    {
        // Exactly the shape the migration leaves behind: plaintext emptied, the
        // hash of the token already in the candidate's inbox stored instead.
        $offer  = $this->offer();
        $legacy = Str::random(48);
        DB::table('hr_offers')->where('id', $offer->id)->update([
            'access_token'    => null,
            'token_hash'      => hash('sha256', $legacy),
            'token_issued_at' => now()->subDays(3),
        ]);

        $this->getJson('/api/offer/'.$legacy)->assertOk();
    }

    /** @test */
    public function no_direct_plaintext_lookup_survives_anywhere_in_the_app(): void
    {
        $hits = [];
        $rii  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($rii as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            // Tokenised rather than grepped, so the class docblocks that
            // DESCRIBE the old lookup are not mistaken for the lookup itself.
            $code = '';
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }

            if (preg_match("/where\\(\\s*'access_token'/", $code)) {
                $hits[] = $file->getPathname();
            }
        }

        $this->assertSame([], $hits, 'A plaintext access_token lookup is still in production code.');
    }

    /* ═══════════ 3. EVERY PUBLIC ROUTE USES THE ONE RESOLVER ═══════════ */

    /**
     * All six public routes, driven with a token that has been superseded.
     *
     * Protecting the landing page proves nothing about the accept route, which
     * is the one that signs something.
     *
     * @test
     */
    public function every_public_offer_route_rejects_a_re_keyed_token(): void
    {
        [$offer, $old] = $this->live();
        $this->withLetter($offer);
        $this->offers()->send($offer->fresh());   // re-keys

        $this->getJson("/api/offer/{$old}")->assertStatus(404);
        $this->get("/api/offer/{$old}/letter")->assertStatus(404);
        $this->postJson("/api/offer/{$old}/accept", ['agreed' => true, 'full_name' => 'X'])->assertStatus(404);
        $this->postJson("/api/offer/{$old}/decline", ['reason' => 'no'])->assertStatus(404);
        $this->postJson("/api/offer/{$old}/clarify", ['message' => 'hm'])->assertStatus(404);
        $this->postJson("/api/offer/{$old}/tasks", ['key' => 'nda', 'value' => 'ok'])->assertStatus(404);
    }

    /** @test */
    public function every_public_offer_route_works_with_the_current_token(): void
    {
        [$offer, $raw] = $this->live();
        $this->withLetter($offer);

        $this->getJson("/api/offer/{$raw}")->assertOk();
        $this->get("/api/offer/{$raw}/letter")->assertOk();
        $this->postJson("/api/offer/{$raw}/clarify", ['message' => 'When do I start?'])->assertOk();
        $this->postJson("/api/offer/{$raw}/accept", ['agreed' => true, 'full_name' => 'Asha'])->assertOk();
        $this->postJson("/api/offer/{$raw}/tasks", ['key' => 'nda', 'value' => 'agreed'])->assertOk();
    }

    /** @test */
    public function an_offer_id_cannot_be_substituted_for_a_token(): void
    {
        [$offer, ] = $this->live();

        $this->getJson('/api/offer/'.$offer->id)->assertStatus(404);
    }

    /** @test */
    public function a_token_never_reaches_another_tenants_offer(): void
    {
        [$mine, $mineRaw]   = $this->live($this->tenant);
        [$theirs, $theirRaw] = $this->live($this->other);

        $this->getJson('/api/offer/'.$mineRaw)->assertOk()
            ->assertJsonPath('reference', 'OFF-'.str_pad((string) $mine->id, 4, '0', STR_PAD_LEFT));

        $this->getJson('/api/offer/'.$theirRaw)->assertOk()
            ->assertJsonPath('reference', 'OFF-'.str_pad((string) $theirs->id, 4, '0', STR_PAD_LEFT));
    }

    /* ═══════════ 4. VALIDITY / STATE — ALL UNCHANGED ══════════════════ */

    /** @test */
    public function an_offer_inside_its_validity_can_still_be_responded_to(): void
    {
        [, $raw] = $this->live(null, ['validity_date' => now()->addDays(3)->toDateString()]);

        $this->getJson('/api/offer/'.$raw)->assertOk()
            ->assertJsonPath('can_respond', true)
            ->assertJsonPath('is_expired', false);
    }

    /** @test */
    public function a_past_validity_date_still_expires_the_offer_on_read(): void
    {
        [$offer, $raw] = $this->live(null, ['validity_date' => now()->subDay()->toDateString()]);

        $this->getJson('/api/offer/'.$raw)->assertOk()
            ->assertJsonPath('status', 'Expired')
            ->assertJsonPath('is_expired', true)
            ->assertJsonPath('can_respond', false);

        $this->assertSame('Expired', $offer->fresh()->status);
    }

    /**
     * An EXPIRED OFFER STILL OPENS ITS LINK. The credential and the commercial
     * deadline are different things and this is where that shows.
     *
     * @test
     */
    public function the_link_to_an_expired_offer_is_still_live(): void
    {
        [$offer, $raw] = $this->live(null, ['validity_date' => now()->subDay()->toDateString()]);

        $this->getJson('/api/offer/'.$raw)->assertOk();
        $this->assertTrue($this->tokens()->isLive($offer->fresh()));
    }

    /**
     * A null validity_date leaves the offer usable indefinitely — reported as a
     * separate backlog item and DELIBERATELY NOT CHANGED here. This test pins
     * that behaviour so the token work cannot alter it by accident.
     *
     * @test
     */
    public function a_null_validity_date_behaves_exactly_as_before(): void
    {
        [, $raw] = $this->live(null, ['validity_date' => null]);

        $this->getJson('/api/offer/'.$raw)->assertOk()
            ->assertJsonPath('is_expired', false)
            ->assertJsonPath('can_respond', true)
            ->assertJsonPath('valid_until', null);
    }

    /** @test */
    public function accepted_declined_withdrawn_and_completed_offers_all_still_open(): void
    {
        $cases = [
            ['Accepted',  'accepted_at'],
            ['Declined',  'declined_at'],
            ['Withdrawn', 'withdrawn_at'],
            ['Completed', 'joining_confirmed_at'],
        ];

        foreach ($cases as [$status, $stamp]) {
            [, $raw] = $this->live(null, ['status' => $status, $stamp => now()]);

            $this->getJson('/api/offer/'.$raw)
                ->assertOk()
                ->assertJsonPath('status', $status)
                ->assertJsonPath('can_respond', false);
        }
    }

    /** @test */
    public function a_withdrawn_offer_still_shows_its_reason(): void
    {
        [, $raw] = $this->live(null, [
            'status' => 'Withdrawn', 'withdrawn_at' => now(), 'withdraw_reason' => 'Role closed',
        ]);

        $this->getJson('/api/offer/'.$raw)->assertOk()
            ->assertJsonPath('is_withdrawn', true)
            ->assertJsonPath('withdraw_reason', 'Role closed');
    }

    /** @test */
    public function accepting_an_expired_offer_is_still_refused_by_the_state_machine(): void
    {
        [, $raw] = $this->live(null, ['validity_date' => now()->subDay()->toDateString()]);

        // The read expires it, then the write is refused on status — the same
        // 422 as before, not a token failure.
        $this->getJson('/api/offer/'.$raw)->assertOk();
        $this->postJson("/api/offer/{$raw}/accept", ['agreed' => true, 'full_name' => 'Asha'])
            ->assertStatus(422);
    }

    /* ═══════════════════ 5. EXPOSURE ══════════════════════════════════ */

    /** @test */
    public function the_hr_offer_list_and_show_expose_neither_the_token_nor_its_hash(): void
    {
        [$offer, $raw] = $this->live();
        Sanctum::actingAs($this->hrUser());

        foreach (['/api/hr/offers', '/api/hr/offers/'.$offer->id] as $url) {
            $body = $this->getJson($url)->assertOk()->getContent();

            $this->assertStringNotContainsString($raw, $body);
            $this->assertStringNotContainsString($offer->fresh()->token_hash, $body);
            $this->assertStringNotContainsString('access_token', $body);
            $this->assertStringNotContainsString('token_hash', $body);
        }
    }

    /** @test */
    public function the_write_endpoints_expose_neither_the_token_nor_its_hash(): void
    {
        [$offer, ] = $this->live(null, ['status' => 'Sent']);
        Sanctum::actingAs($this->hrUser());

        $calls = [
            fn () => $this->patchJson("/api/hr/offers/{$offer->id}/send"),
            fn () => $this->patchJson("/api/hr/offers/{$offer->id}/extend", ['validity_date' => now()->addDays(9)->toDateString()]),
            fn () => $this->patchJson("/api/hr/offers/{$offer->id}/withdraw", ['reason' => 'Closed']),
        ];

        foreach ($calls as $call) {
            $body = $call()->getContent();
            $this->assertStringNotContainsString('access_token', $body);
            $this->assertStringNotContainsString('token_hash', $body);
            $this->assertStringNotContainsString((string) $offer->fresh()->token_hash, $body);
        }
    }

    /** @test */
    public function the_employee_profile_no_longer_carries_the_offer_token(): void
    {
        [$offer, $raw] = $this->live();
        $offer->update(['status' => 'Accepted', 'accepted_at' => now()]);

        $employee = \App\Models\Hr\HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'candidate_id' => $offer->candidate_id,
            'name' => 'Asha', 'employee_code' => 'E'.substr(uniqid(), -5),
            'department' => 'Operations', 'designation' => 'Analyst',
            'email' => uniqid().'@emp.test', 'status' => 'Active',
            'joining_date' => now()->toDateString(),
        ]);

        Sanctum::actingAs($this->hrUser());
        $body = $this->getJson('/api/hr/employees/'.$employee->id.'/profile')->getContent();

        $this->assertStringNotContainsString($raw, $body);
        $this->assertStringNotContainsString('access_token', $body);
    }

    /** @test */
    public function the_candidate_record_no_longer_carries_the_offer_token(): void
    {
        [$offer, $raw] = $this->live();

        Sanctum::actingAs($this->hrUser());
        $body = $this->getJson('/api/hr/candidates/'.$offer->candidate_id)->getContent();

        $this->assertStringNotContainsString($raw, $body);
        $this->assertStringNotContainsString('access_token', $body);
        $this->assertStringNotContainsString($offer->fresh()->token_hash, $body);
    }

    /** @test */
    public function the_onboarding_dashboard_no_longer_hands_over_the_offer_token(): void
    {
        $candidate  = $this->candidate();
        $onboarding = HrOnboarding::create([
            'tenant_id' => $this->tenant->id, 'candidate_id' => $candidate->id,
            'candidate_name' => $candidate->name, 'position' => 'Analyst',
            'status' => 'Pending', 'verification_status' => 'Pending', 'invited_at' => now(),
        ]);
        $offer = $this->offer(null, ['candidate_id' => $candidate->id]);
        $raw   = $this->tokens()->issue($offer);

        $onboardingRaw = app(OnboardingPortalToken::class)->issue($onboarding);

        $body = $this->getJson('/api/onboarding/'.$onboardingRaw)->assertOk()->getContent();

        $this->assertStringNotContainsString($raw, $body);
        $this->assertStringNotContainsString($offer->fresh()->token_hash, $body);
        // The tab still knows the offer exists — only the credential is gone.
        $this->assertStringContainsString('"exists":true', $body);
    }

    /** @test */
    public function the_raw_token_is_returned_only_by_the_issuing_endpoint(): void
    {
        [$offer, ] = $this->live();
        Sanctum::actingAs($this->hrUser());

        $res = $this->postJson("/api/hr/offers/{$offer->id}/portal-link")->assertStatus(201);

        $link = $res->json('data.link');
        $this->assertStringContainsString('/offer/', $link);

        // And it opens the portal exactly once issued.
        $raw = substr($link, strrpos($link, '/') + 1);
        $this->getJson('/api/offer/'.$raw)->assertOk();

        // A later ordinary read gives nothing back.
        $body = $this->getJson('/api/hr/offers/'.$offer->id)->getContent();
        $this->assertStringNotContainsString($raw, $body);
    }

    /** @test */
    public function issuing_a_link_supersedes_the_previous_one(): void
    {
        [$offer, $old] = $this->live();
        Sanctum::actingAs($this->hrUser());

        $this->postJson("/api/hr/offers/{$offer->id}/portal-link")->assertStatus(201);

        $this->getJson('/api/offer/'.$old)->assertStatus(404);
    }

    /** @test */
    public function revoking_kills_the_link_without_touching_the_offer(): void
    {
        [$offer, $raw] = $this->live(null, ['status' => 'Viewed']);
        Sanctum::actingAs($this->hrUser());

        $this->deleteJson("/api/hr/offers/{$offer->id}/portal-link", ['reason' => 'Forwarded in error'])
            ->assertOk();

        $this->getJson('/api/offer/'.$raw)->assertStatus(404);

        $fresh = $offer->fresh();
        $this->assertSame('Viewed', $fresh->status, 'Revoking a link must not change the offer.');
        $this->assertNull($fresh->withdrawn_at);
        $this->assertNotNull($fresh->validity_date);
    }

    /* ═══════════════════ 6. HR LETTER ACCESS ══════════════════════════ */

    /** @test */
    public function hr_can_download_the_offer_letter_without_the_candidates_token(): void
    {
        [$offer, ] = $this->live();
        $this->withLetter($offer);

        Sanctum::actingAs($this->hrUser());

        $this->get("/api/hr/offers/{$offer->id}/letter")->assertOk();
    }

    /** @test */
    public function a_user_without_hr_rights_cannot_download_an_offer_letter(): void
    {
        [$offer, ] = $this->live();
        $this->withLetter($offer);

        Sanctum::actingAs($this->plainUser());

        $this->get("/api/hr/offers/{$offer->id}/letter")->assertStatus(403);
    }

    /** @test */
    public function hr_cannot_download_another_tenants_offer_letter(): void
    {
        [$offer, ] = $this->live($this->other);
        $this->withLetter($offer);

        Sanctum::actingAs($this->hrUser($this->tenant));

        $this->get("/api/hr/offers/{$offer->id}/letter")->assertStatus(404);
    }

    /** @test */
    public function the_hr_letter_route_does_not_mark_the_offer_viewed(): void
    {
        [$offer, ] = $this->live(null, ['status' => 'Sent']);
        $this->withLetter($offer);

        Sanctum::actingAs($this->hrUser());
        $this->get("/api/hr/offers/{$offer->id}/letter")->assertOk();

        $this->assertSame('Sent', $offer->fresh()->status);
        $this->assertNull($offer->fresh()->viewed_at);
    }

    /** @test */
    public function portal_link_endpoints_are_tenant_scoped_and_permission_gated(): void
    {
        [$mine, ] = $this->live($this->tenant);
        [$theirs, ] = $this->live($this->other);

        Sanctum::actingAs($this->plainUser($this->tenant));
        $this->postJson("/api/hr/offers/{$mine->id}/portal-link")->assertStatus(403);

        Sanctum::actingAs($this->hrUser($this->tenant));
        $this->postJson("/api/hr/offers/{$theirs->id}/portal-link")->assertStatus(404);
    }

    /* ═══════════ 7. THE ONBOARDING-EMBEDDED OFFER TAB ═════════════════ */

    /** Onboarding + offer for one candidate, returning [onboardingRaw, offer]. */
    private function embedded(?Tenant $t = null): array
    {
        $t         = $t ?: $this->tenant;
        $candidate = $this->candidate($t);

        $onboarding = HrOnboarding::create([
            'tenant_id' => $t->id, 'candidate_id' => $candidate->id,
            'candidate_name' => $candidate->name, 'position' => 'Analyst',
            'status' => 'Pending', 'verification_status' => 'Pending', 'invited_at' => now(),
        ]);

        $offer = $this->offer($t, ['candidate_id' => $candidate->id]);

        return [app(OnboardingPortalToken::class)->issue($onboarding), $offer];
    }

    /** @test */
    public function the_onboarding_token_opens_the_candidates_own_offer(): void
    {
        [$obRaw, $offer] = $this->embedded();

        $this->getJson('/api/onboarding/'.$obRaw.'/offer')
            ->assertOk()
            ->assertJsonPath('reference', 'OFF-'.str_pad((string) $offer->id, 4, '0', STR_PAD_LEFT));
    }

    /** @test */
    public function an_invalid_onboarding_token_cannot_open_an_offer(): void
    {
        $this->embedded();

        $this->getJson('/api/onboarding/'.Str::random(64).'/offer')->assertStatus(404);
    }

    /**
     * The other candidate's offer is created FIRST on purpose.
     *
     * An adapter that reached "an offer" rather than "this candidate's offer"
     * would hand back the earliest row, and a test whose own fixture happened
     * to be first would sail straight past that. So the decoy is seeded ahead
     * of the real one and the reference is asserted exactly.
     *
     * @test
     */
    public function an_onboarding_token_cannot_reach_another_candidates_offer(): void
    {
        [, $theirOffer]      = $this->embedded();
        [$mineRaw, $myOffer] = $this->embedded();

        $this->assertLessThan($myOffer->id, $theirOffer->id);

        $this->getJson('/api/onboarding/'.$mineRaw.'/offer')
            ->assertOk()
            ->assertJsonPath('reference', 'OFF-'.str_pad((string) $myOffer->id, 4, '0', STR_PAD_LEFT));
    }

    /** @test */
    public function a_revoked_onboarding_token_cannot_open_the_offer_tab(): void
    {
        [$obRaw, ] = $this->embedded();

        $onboarding = HrOnboarding::whereNotNull('token_hash')->firstOrFail();
        app(OnboardingPortalToken::class)->revoke($onboarding);

        $this->getJson('/api/onboarding/'.$obRaw.'/offer')->assertStatus(404);
    }

    /** @test */
    public function the_embedded_tab_delegates_to_the_same_offer_operations(): void
    {
        [$obRaw, $offer] = $this->embedded();
        $this->withLetter($offer);

        $this->get('/api/onboarding/'.$obRaw.'/offer/letter')->assertOk();
        $this->postJson('/api/onboarding/'.$obRaw.'/offer/clarify', ['message' => 'Start date?'])->assertOk();
        $this->postJson('/api/onboarding/'.$obRaw.'/offer/accept', ['agreed' => true, 'full_name' => 'Asha'])->assertOk();

        $fresh = $offer->fresh();
        $this->assertSame('Accepted', $fresh->status);
        $this->assertSame('Asha', $fresh->accepted_name);
        $this->assertSame('Start date?', $fresh->clarification);
    }

    /** @test */
    public function the_embedded_tab_obeys_the_same_state_machine(): void
    {
        [$obRaw, $offer] = $this->embedded();
        $offer->update(['status' => 'Declined', 'declined_at' => now()]);

        $this->postJson('/api/onboarding/'.$obRaw.'/offer/accept', ['agreed' => true, 'full_name' => 'Asha'])
            ->assertStatus(422);
    }

    /** @test */
    public function the_embedded_tab_never_returns_an_offer_token(): void
    {
        [$obRaw, $offer] = $this->embedded();
        $raw = $this->tokens()->issue($offer);

        $body = $this->getJson('/api/onboarding/'.$obRaw.'/offer')->assertOk()->getContent();

        $this->assertStringNotContainsString($raw, $body);
        $this->assertStringNotContainsString($offer->fresh()->token_hash, $body);
    }

    /* ═══════════════════ 8. CAREERS PORTAL ════════════════════════════ */

    /** A job + application whose candidate has the offer, returning [jobId, email, offer]. */
    private function careersFixture(): array
    {
        $job = \App\Models\Hr\HrJobPosting::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Analyst',
            'department' => 'Operations', 'location' => 'Pune',
            'status' => 'Published', 'job_type' => 'Full-time',
            'description' => 'x', 'vacancies' => 1,
        ]);

        $candidate = $this->candidate();
        $candidate->update(['job_posting_id' => $job->id]);

        $offer = $this->offer(null, ['candidate_id' => $candidate->id, 'status' => 'Sent']);

        return [$job->id, $candidate->email, $offer];
    }

    /** @test */
    public function careers_still_requires_the_offer_token_beside_the_email(): void
    {
        [$jobId, $email, $offer] = $this->careersFixture();
        $this->withLetter($offer);
        $this->tokens()->issue($offer);

        // Email + job alone is still not enough — the same 403 as before.
        $this->getJson("/api/careers/opt/jobs/{$jobId}/offer/letter?email={$email}&token=".Str::random(64))
            ->assertStatus(403);
    }

    /** @test */
    public function careers_accepts_the_current_offer_token(): void
    {
        [$jobId, $email, $offer] = $this->careersFixture();
        $this->withLetter($offer);
        $raw = $this->tokens()->issue($offer);

        $this->get("/api/careers/opt/jobs/{$jobId}/offer/letter?email={$email}&token={$raw}")
            ->assertOk();
    }

    /** @test */
    public function careers_rejects_a_token_belonging_to_a_different_offer(): void
    {
        [$jobId, $email, $offer] = $this->careersFixture();
        $this->withLetter($offer);
        $this->tokens()->issue($offer);

        // A perfectly valid credential — for somebody else's offer.
        [, $otherRaw] = $this->live();

        $this->get("/api/careers/opt/jobs/{$jobId}/offer/letter?email={$email}&token={$otherRaw}")
            ->assertStatus(403);
    }

    /** @test */
    public function careers_rejects_a_re_keyed_token(): void
    {
        [$jobId, $email, $offer] = $this->careersFixture();
        $this->withLetter($offer);
        $old = $this->tokens()->issue($offer);
        $this->tokens()->issue($offer->fresh());   // re-key

        $this->get("/api/careers/opt/jobs/{$jobId}/offer/letter?email={$email}&token={$old}")
            ->assertStatus(403);
    }

    /* ═══════════════════ 9. MIGRATION ═════════════════════════════════ */

    /**
     * The migration converts in place: a link already in a candidate's inbox
     * still works afterwards, because its hash is derivable from the plaintext
     * that was stored.
     *
     * @test
     */
    public function the_migration_preserves_every_existing_active_link(): void
    {
        $offer  = $this->offer();
        $legacy = Str::random(48);

        // Wind the row back to its pre-migration state.
        DB::table('hr_offers')->where('id', $offer->id)->update([
            'access_token' => $legacy, 'token_hash' => null, 'token_issued_at' => null,
        ]);

        $this->runOfferTokenMigration();

        $row = DB::table('hr_offers')->where('id', $offer->id)->first();
        $this->assertNull($row->access_token, 'Plaintext must be gone.');
        $this->assertSame(hash('sha256', $legacy), $row->token_hash);

        $this->getJson('/api/offer/'.$legacy)->assertOk();
    }

    /** @test */
    public function the_migration_leaves_expired_offers_expired(): void
    {
        $offer  = $this->offer(null, ['status' => 'Expired', 'validity_date' => now()->subDays(5)->toDateString(), 'expired_at' => now()->subDays(4)]);
        $legacy = Str::random(48);
        DB::table('hr_offers')->where('id', $offer->id)->update([
            'access_token' => $legacy, 'token_hash' => null,
        ]);

        $this->runOfferTokenMigration();

        $this->assertSame('Expired', $offer->fresh()->status);
        $this->getJson('/api/offer/'.$legacy)->assertOk()->assertJsonPath('is_expired', true);
    }

    /** @test */
    public function the_migration_is_idempotent(): void
    {
        $offer  = $this->offer();
        $legacy = Str::random(48);
        DB::table('hr_offers')->where('id', $offer->id)->update(['access_token' => $legacy, 'token_hash' => null]);

        $this->runOfferTokenMigration();
        $after = DB::table('hr_offers')->where('id', $offer->id)->first();

        $this->runOfferTokenMigration();
        $again = DB::table('hr_offers')->where('id', $offer->id)->first();

        $this->assertEquals($after->token_hash, $again->token_hash);
        $this->assertNull($again->access_token);
        $this->getJson('/api/offer/'.$legacy)->assertOk();
    }

    /** @test */
    public function newly_issued_tokens_never_write_plaintext(): void
    {
        [$offer, ] = $this->live();
        $this->offers()->send($offer->fresh());
        $this->tokens()->issue($offer->fresh());

        $this->assertSame(
            0,
            DB::table('hr_offers')->whereNotNull('access_token')->count(),
            'Something is still writing a plaintext token.'
        );
    }

    /** Re-run just this migration's backfill against the current schema. */
    private function runOfferTokenMigration(): void
    {
        $migration = require database_path('migrations/2026_09_30_000001_harden_hr_offer_portal_token.php');
        $migration->up();
    }
}
