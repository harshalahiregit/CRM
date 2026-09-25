<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrOnboarding;
use App\Models\User;
use App\Services\Settings\SettingsService;
use App\Support\Hr\HrSetting;
use App\Support\Hr\TenantTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The candidate's key to their own onboarding.
 *
 * A BEARER CREDENTIAL, stated plainly: whoever holds the link is the candidate
 * as far as this server is concerned. There is no password and no second
 * factor, because a candidate has no account and the link is sent to an email
 * address we do not control. What protects it is high entropy, expiry,
 * revocation and the fact that the raw value is never stored.
 *
 * ONLY THE HASH IS KEPT. The raw token is generated here, handed back once,
 * and written nowhere — not to a column, not to an audit row, not to a log
 * line. It goes into the portal URL that is mailed to the candidate and
 * nothing else. This is the same shape PoshTokenService uses, for the same
 * reason: a credential that can be read back out of the database is a
 * credential every DBA holds.
 *
 * ONE LIVE TOKEN PER ONBOARDING. Reissuing revokes the predecessor in the same
 * write, so a link regenerated because the first was lost does not leave the
 * first one working. That is a DELIBERATE CHANGE of behaviour and it is the
 * point of reissuing; before this existed there was no way to invalidate
 * anything at all.
 *
 * FAIL CLOSED. A malformed, unknown, expired or revoked token all produce the
 * same refusal, and a validity window that is not configured to a usable
 * number of days refuses to issue rather than minting something immortal.
 */
class OnboardingPortalToken
{
    /**
     * 64 characters from Str::random, which draws on random_bytes.
     *
     * Longer than the 48 this portal used before. The link is the only thing
     * between a URL and somebody's identity documents.
     */
    public const TOKEN_LENGTH = 64;

    public function __construct(private SettingsService $settings)
    {
    }

    /* ── issuing ──────────────────────────────────────────────────────── */

    /**
     * Mint a link for this onboarding, replacing any live one.
     *
     * @return string the raw token — the ONLY time it is ever available
     */
    public function issue(HrOnboarding $onboarding, ?User $actor = null): string
    {
        // Resolved and validated before anything is written, so a bad setting
        // leaves no half-issued credential behind.
        $expiresAt = $this->expiryFor((int) $onboarding->tenant_id);

        $raw = Str::random(self::TOKEN_LENGTH);

        $onboarding->forceFill([
            'token_hash'       => self::hash($raw),
            'token_issued_at'  => now(),
            'token_expires_at' => $expiresAt,
            // A fresh link is live by definition, so any earlier revocation is
            // cleared rather than left to silently kill the new one.
            'token_revoked_at' => null,
            // Nothing writes the old plaintext column again.
            'access_token'     => null,
        ])->save();

        // Ids and timing only. A test asserts the raw token reaches no audit.
        $onboarding->recordAudit('Onboarding Portal Link Issued', $actor, null, [
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        return $raw;
    }

    /**
     * Stop a link working.
     *
     * Deliberately NOT called when HR approves or rejects an onboarding. The
     * candidate can still open the portal after either — to see the outcome,
     * or to re-upload a document that was turned down — and cutting that off
     * would change how the product behaves for a security problem it does not
     * have. Revoking is an explicit act.
     */
    public function revoke(HrOnboarding $onboarding, ?User $actor = null, ?string $reason = null): void
    {
        if (! $onboarding->token_hash) {
            throw new BusinessException('This onboarding has no portal link to revoke.', 422);
        }

        if ($onboarding->token_revoked_at) {
            throw new BusinessException('That portal link has already been revoked.', 422);
        }

        $onboarding->forceFill(['token_revoked_at' => now()])->save();

        $onboarding->recordAudit('Onboarding Portal Link Revoked', $actor, $reason);
    }

    /* ── resolving ────────────────────────────────────────────────────── */

    /**
     * The onboarding this link opens, or null.
     *
     * ONE ANSWER FOR EVERY FAILURE — malformed, unknown, expired, revoked, or
     * belonging to a record that has since gone. The caller turns null into a
     * single refusal. Telling them which it was would confirm that a token was
     * real, which is the one fact somebody probing is trying to establish.
     *
     * There is no tenant argument, because the token IS the scope: it resolves
     * to exactly one row and that row names its workspace. Nothing is read
     * from the request, so there is no tenant header to disagree with and
     * nothing to cross.
     */
    public function resolve(?string $raw): ?HrOnboarding
    {
        if (! $this->looksLikeOurs($raw)) {
            // Rejected before it reaches a query.
            return null;
        }

        $onboarding = HrOnboarding::where('token_hash', self::hash($raw))
            ->with(['candidate', 'documents'])
            ->first();

        if (! $onboarding || ! $this->isLive($onboarding)) {
            return null;
        }

        return $onboarding;
    }

    /** Still usable: issued, not revoked, not past its date. */
    public function isLive(HrOnboarding $onboarding): bool
    {
        if (! $onboarding->token_hash || $onboarding->token_revoked_at) {
            return false;
        }

        // A row with no expiry cannot be reached through issue() or the
        // migration, both of which always set one. Treating it as dead is the
        // fail-closed reading of a state that should not exist.
        if (! $onboarding->token_expires_at) {
            return false;
        }

        return $onboarding->token_expires_at->isFuture();
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
     * TWO LENGTHS ARE ACCEPTED, and the second one matters. New tokens are 64
     * characters, but every link already in a candidate's inbox is 48 — the
     * length this portal used before — and the hardening migration hashed
     * those in place precisely so they would keep working. Insisting on 64
     * here would reject all of them before the lookup, which is the silent
     * invalidation the migration exists to avoid.
     *
     * This is a shape filter, not authorisation. The hash lookup decides.
     */
    private const LEGACY_TOKEN_LENGTH = 48;

    private function looksLikeOurs(?string $raw): bool
    {
        return is_string($raw)
            && in_array(strlen($raw), [self::TOKEN_LENGTH, self::LEGACY_TOKEN_LENGTH], true)
            && ctype_alnum($raw);
    }

    /**
     * When a link issued now should stop working.
     *
     * Computed on the workspace's own clock through TenantTime, which is where
     * HR already keeps this question. Anything that is not a whole number of
     * days, at least one, is a misconfiguration and is refused — 30 is not
     * quietly substituted, because that would hide the setting that needs
     * fixing behind a link that works anyway.
     */
    private function expiryFor(int $tenantId): Carbon
    {
        $raw = $this->settings->get($tenantId, HrSetting::GROUP, 'onboarding_link_ttl_days');

        if (! is_numeric($raw) || (int) $raw != $raw || (int) $raw < 1) {
            throw new BusinessException(
                'The onboarding link validity is not set to a usable number of days. '
                .'Set it under HR Settings before sending a portal link.',
                422
            );
        }

        return TenantTime::now($tenantId)->addDays((int) $raw);
    }
}
