<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;
use App\Models\Hr\HrOffer;
use App\Models\Tenant;
use App\Services\Hr\OfferPortalToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Careers portal's offer surface: a tracker, not a control panel.
 *
 * Two things were wrong here and they failed in opposite directions.
 *
 *   THE TRACKER HID THE OFFER. publicOffer() only returned a summary for
 *   ['Sent', 'Accepted', 'Rejected']. 'Rejected' is not an offer status at all
 *   — declining writes 'Declined' — and 'Viewed' was missing, which is the
 *   status an offer takes the instant the candidate opens their emailed link.
 *   So on the most ordinary path in the product, reading your offer made it
 *   disappear from the page that tracks it.
 *
 *   THE LETTER COULD NEVER BE FOUND. The download probed the `local` and
 *   `public` disks while every offer PDF has only ever been written to
 *   hr_documents, one directory deeper than either probe looked. Careers 404'd
 *   for every offer, as though the document did not exist.
 *
 * What was NOT wrong, and is pinned below so it stays that way: Careers has no
 * accept/decline controls, the respond endpoint still refuses anything but
 * 'Sent', the offer token is still required beside the email, and neither the
 * token nor its hash nor a storage path appears in any response.
 */
class CareerPortalOfferTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();
        // ONE disk, on purpose. The whole point of the storage fix is that
        // Careers reads where OfferService writes; faking local/public as well
        // would let a regression hide behind a second copy of the file.
        Storage::fake('hr_documents');

        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'cpo', 'status' => 'active']);
        $this->other  = Tenant::create(['name' => 'Two', 'slug' => 'cpo2', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function job(?Tenant $t = null): HrJobPosting
    {
        $t = $t ?: $this->tenant;

        return HrJobPosting::create([
            'tenant_id' => $t->id, 'title' => 'Analyst',
            'department' => 'Operations', 'location' => 'Pune',
            'status' => 'Published', 'job_type' => 'Full-time',
            'description' => 'x', 'vacancies' => 1,
        ]);
    }

    /**
     * A job, an applicant and their offer, wired the way the portal reaches
     * them: tenant → job → candidate (by email) → offer.
     *
     * @return array{0: Tenant, 1: HrJobPosting, 2: HrCandidate, 3: HrOffer}
     */
    private function application(?Tenant $t = null, array $offerAttrs = []): array
    {
        $t   = $t ?: $this->tenant;
        $job = $this->job($t);

        $candidate = HrCandidate::create([
            'tenant_id' => $t->id, 'name' => 'C'.substr(uniqid(), -5),
            'email' => uniqid().'@cand.test', 'phone' => '9000000000',
            'whatsapp_opt_in' => false,
            'job_posting_id' => $job->id,
            'stage' => 'Offer', 'status' => 'Active',
        ]);

        $offer = HrOffer::create(array_merge([
            'tenant_id'     => $t->id,
            'candidate_id'  => $candidate->id,
            'position'      => 'Analyst',
            'department'    => 'Operations',
            'offered_ctc'   => 900000,
            'joining_date'  => now()->addDays(30)->toDateString(),
            'validity_date' => now()->addDays(7)->toDateString(),
            'status'        => 'Sent',
            'sent_at'       => now(),
        ], $offerAttrs));

        return [$t, $job, $candidate, $offer];
    }

    private function tokens(): OfferPortalToken
    {
        return app(OfferPortalToken::class);
    }

    /** The stored letter, written exactly where OfferService writes it. */
    private function withLetter(HrOffer $offer): HrOffer
    {
        $path = "hr/documents/offers/tenant_{$offer->tenant_id}/offer_{$offer->id}.pdf";
        Storage::disk('hr_documents')->put($path, '%PDF-1.4 test');
        $offer->update(['letter_path' => $path]);

        return $offer->fresh();
    }

    private function track(Tenant $t, int $jobId, string $email)
    {
        return $this->postJson("/api/careers/{$t->slug}/jobs/{$jobId}/status", ['email' => $email]);
    }

    private function letter(Tenant $t, int $jobId, string $email, ?string $token)
    {
        return $this->get("/api/careers/{$t->slug}/jobs/{$jobId}/offer/letter?email="
            .urlencode($email).'&token='.urlencode((string) $token));
    }

    private function respond(Tenant $t, int $jobId, array $payload)
    {
        return $this->postJson("/api/careers/{$t->slug}/jobs/{$jobId}/offer/respond", $payload);
    }

    /* ═══════════ 1. THE TRACKER SHOWS THE WHOLE LIFECYCLE ═════════════ */

    /**
     * Every status the candidate has been told about keeps its summary.
     *
     * 'Viewed' is the one that matters most — it is what the offer becomes the
     * moment they open their link — but all seven are driven, because the old
     * whitelist dropped five of them.
     *
     * @test
     */
    public function the_tracker_returns_the_offer_for_every_informed_status(): void
    {
        foreach (['Sent', 'Viewed', 'Accepted', 'Declined', 'Expired', 'Withdrawn', 'Completed'] as $status) {
            [$t, $job, $candidate, ] = $this->application(null, ['status' => $status]);

            $this->track($t, $job->id, $candidate->email)
                ->assertOk()
                ->assertJsonPath('applied', true)
                ->assertJsonPath('offer.status', $status)
                ->assertJsonPath('offer.position', 'Analyst');
        }
    }

    /**
     * Nothing before Sent: an internal draft, or one still waiting on approval,
     * is not the candidate's business yet.
     *
     * @test
     */
    public function the_tracker_hides_an_offer_the_candidate_has_not_been_sent(): void
    {
        foreach (['Draft', 'Pending Approval', 'Approved', 'Generated'] as $status) {
            [$t, $job, $candidate, ] = $this->application(null, ['status' => $status, 'sent_at' => null]);

            $this->track($t, $job->id, $candidate->email)
                ->assertOk()
                ->assertJsonPath('applied', true)
                ->assertJsonPath('offer', null);
        }
    }

    /**
     * 'Rejected' is not an offer status and is not treated as one.
     *
     * It was in the old whitelist and matched nothing, because declining writes
     * 'Declined' — 'Rejected' is a candidate DECISION value. Both halves are
     * asserted: a row forced to that string is not recognised, and a real
     * decline produces 'Declined'.
     *
     * @test
     */
    public function the_obsolete_rejected_status_is_not_an_offer_status(): void
    {
        [$t, $job, $candidate, $offer] = $this->application();
        $offer->update(['status' => 'Rejected']);

        $this->track($t, $job->id, $candidate->email)
            ->assertOk()
            ->assertJsonPath('offer', null);

        // And the status a decline actually produces:
        [$t2, $job2, $cand2, $offer2] = $this->application();
        $raw = $this->tokens()->issue($offer2);

        $this->respond($t2, $job2->id, ['email' => $cand2->email, 'action' => 'decline', 'token' => $raw])
            ->assertOk();

        $this->assertSame('Declined', $offer2->fresh()->status);
    }

    /* ═══════════ 2. THE TRACKER LEAKS NOTHING ═════════════════════════ */

    /** @test */
    public function the_tracker_exposes_no_token_no_hash_and_no_storage_path(): void
    {
        [$t, $job, $candidate, $offer] = $this->application();
        $raw = $this->tokens()->issue($offer);
        $this->withLetter($offer);

        $body = $this->track($t, $job->id, $candidate->email)->assertOk()->getContent();

        $this->assertStringNotContainsString($raw, $body);
        $this->assertStringNotContainsString($offer->fresh()->token_hash, $body);
        $this->assertStringNotContainsString('access_token', $body);
        $this->assertStringNotContainsString('token_hash', $body);
        $this->assertStringNotContainsString('letter_path', $body);
        $this->assertStringNotContainsString('hr/documents', $body);
        $this->assertStringNotContainsString(storage_path(), $body);
    }

    /* ═══════════ 3. can_respond / can_download TELL THE TRUTH ═════════ */

    /**
     * can_download follows the FILE, not the column.
     *
     * It used to read `! empty($offer->letter_path)`, which was true for every
     * generated offer while the download itself 404'd — the tracker advertised
     * a document it could not produce.
     *
     * @test
     */
    public function can_download_is_false_when_the_file_is_not_really_there(): void
    {
        [$t, $job, $candidate, $offer] = $this->application();

        // A path recorded, but no file behind it.
        $offer->update(['letter_path' => 'hr/documents/offers/tenant_1/offer_999.pdf']);

        $this->track($t, $job->id, $candidate->email)
            ->assertOk()
            ->assertJsonPath('offer.can_download', false);

        $this->withLetter($offer);

        $this->track($t, $job->id, $candidate->email)
            ->assertOk()
            ->assertJsonPath('offer.can_download', true);
    }

    /**
     * can_respond mirrors the respond ENDPOINT's guard, which stops at 'Sent'.
     *
     * Deliberately not widened to 'Viewed' to match the offer portal: that
     * endpoint's behaviour is not being changed here, so the flag that reports
     * it must not drift away from it.
     *
     * @test
     */
    public function can_respond_mirrors_the_respond_endpoints_own_guard(): void
    {
        [$t, $job, $candidate, $offer] = $this->application();

        $this->track($t, $job->id, $candidate->email)->assertJsonPath('offer.can_respond', true);

        $offer->update(['status' => 'Viewed', 'viewed_at' => now()]);

        $this->track($t, $job->id, $candidate->email)->assertJsonPath('offer.can_respond', false);
    }

    /* ═══════════ 4. THE OFFER LETTER ═════════════════════════════════ */

    /**
     * The regression itself: a letter HR generated is retrievable through
     * Careers, from the disk OfferService actually wrote it to.
     *
     * @test
     */
    public function the_letter_hr_generated_downloads_through_careers(): void
    {
        [$t, $job, $candidate, $offer] = $this->application();
        $this->withLetter($offer);
        $raw = $this->tokens()->issue($offer);

        $res = $this->letter($t, $job->id, $candidate->email, $raw)->assertOk();

        $this->assertStringContainsString(
            'Offer-Letter-'.$offer->id.'.pdf',
            $res->headers->get('content-disposition'),
            'The download must come from the shared offerLetterFile() helper.'
        );
    }

    /**
     * It works for every informed status, not only while the offer is open.
     *
     * @test
     */
    public function the_letter_downloads_after_the_candidate_has_viewed_or_accepted(): void
    {
        foreach (['Viewed', 'Accepted', 'Declined', 'Completed'] as $status) {
            [$t, $job, $candidate, $offer] = $this->application(null, ['status' => $status]);
            $this->withLetter($offer);
            $raw = $this->tokens()->issue($offer);

            $this->letter($t, $job->id, $candidate->email, $raw)
                ->assertOk();
        }
    }

    /** @test */
    public function a_missing_letter_is_still_a_clean_404(): void
    {
        [$t, $job, $candidate, $offer] = $this->application();
        $raw = $this->tokens()->issue($offer);

        // Never rendered at all.
        $this->letter($t, $job->id, $candidate->email, $raw)->assertStatus(404);

        // A path recorded but no file behind it — the same single message.
        $offer->update(['letter_path' => 'hr/documents/offers/tenant_1/offer_404.pdf']);
        $this->letter($t, $job->id, $candidate->email, $raw)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Offer letter is not available.');
    }

    /**
     * A letter the candidate has not been sent stays out of reach, even from
     * somebody holding a perfectly valid token.
     *
     * This is not hypothetical. Revising an offer drops it back to Draft,
     * re-renders the PDF with the NEW terms, and deliberately keeps the
     * existing credential — so between the revision and HR sending it again
     * there is a live token pointing at a letter nobody has been told about.
     * The status gate is what holds that shut; without it the candidate could
     * read revised terms before they were offered.
     *
     * @test
     */
    public function a_letter_for_an_unsent_offer_is_refused_even_with_a_valid_token(): void
    {
        foreach (['Draft', 'Pending Approval', 'Approved', 'Generated'] as $status) {
            [$t, $job, $candidate, $offer] = $this->application(null, ['status' => $status, 'sent_at' => null]);
            $this->withLetter($offer);
            $raw = $this->tokens()->issue($offer);

            $this->letter($t, $job->id, $candidate->email, $raw)
                ->assertStatus(404)
                ->assertJsonPath('message', 'Offer letter is not available.');
        }
    }

    /** @test */
    public function the_letter_response_never_reveals_a_storage_path(): void
    {
        [$t, $job, $candidate, $offer] = $this->application();
        $this->withLetter($offer);
        $raw = $this->tokens()->issue($offer);

        $headers = json_encode($this->letter($t, $job->id, $candidate->email, $raw)->assertOk()->headers->all());

        $this->assertStringNotContainsString(storage_path(), $headers);
        $this->assertStringNotContainsString('hr/documents', $headers);
        $this->assertStringNotContainsString($raw, $headers);
    }

    /** @test */
    public function the_letter_requires_the_token_beside_the_email(): void
    {
        [$t, $job, $candidate, $offer] = $this->application();
        $this->withLetter($offer);
        $this->tokens()->issue($offer);

        // Absent entirely — validation.
        $this->getJson("/api/careers/{$t->slug}/jobs/{$job->id}/offer/letter?email=".urlencode($candidate->email))
            ->assertStatus(422);

        // Present but wrong — the second factor.
        $this->letter($t, $job->id, $candidate->email, Str::random(64))->assertStatus(403);
    }

    /** @test */
    public function the_letter_rejects_a_token_issued_for_another_offer(): void
    {
        [$t, $job, $candidate, $offer] = $this->application();
        $this->withLetter($offer);
        $this->tokens()->issue($offer);

        [, , , $someoneElse] = $this->application();
        $theirRaw = $this->tokens()->issue($someoneElse);

        $this->letter($t, $job->id, $candidate->email, $theirRaw)->assertStatus(403);
    }

    /** @test */
    public function the_letter_rejects_a_revoked_token(): void
    {
        [$t, $job, $candidate, $offer] = $this->application();
        $this->withLetter($offer);
        $raw = $this->tokens()->issue($offer);

        $this->tokens()->revoke($offer->fresh());

        $this->letter($t, $job->id, $candidate->email, $raw)->assertStatus(403);
    }

    /** @test */
    public function the_letter_rejects_a_re_keyed_token(): void
    {
        [$t, $job, $candidate, $offer] = $this->application();
        $this->withLetter($offer);
        $old = $this->tokens()->issue($offer);
        $this->tokens()->issue($offer->fresh());

        $this->letter($t, $job->id, $candidate->email, $old)->assertStatus(403);
    }

    /** @test */
    public function the_letter_rejects_the_wrong_email(): void
    {
        [$t, $job, , $offer] = $this->application();
        $this->withLetter($offer);
        $raw = $this->tokens()->issue($offer);

        $this->letter($t, $job->id, 'someone.else@cand.test', $raw)->assertStatus(404);
    }

    /**
     * Everything about the request is the other tenant's — EXCEPT the slug.
     *
     * THE JOB ID HAS TO BE THEIRS, and that is the whole point of this test.
     * It first used a freshly created job of my own, which meant the lookup
     * missed on `job_posting_id` and returned 404 for a reason that has nothing
     * to do with tenancy: deleting the tenant filter from
     * CandidateRepository::findApplicationForJob() left this passing, so it
     * advertised a boundary it never touched.
     *
     * Using their job leaves `tenant_id` as the only column that can refuse the
     * row, so the 404 below means what the method name says it means.
     *
     * @test
     */
    public function the_letter_cannot_be_reached_across_tenants(): void
    {
        [, $theirJob, $theirCandidate, $theirOffer] = $this->application($this->other);
        $this->withLetter($theirOffer);
        $raw = $this->tokens()->issue($theirOffer);

        // Their job, their email, their live credential — through my slug.
        $this->letter($this->tenant, $theirJob->id, $theirCandidate->email, $raw)
            ->assertStatus(404);
    }

    /* ═══════════ 5. THE RESPOND ENDPOINT — PINNED, NOT CHANGED ═══════ */

    /** @test */
    public function a_sent_offer_can_be_accepted_with_the_right_email_and_token(): void
    {
        [$t, $job, $candidate, $offer] = $this->application();
        $raw = $this->tokens()->issue($offer);

        $this->respond($t, $job->id, ['email' => $candidate->email, 'action' => 'accept', 'token' => $raw])
            ->assertOk()
            ->assertJsonPath('offer.status', 'Accepted');

        $this->assertSame('Accepted', $offer->fresh()->status);
    }

    /** @test */
    public function a_sent_offer_can_be_declined_with_a_reason(): void
    {
        [$t, $job, $candidate, $offer] = $this->application();
        $raw = $this->tokens()->issue($offer);

        $this->respond($t, $job->id, [
            'email' => $candidate->email, 'action' => 'decline',
            'reason' => 'Accepted another role', 'token' => $raw,
        ])->assertOk()->assertJsonPath('offer.status', 'Declined');

        $this->assertSame('Accepted another role', $offer->fresh()->rejection_reason);
    }

    /**
     * 'Viewed' IS STILL REFUSED, and this test exists to keep it that way.
     *
     * The offer portal accepts a response at Sent or Viewed; this endpoint
     * stops at Sent. That difference was reviewed and deliberately left alone,
     * so widening it should break a test rather than pass quietly. The
     * candidate's route for a Viewed offer is their emailed link.
     *
     * @test
     */
    public function a_viewed_offer_is_still_refused_by_this_endpoint(): void
    {
        [$t, $job, $candidate, $offer] = $this->application(null, ['status' => 'Viewed', 'viewed_at' => now()]);
        $raw = $this->tokens()->issue($offer);

        $this->respond($t, $job->id, ['email' => $candidate->email, 'action' => 'accept', 'token' => $raw])
            ->assertStatus(422);

        $this->assertSame('Viewed', $offer->fresh()->status, 'The offer must be untouched.');
    }

    /** @test */
    public function a_terminal_offer_cannot_be_responded_to(): void
    {
        foreach (['Accepted', 'Declined', 'Withdrawn', 'Completed', 'Expired'] as $status) {
            [$t, $job, $candidate, $offer] = $this->application(null, ['status' => $status]);
            $raw = $this->tokens()->issue($offer);

            $this->respond($t, $job->id, ['email' => $candidate->email, 'action' => 'accept', 'token' => $raw])
                ->assertStatus(422);

            $this->assertSame($status, $offer->fresh()->status);
        }
    }

    /** @test */
    public function responding_requires_the_token(): void
    {
        [$t, $job, $candidate, $offer] = $this->application();
        $this->tokens()->issue($offer);

        // Missing — validation, before anything is reached.
        $this->respond($t, $job->id, ['email' => $candidate->email, 'action' => 'accept'])
            ->assertStatus(422);

        // Wrong — the second factor.
        $this->respond($t, $job->id, ['email' => $candidate->email, 'action' => 'accept', 'token' => Str::random(64)])
            ->assertStatus(403);

        $this->assertSame('Sent', $offer->fresh()->status);
    }

    /** @test */
    public function responding_rejects_a_token_issued_for_another_offer(): void
    {
        [$t, $job, $candidate, $offer] = $this->application();
        $this->tokens()->issue($offer);

        [, , , $someoneElse] = $this->application();
        $theirRaw = $this->tokens()->issue($someoneElse);

        $this->respond($t, $job->id, ['email' => $candidate->email, 'action' => 'accept', 'token' => $theirRaw])
            ->assertStatus(403);

        $this->assertSame('Sent', $offer->fresh()->status);
        $this->assertSame('Sent', $someoneElse->fresh()->status, 'Nor may it act on the token\'s own offer.');
    }

    /** @test */
    public function responding_rejects_the_wrong_email(): void
    {
        [$t, $job, , $offer] = $this->application();
        $raw = $this->tokens()->issue($offer);

        $this->respond($t, $job->id, ['email' => 'someone.else@cand.test', 'action' => 'accept', 'token' => $raw])
            ->assertStatus(404);

        $this->assertSame('Sent', $offer->fresh()->status);
    }

    /** @test */
    public function responding_rejects_a_revoked_token(): void
    {
        [$t, $job, $candidate, $offer] = $this->application();
        $raw = $this->tokens()->issue($offer);
        $this->tokens()->revoke($offer->fresh());

        $this->respond($t, $job->id, ['email' => $candidate->email, 'action' => 'accept', 'token' => $raw])
            ->assertStatus(403);

        $this->assertSame('Sent', $offer->fresh()->status);
    }

    /** @test */
    public function responding_rejects_a_re_keyed_token(): void
    {
        [$t, $job, $candidate, $offer] = $this->application();
        $old = $this->tokens()->issue($offer);
        $this->tokens()->issue($offer->fresh());

        $this->respond($t, $job->id, ['email' => $candidate->email, 'action' => 'accept', 'token' => $old])
            ->assertStatus(403);

        $this->assertSame('Sent', $offer->fresh()->status);
    }

    /**
     * The same correction, for the endpoint that can actually change something.
     *
     * Their job id, so `tenant_id` is the only filter left standing — see the
     * note on the letter's cross-tenant test. The status assertion matters
     * doubly here: refusing the request is not enough if the offer moved.
     *
     * @test
     */
    public function responding_cannot_reach_across_tenants(): void
    {
        [, $theirJob, $theirCandidate, $theirOffer] = $this->application($this->other);
        $raw = $this->tokens()->issue($theirOffer);

        // Their job, their email, their live credential — through my slug.
        $this->respond($this->tenant, $theirJob->id, [
            'email' => $theirCandidate->email, 'action' => 'accept', 'token' => $raw,
        ])->assertStatus(404);

        $this->assertSame('Sent', $theirOffer->fresh()->status);
    }

    /** @test */
    public function the_respond_response_leaks_no_credential(): void
    {
        [$t, $job, $candidate, $offer] = $this->application();
        $raw = $this->tokens()->issue($offer);

        $body = $this->respond($t, $job->id, [
            'email' => $candidate->email, 'action' => 'accept', 'token' => $raw,
        ])->assertOk()->getContent();

        $this->assertStringNotContainsString($raw, $body);
        $this->assertStringNotContainsString($offer->fresh()->token_hash, $body);
        $this->assertStringNotContainsString('access_token', $body);
    }
}
