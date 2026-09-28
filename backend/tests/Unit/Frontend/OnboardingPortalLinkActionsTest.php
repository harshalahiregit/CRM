<?php

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * The HR onboarding screen's Candidate Portal buttons, and why they stopped.
 *
 * Hardening the onboarding token emptied hr_onboarding.access_token and put the
 * column in the model's $hidden, so the key is ABSENT from every list response
 * rather than null. Onboarding.jsx still asked `r.access_token ? … : …`, which
 * is false for every row, so "Open Candidate Portal" and "Copy Link" rendered
 * as a disabled "Portal link not available." for every onboarding in the
 * product — including the ones holding a perfectly good live link. The backend
 * endpoints to fix it shipped in the same commit and nothing ever called them.
 *
 * Source-level because the frontend has no test runner — the same approach as
 * BannedPatternsTest and HrActionVisibilityTest beside this file. The endpoints
 * these buttons call, and their tenant/permission gating, are covered
 * behaviourally by OnboardingPortalTokenTest.
 *
 * What these tests defend is narrower than "the buttons work": the raw link
 * must exist only for the immediate action. So several of them look for the
 * ABSENCE of things — no token in row state, no URL assembled locally, no
 * storage write — because that is the property that would rot silently.
 */
class OnboardingPortalLinkActionsTest extends TestCase
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
        return $this->read('modules/hr/pages/Onboarding.jsx');
    }

    /* ── 1. the regression itself ─────────────────────────────────────── */

    /**
     * The dead test is gone.
     *
     * Asserted against code with comments stripped, because the file explains
     * what it used to read and that sentence must not be what makes this pass.
     */
    public function test_the_screen_no_longer_depends_on_the_raw_onboarding_token(): void
    {
        $code = $this->stripComments($this->page());

        $this->assertStringNotContainsString('access_token', $code,
            'Onboarding.jsx must not read the raw token: it is hidden from every response and only a hash is stored.');
    }

    /** And it is not smuggled back in by rebuilding the URL from a token. */
    public function test_the_screen_does_not_assemble_a_portal_url_itself(): void
    {
        $code = $this->stripComments($this->page());

        $this->assertStringNotContainsString('/onboarding/${', $code,
            'The server returns the finished link; the page must not build one from a token.');
        $this->assertStringNotContainsString('const portalUrl', $code,
            'portalUrl() existed only to concatenate a raw token into a URL.');
    }

    /* ── 2. the four states, read from the lifecycle timestamps ───────── */

    public function test_link_state_is_derived_from_the_lifecycle_timestamps(): void
    {
        $src = $this->page();

        $this->assertStringContainsString('const linkState = (r) => {', $src);

        // Never issued.
        $this->assertStringContainsString("if (! r.token_issued_at) return 'none'", $src,
            'An onboarding whose link was never issued is its own state.');

        // Deliberately killed.
        $this->assertStringContainsString("if (r.token_revoked_at) return 'revoked'", $src,
            'Revoked is checked before expiry — a revoked link is dead whatever its date says.');

        // Past its TTL.
        $this->assertStringContainsString("new Date(r.token_expires_at) <= new Date()) return 'expired'", $src,
            'Expiry is compared as a date, not assumed from presence.');

        $this->assertStringContainsString("return 'live'", $src);
    }

    /** Each state says something different on the button. */
    public function test_each_state_is_labelled_distinctly(): void
    {
        $src = $this->page();

        $this->assertStringContainsString(
            "const LINK_LABEL = { none: 'Issue Portal Link', revoked: 'Issue New Link', expired: 'Issue New Link', live: 'New Portal Link' }",
            $src,
            'A live link is REPLACED, not opened — the label has to say so.');

        $this->assertStringContainsString('{LINK_LABEL[linkState(r)]}', $src,
            'The button must render the label for the row\'s actual state.');
    }

    /** Revoking is offered only where there is something to revoke. */
    public function test_revoke_is_offered_only_for_a_live_link(): void
    {
        $src = $this->page();

        $this->assertStringContainsString("{linkState(r) === 'live' && (", $src,
            'Revoke on a dead link is an error the server would reject anyway.');
        $this->assertStringContainsString('Revoke Link', $src);
        $this->assertStringContainsString("{linkState(r) === 'revoked' && (", $src,
            'A revoked link should be visible as revoked, not silently look un-issued.');
        $this->assertStringContainsString("{linkState(r) === 'expired' && (", $src);
    }

    /* ── 3. the endpoints ─────────────────────────────────────────────── */

    public function test_the_api_helpers_call_the_existing_backend_endpoints(): void
    {
        $api = $this->read('services/hrApi.js');

        $this->assertStringContainsString(
            'issuePortalLink: (id)         => api.post(`/hr/onboarding/${id}/portal-link`).then(r => r.data)',
            $api,
            'Issuing must POST the endpoint that already exists — no new route, no new credential.');

        $this->assertStringContainsString(
            'revokePortalLink:(id, reason) => api.delete(`/hr/onboarding/${id}/portal-link`, { data: { reason } }).then(r => r.data)',
            $api,
            'Revoking must DELETE the same endpoint, carrying the optional reason the controller validates.');
    }

    /** There is no "read the current link" call, because there is no such thing. */
    public function test_no_helper_pretends_to_fetch_an_existing_link(): void
    {
        $api = $this->stripComments($this->read('services/hrApi.js'));

        $this->assertStringNotContainsString('api.get(`/hr/onboarding/${id}/portal-link`', $api,
            'Only a hash is stored — a GET for the current link could never be answered.');
    }

    public function test_issuing_and_revoking_go_through_those_helpers(): void
    {
        $src = $this->page();

        $this->assertStringContainsString('hrApi.onboarding.issuePortalLink(record.id)', $src);
        $this->assertStringContainsString('hrApi.onboarding.revokePortalLink(record.id)', $src);
    }

    /* ── 4. the returned URL is what gets used ────────────────────────── */

    public function test_open_uses_the_url_the_server_returned(): void
    {
        $src = $this->page();

        $this->assertStringContainsString(
            "const openPortal = (record) => issueLink(record, (link) => window.open(link, '_blank', 'noopener,noreferrer'))",
            $src,
            'Open must receive the issued link as an argument and use it directly.');
    }

    public function test_copy_uses_the_url_the_server_returned(): void
    {
        $src = $this->page();

        $this->assertStringContainsString('const copyLink = (record) => issueLink(record, async (link) => {', $src);
        $this->assertStringContainsString('navigator.clipboard.writeText(link)', $src,
            'Copy must write the issued link, not something reconstructed.');
        $this->assertStringContainsString("showToast('Candidate portal link copied!')", $src,
            'The existing success feedback is kept.');
    }

    /** The link is read out of the response, not guessed at. */
    public function test_the_link_is_taken_from_the_issue_response(): void
    {
        $this->assertStringContainsString('then(res?.data?.link)', $this->page(),
            'The raw URL comes from the one response that carries it.');
    }

    /* ── 5. replacing a live link is confirmed ────────────────────────── */

    public function test_replacing_a_live_link_asks_first(): void
    {
        $src = $this->page();

        $this->assertStringContainsString(
            "linkState(record) === 'live'\n      ? setLinkConfirm({ kind: 'issue', record, then })\n      : runIssueLink({ record, then })",
            $src,
            'Only the live case is destructive; the other three have nothing to destroy and must not nag.');

        $this->assertStringContainsString('<ConfirmDialog', $src);
        $this->assertStringContainsString("import ConfirmDialog from '@/components/ui/ConfirmDialog'", $src);
        $this->assertStringContainsString('Issue a new onboarding link?', $src);
    }

    /** Revoking is destructive too, and asks in the same way. */
    public function test_revoking_asks_first(): void
    {
        $src = $this->page();

        $this->assertStringContainsString("const revokeLink = (record) => setLinkConfirm({ kind: 'revoke', record })", $src);
        $this->assertStringContainsString('Revoke this onboarding link?', $src);
    }

    /** The banned pattern this screen must not reach for. */
    public function test_no_native_confirm_is_used_for_these_actions(): void
    {
        $code = $this->stripComments($this->page());

        $this->assertStringNotContainsString('window.confirm', $code,
            'ConfirmDialog is the convention, and BannedPatternsTest enforces it.');
    }

    /* ── 6. the raw link does not stick around ────────────────────────── */

    /**
     * The property worth defending most, and the easiest to lose later.
     *
     * The confirmation state holds the RECORD and a callback — never a link,
     * because at that point no link exists yet. Putting the issued URL into
     * `records` or into its own state would leave a bearer credential sitting
     * in the page for as long as the tab is open.
     */
    public function test_the_issued_link_is_never_put_into_page_state(): void
    {
        $src  = $this->page();
        $code = $this->stripComments($src);

        $this->assertStringContainsString("// Pending portal-link confirmation: { kind: 'issue'|'revoke', record, then? }.", $src,
            'The shape of what is held is documented where it is declared.');

        foreach (['setLinkConfirm({ link', 'setRecords(link', 'setLink(', 'useState(link'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code,
                "The issued link must not be stored — found `{$forbidden}`.");
        }

        // It is consumed by the callback and goes out of scope.
        $this->assertStringContainsString('then(res?.data?.link)', $code);
    }

    public function test_the_issued_link_is_never_written_to_browser_storage(): void
    {
        $code = $this->stripComments($this->page());

        $this->assertStringNotContainsString('localStorage', $code);
        $this->assertStringNotContainsString('sessionStorage', $code);
    }

    public function test_the_issued_link_is_never_logged(): void
    {
        $code = $this->stripComments($this->page());

        $this->assertStringNotContainsString('console.log', $code,
            'A link in the console is a credential in the console.');
    }

    /* ── 7. the list stays accurate afterwards ────────────────────────── */

    public function test_issuing_and_revoking_refresh_the_list(): void
    {
        $src = $this->page();

        // Both handlers end by re-reading the list, so the timestamps the
        // buttons are derived from cannot go stale behind the user.
        $this->assertSame(
            3,
            substr_count($src, 'fetchData()'),
            'fetchData() should run on mount, after issuing and after revoking.'
        );
    }

    /* ── 8. no offer-token exposure is reintroduced ───────────────────── */

    public function test_this_change_does_not_touch_the_offer_token_model(): void
    {
        foreach ([
            'modules/hr/pages/OfferLetters.jsx',
            'modules/hr/pages/CandidateProfile.jsx',
            'modules/hr/pages/EmployeeProfile.jsx',
            'pages/careers/OnboardingPortal.jsx',
        ] as $path) {
            $code = $this->stripComments($this->read($path));

            $this->assertStringNotContainsString('access_token', $code,
                "{$path} must not read a raw token — the offer hardening in ef051d45 removed every one.");
        }
    }

    /* ── helper ───────────────────────────────────────────────────────── */

    /**
     * Strip // and /* comments so a docblock DESCRIBING the old code cannot
     * satisfy — or break — an assertion about the code itself.
     */
    private function stripComments(string $src): string
    {
        $src = preg_replace('#/\*.*?\*/#s', '', $src);

        return preg_replace('#(^|\s)//[^\n]*#', '$1', $src);
    }
}
