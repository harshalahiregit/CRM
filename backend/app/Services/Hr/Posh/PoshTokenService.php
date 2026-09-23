<?php

namespace App\Services\Hr\Posh;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrPoshCase;
use App\Models\Hr\HrPoshCaseToken;
use App\Models\User;
use App\Services\Settings\SettingsService;
use App\Support\Hr\HrSetting;
use App\Support\Hr\TenantTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issuing, resolving and revoking a complainant's link.
 *
 * THE RAW TOKEN EXISTS ONCE. It is generated here, returned to the caller of
 * issue(), and never written anywhere — not to the row, not to the audit
 * trail, not to a log line, not into a notification. Only its sha256 is
 * stored. Nothing in this system can recover it afterwards, including this
 * service, which is the point: a credential that can be read back out of the
 * database is a credential every DBA holds.
 *
 * ISSUANCE IS NOT INTAKE. Creating a case does not mint a token. The person
 * who takes a complaint at the door holds hr_posh_intake and nothing else, and
 * handing them the complainant's credential would give them a way into a file
 * they were deliberately denied. Issuing is a separate, explicit act by an
 * active case member whose committee role carries can_manage_case, which keeps
 * the credential inside the committee.
 *
 * ONE LIVE TOKEN PER CASE. Issuing again revokes the predecessor in the same
 * transaction, so a link handed out twice does not leave two working doors and
 * an old one that nobody remembers. Rows are never deleted: the history of
 * which credentials existed and when they stopped working is part of the case
 * record.
 *
 * FAIL CLOSED ON THE TTL. The validity comes from a tenant setting whose
 * generic integer rule admits 0 and null. Neither means "forever" here, and
 * treating them that way would mint an immortal credential from a blank form
 * field. Both are refused, and 30 is NOT substituted at runtime — quietly
 * supplying a default would hide the misconfiguration that needs fixing.
 */
class PoshTokenService
{
    /**
     * 64 characters from Str::random, which draws on random_bytes.
     *
     * Longer than the 48 the offer and onboarding portals use, because this
     * link is the only thing standing between a URL and a harassment file.
     */
    private const TOKEN_LENGTH = 64;

    public function __construct(
        private PoshCaseAuthority $authority,
        private SettingsService $settings,
    ) {
    }

    /* ── issuing ──────────────────────────────────────────────────────── */

    /**
     * Mint a link for this case, revoking any predecessor.
     *
     * @return array{token: string, expires_at: string, id: int}
     *         `token` is the ONLY time the raw value is ever available.
     */
    public function issue(HrPoshCase $case, User $actor): array
    {
        $this->authority->assertCanManageCase($actor, $case);

        // Resolved and validated BEFORE anything is written, so a bad setting
        // leaves no half-issued row behind.
        $expiresAt = $this->expiryFor((int) $case->tenant_id);

        $raw = Str::random(self::TOKEN_LENGTH);

        return DB::transaction(function () use ($case, $actor, $raw, $expiresAt) {
            $superseded = $this->liveToken($case);

            if ($superseded) {
                $superseded->update([
                    'revoked_at'     => now(),
                    'revoked_by'     => $actor->id,
                    'revoked_reason' => 'Superseded by a newly issued link.',
                ]);
            }

            $token = HrPoshCaseToken::create([
                'tenant_id'  => $case->tenant_id,
                'case_id'    => $case->id,
                'token_hash' => HrPoshCaseToken::hash($raw),
                'label'      => $this->label($case),
                'issued_at'  => now(),
                'issued_by'  => $actor->id,
                'expires_at' => $expiresAt,
                // Returned in this response and never again.
                'presented_at' => now(),
                'use_count'  => 0,
            ]);

            // Ids and timing only. The raw token is deliberately absent, and a
            // test asserts it never reaches an audit payload.
            $case->recordAudit('POSH Complainant Link Issued', $actor, null, [
                'token_id'   => $token->id,
                'expires_at' => $expiresAt->toIso8601String(),
                'superseded' => $superseded?->id,
            ]);

            return [
                'id'         => $token->id,
                'token'      => $raw,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        });
    }

    /**
     * Stop a link working.
     *
     * The same authority as issuing. hr_settings is not enough and neither is
     * being an administrator: both can reach the case's configuration, neither
     * can reach the case.
     */
    public function revoke(HrPoshCase $case, int $tokenId, User $actor, ?string $reason = null): void
    {
        $this->authority->assertCanManageCase($actor, $case);

        $token = HrPoshCaseToken::where('tenant_id', $case->tenant_id)
            ->where('case_id', $case->id)
            ->whereKey($tokenId)
            ->first();

        if (! $token) {
            // Looked up WITHIN the case, never by bare id — the same ordering
            // the attachment routes use against the same class of mistake.
            throw new BusinessException('Case not found', 404);
        }

        if ($token->isRevoked()) {
            throw new BusinessException('That link has already been revoked.', 422);
        }

        $token->update([
            'revoked_at'     => now(),
            'revoked_by'     => $actor->id,
            'revoked_reason' => $reason ? trim($reason) : 'Revoked by the committee.',
        ]);

        $case->recordAudit('POSH Complainant Link Revoked', $actor, $reason, [
            'token_id' => $token->id,
        ]);
    }

    /* ── resolving ────────────────────────────────────────────────────── */

    /**
     * The case this link opens, or null.
     *
     * ONE ANSWER FOR EVERY FAILURE. Malformed, unknown, expired, revoked,
     * superseded, or pointing at a case that has since been deleted — all of
     * them return null, and the caller turns null into one 404 with one body.
     * Distinguishing them would confirm that a token was real, which is the
     * one fact a probing caller is trying to establish.
     *
     * There is no tenant argument. The token IS the scope: it resolves to a
     * row, the row names the case, and the case names the tenant. Nothing is
     * read from the request, so there is no header or parameter to disagree
     * with.
     */
    public function resolve(?string $raw): ?array
    {
        if (! is_string($raw) || strlen($raw) !== self::TOKEN_LENGTH || ! ctype_alnum($raw)) {
            // Rejected before it reaches a query. Str::random is alphanumeric,
            // so anything else cannot be one of ours.
            return null;
        }

        $token = HrPoshCaseToken::where('token_hash', HrPoshCaseToken::hash($raw))->first();

        if (! $token || ! $token->isLive()) {
            return null;
        }

        $case = HrPoshCase::where('tenant_id', $token->tenant_id)
            ->whereKey($token->case_id)
            ->first();

        if (! $case) {
            return null;
        }

        return ['case' => $case, 'token' => $token];
    }

    /** Record that the link was used. Never fails the read it is describing. */
    public function touch(HrPoshCaseToken $token): void
    {
        $token->forceFill([
            'last_used_at' => now(),
            'use_count'    => (int) $token->use_count + 1,
        ])->saveQuietly();
    }

    /* ── internals ────────────────────────────────────────────────────── */

    /** The live token for a case, if there is one. */
    public function liveToken(HrPoshCase $case): ?HrPoshCaseToken
    {
        return HrPoshCaseToken::where('tenant_id', $case->tenant_id)
            ->where('case_id', $case->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    /**
     * When a token issued now should stop working.
     *
     * Computed on the workspace's own clock through TenantTime, which is the
     * mechanism the rest of HR already uses. Anything that is not a whole
     * number of days, at least one, is a misconfiguration and is refused.
     */
    private function expiryFor(int $tenantId): \Illuminate\Support\Carbon
    {
        $raw = $this->settings->get($tenantId, HrSetting::GROUP, 'posh_token_ttl_days');

        if (! is_numeric($raw) || (int) $raw != $raw || (int) $raw < 1) {
            throw new BusinessException(
                'The POSH complainant link validity is not set to a usable number of days. '
                .'Set it under HR Settings before issuing a link.',
                422
            );
        }

        return TenantTime::now($tenantId)->addDays((int) $raw);
    }

    /**
     * What the audit trail calls whoever uses this link.
     *
     * The case reference and nothing else. Not the complainant's name, not the
     * narrative, not the respondent, and no part of the token — a label is
     * printed on screens and copied into exports, and it has to stay safe
     * everywhere it lands.
     */
    private function label(HrPoshCase $case): string
    {
        return 'Complainant · '.$case->reference;
    }
}
