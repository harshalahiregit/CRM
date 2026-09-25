<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrOffer;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The candidate's key to their own offer.
 *
 * A BEARER CREDENTIAL, stated plainly: whoever holds the link is the candidate
 * as far as this server is concerned. There is no password and no second
 * factor, because a candidate has no account and the link is sent to an email
 * address we do not control. Whoever holds it can read the offered CTC and the
 * full salary breakup, and can ACCEPT the offer — an acceptance records a
 * typed name, a drawn signature, an IP and a device against that person.
 *
 * ONLY THE HASH IS KEPT. The raw token is generated here, handed back once,
 * and written nowhere — not to a column, not to an audit row, not to a log
 * line. It goes into the portal URL that is mailed and WhatsApp'd to the
 * candidate and nothing else. Same shape as OnboardingPortalToken and
 * ProposalOtpService, for the same reason: a credential that can be read back
 * out of the database is a credential every DBA holds.
 *
 * ONE LIVE TOKEN PER OFFER. Issuing revokes the predecessor in the same write.
 *
 * THERE IS NO TTL HERE, AND THAT IS THE DESIGN.
 *
 * OnboardingPortalToken expires its tokens because that portal has no notion of
 * its own of when it should close. An offer does: hr_offers.validity_date, which
 * HR sets, extends and regenerates, and which drives the Expired status the
 * candidate is shown. Giving the token a second, independent clock would let a
 * link die while the offer it opens is still valid — and, worse, would make
 * "valid until the 30th" mean something different depending on which of the two
 * clocks ran out first.
 *
 * So liveness here answers one question only: IS THIS CREDENTIAL STILL THE
 * CURRENT ONE? Whether the offer behind it can still be accepted is a separate
 * question, answered where it always was — by the status machine in
 * OfferService. An expired offer still opens its link and shows the candidate
 * that it expired. A declined one shows that it was declined. That behaviour is
 * deliberate and is preserved exactly.
 *
 * FAIL CLOSED. Malformed, unknown, revoked and superseded all produce the same
 * null, which the caller turns into one refusal.
 */
class OfferPortalToken
{
    /**
     * 64 characters from Str::random, which draws on random_bytes.
     *
     * Longer than the 48 this portal used before, and the column that holds
     * the hash is sized for sha256 hex rather than for the token.
     */
    public const TOKEN_LENGTH = 64;

    /**
     * Tokens minted before this hardening are 48 characters.
     *
     * They are still live — the migration hashed them in place rather than
     * invalidating them — so the shape filter has to let them through or every
     * link already in a candidate's inbox would be rejected before the lookup,
     * which is precisely the silent invalidation that must not happen.
     */
    private const LEGACY_TOKEN_LENGTH = 48;

    /* ── issuing ──────────────────────────────────────────────────────── */

    /**
     * Mint a link for this offer, replacing any live one.
     *
     * @return string the raw token — the ONLY time it is ever available
     */
    public function issue(HrOffer $offer, ?User $actor = null): string
    {
        $raw = Str::random(self::TOKEN_LENGTH);

        $offer->forceFill([
            'token_hash'       => self::hash($raw),
            'token_issued_at'  => now(),
            // A fresh link is live by definition, so any earlier revocation is
            // cleared rather than left to silently kill the new one.
            'token_revoked_at' => null,
            // Nothing writes the old plaintext column again, ever.
            'access_token'     => null,
        ])->save();

        return $raw;
    }

    /**
     * Stop a link working, without touching the offer.
     *
     * A CREDENTIAL OPERATION ONLY. Revoking does not withdraw, decline or
     * expire anything: the offer keeps its status, its validity date and its
     * place in the state machine, and HR can issue a new link for it a moment
     * later. That separation is the point — "this link leaked" and "this offer
     * is off the table" are different events and conflating them would let a
     * security action silently change a commercial one.
     *
     * Deliberately NOT called on accept, decline, withdraw or joining. The
     * candidate can still open the portal after all four — to re-read what they
     * signed, or to see why it was withdrawn — and cutting that off would
     * change how the product behaves for a problem it does not have.
     */
    public function revoke(HrOffer $offer, ?User $actor = null, ?string $reason = null): void
    {
        if (! $offer->token_hash) {
            throw new BusinessException('This offer has no portal link to revoke.', 422);
        }

        if ($offer->token_revoked_at) {
            throw new BusinessException('That portal link has already been revoked.', 422);
        }

        $offer->forceFill(['token_revoked_at' => now()])->save();

        optional($offer->candidate)->recordAudit('Offer Portal Link Revoked', $actor, $reason);
    }

    /* ── resolving ────────────────────────────────────────────────────── */

    /**
     * The offer this link opens, or null.
     *
     * ONE ANSWER FOR EVERY FAILURE — malformed, unknown, revoked, superseded by
     * a re-key, or belonging to a row that has since gone. The caller turns null
     * into a single refusal. Telling them which it was would confirm that a
     * token was real, which is the one fact somebody probing is trying to
     * establish.
     *
     * There is no tenant argument, and there must not be one. The token IS the
     * scope: it resolves to exactly one row and that row names its workspace.
     * Nothing is read from the request, so there is no tenant header to
     * disagree with and nothing to cross.
     */
    public function resolve(?string $raw): ?HrOffer
    {
        if (! $this->looksLikeOurs($raw)) {
            // Rejected before it reaches a query.
            return null;
        }

        $offer = HrOffer::where('token_hash', self::hash($raw))
            ->with('candidate')
            ->first();

        if (! $offer || ! $this->isLive($offer)) {
            return null;
        }

        return $offer;
    }

    /**
     * Still the current credential: issued, and not revoked.
     *
     * Note what is NOT consulted — validity_date, status, expired_at. A link to
     * an expired, declined or withdrawn offer is live and opens the portal,
     * which is how the candidate learns the outcome. See the class note.
     */
    public function isLive(HrOffer $offer): bool
    {
        return (bool) $offer->token_hash && ! $offer->token_revoked_at;
    }

    /**
     * The stored form of a raw token.
     *
     * One place, so a lookup and a write can never disagree about what is
     * being compared.
     */
    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /* ── internals ────────────────────────────────────────────────────── */

    /**
     * Cheap shape check, so obvious rubbish never reaches a query.
     *
     * This is a filter, not authorisation. The hash lookup decides.
     */
    private function looksLikeOurs(?string $raw): bool
    {
        return is_string($raw)
            && in_array(strlen($raw), [self::TOKEN_LENGTH, self::LEGACY_TOKEN_LENGTH], true)
            && ctype_alnum($raw);
    }
}
