<?php

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * The Careers page tracks the offer; the emailed link acts on it.
 *
 * Two dead bodies were removed here. respond() built an accept/decline call
 * without the offer token its endpoint requires, and was wired to no button —
 * it could never have succeeded. careersApi.offerLetterUrl() built a download
 * URL with the same omission and was called by nothing.
 *
 * They are gone rather than repaired, and that is the part worth defending:
 * this page has no token to put in them. Its route is /careers/:slug/jobs/:id,
 * nothing ever emails a tokenised Careers URL, and the raw token must never
 * come back from an API. A page that cannot hold the credential must not host
 * actions that need one.
 *
 * Source-level because the frontend has no test runner — the same approach as
 * BannedPatternsTest and HrActionVisibilityTest beside this file. The server
 * side of all of this is covered behaviourally by CareerPortalOfferTest.
 */
class CareersOfferReadOnlyTest extends TestCase
{
    private const SRC = __DIR__.'/../../../../frontend/src';

    private function read(string $path): string
    {
        $full = self::SRC.'/'.$path;
        $this->assertFileExists($full, "{$path} has moved — update this test to follow it.");

        return file_get_contents($full);
    }

    private function page(): string
    {
        return $this->read('pages/careers/CareerJobDetails.jsx');
    }

    private function api(): string
    {
        return $this->read('services/careersApi.js');
    }

    /* ── 1. the lifecycle the tracker now renders ─────────────────────── */

    /**
     * 'Declined' is the status an offer actually takes.
     *
     * The branch here read 'Rejected', which no offer is ever set to —
     * declining writes 'Declined' and 'Rejected' is a candidate DECISION value
     * — so it never rendered once.
     */
    public function test_the_declined_state_is_rendered_and_the_obsolete_one_is_gone(): void
    {
        $code = $this->stripComments($this->page());

        $this->assertStringContainsString("offer.status === 'Declined'", $code,
            'The real post-decline status must be what the page keys on.');
        $this->assertStringNotContainsString("offer.status === 'Rejected'", $code,
            "'Rejected' is not an offer status — that branch was unreachable.");
    }

    /** The other states the widened payload now returns. */
    public function test_the_remaining_lifecycle_states_are_presented(): void
    {
        $code = $this->stripComments($this->page());

        foreach (['Accepted', 'Expired', 'Withdrawn', 'Completed'] as $status) {
            $this->assertStringContainsString("offer.status === '{$status}'", $code,
                "An offer that is {$status} should say so rather than render nothing.");
        }
    }

    /**
     * The "use your emailed link" message follows the statuses that link can
     * still act at — Sent and Viewed — not can_respond, which reports the
     * Careers endpoint's narrower guard.
     */
    public function test_the_emailed_link_instruction_is_kept_and_correctly_gated(): void
    {
        $src  = $this->page();
        $code = $this->stripComments($src);

        $this->assertStringContainsString("const OPEN_STATUSES = ['Sent', 'Viewed']", $src);
        $this->assertStringContainsString('OPEN_STATUSES.includes(offer.status)', $code);
        $this->assertStringContainsString(
            'please use the private offer link we emailed to you',
            $src,
            'This instruction IS the Careers offer flow — it must not be dropped.');
    }

    /* ── 2. nothing here acts on the offer ────────────────────────────── */

    public function test_the_dead_respond_implementation_is_gone(): void
    {
        $code = $this->stripComments($this->page());

        $this->assertStringNotContainsString('const respond = async', $code,
            'respond() could never have worked — it sent no token.');
        $this->assertStringNotContainsString('respondOffer', $code);
    }

    /** No response control of any kind was put back in its place. */
    public function test_careers_offers_no_response_controls(): void
    {
        $code = $this->stripComments($this->page());

        foreach (['Accept Offer', 'Decline Offer', 'Request Clarification'] as $control) {
            $this->assertStringNotContainsString($control, $code,
                "Careers is a tracker — {$control} belongs on the token-scoped offer portal.");
        }
    }

    /**
     * And it acquires no credential.
     *
     * The three ways it could: a token in the URL, a token typed in, or a token
     * arriving in an API payload. None of them is here.
     */
    public function test_careers_introduces_no_token_handling(): void
    {
        $code = $this->stripComments($this->page());

        $this->assertStringNotContainsString('token', $code,
            'The Careers page must not read, hold, send or build an offer token.');
        $this->assertStringNotContainsString('useSearchParams', $code,
            'No ?token= is read from the Careers URL — nothing ever puts one there.');
    }

    /* ── 3. the API surface ───────────────────────────────────────────── */

    public function test_the_dead_offer_helpers_are_gone(): void
    {
        $code = $this->stripComments($this->api());

        $this->assertStringNotContainsString('respondOffer', $code,
            'It sent no token and was called by nothing.');
        $this->assertStringNotContainsString('offerLetterUrl', $code,
            'It built a URL the server would have rejected, and was called by nothing.');
        $this->assertStringNotContainsString('offer/respond', $code);
        $this->assertStringNotContainsString('offer/letter', $code);
    }

    /** The working Careers helpers are untouched. */
    public function test_the_active_careers_helpers_are_left_alone(): void
    {
        $code = $this->stripComments($this->api());

        foreach (['tenant:', 'jobs:', 'job:', 'apply:', 'status:'] as $helper) {
            $this->assertStringContainsString($helper, $code,
                "{$helper} is in active use and must survive the cleanup.");
        }
    }

    /* ── helper ───────────────────────────────────────────────────────── */

    /**
     * Strip // and /* comments, so a note EXPLAINING what was removed cannot
     * be what fails an assertion about the code itself — these files now carry
     * several such notes.
     */
    private function stripComments(string $src): string
    {
        $src = preg_replace('#/\*.*?\*/#s', '', $src);

        return preg_replace('#(^|\s)//[^\n]*#', '$1', $src);
    }
}
