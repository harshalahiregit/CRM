<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Generic remaining-validity arithmetic — the ONLY piece shared between TPV and
 * Purchase, and deliberately free of either module's rules.
 *
 * Callers decide *whether* an account is temporary and *when* it expires using
 * their own existing logic; this only turns those two facts into the countdown
 * payload the UI consumes. It is not a second source of truth: it never reads a
 * model, a column or a status.
 */
final class ValidityCountdown
{
    /**
     * @param  bool  $isTemporary  the module's own answer
     * @param  Carbon|null  $expiresAt  the module's own expiry instant
     * @param  bool|null  $forceExpired  module-level override (e.g. an admin
     *                                   revoked access before the clock ran out)
     */
    public static function build(
        bool $isTemporary,
        ?Carbon $expiresAt,
        ?bool $forceExpired = null,
        bool $isActivated = true,
    ): array {
        // Permanent accounts carry no countdown at all — the UI shows "Permanent".
        if (! $isTemporary) {
            return [
                'state'             => 'permanent',
                'is_temporary'      => false,
                'is_awaiting_activation' => false,
                'expires_at'        => null,
                'remaining_seconds' => null,
                'remaining_days'    => null,
                'remaining_hours'   => null,
                'is_expired'        => false,
            ];
        }

        // The clock starts at activation. Before that a temporary account has
        // simply not begun — reporting "Expired" for a brand-new registration
        // would be wrong, and reporting a countdown would be a fiction.
        if (! $isActivated) {
            return [
                'state'             => 'awaiting_activation',
                'is_temporary'      => true,
                'is_awaiting_activation' => true,
                'expires_at'        => null,
                'remaining_seconds' => null,
                'remaining_days'    => null,
                'remaining_hours'   => null,
                'is_expired'        => false,
            ];
        }

        $seconds = $expiresAt ? max(0, $expiresAt->getTimestamp() - now()->getTimestamp()) : 0;
        $expired = $forceExpired ?? ($seconds <= 0);

        return [
            'state'             => $expired ? 'expired' : 'active',
            'is_temporary'      => true,
            'is_awaiting_activation' => false,
            'expires_at'        => $expiresAt?->toIso8601String(),
            'remaining_seconds' => $expired ? 0 : $seconds,
            // Whole days, then the hours left over — so "3 Days 4 Hours" is
            // additive rather than two views of the same number.
            'remaining_days'    => $expired ? 0 : intdiv($seconds, 86400),
            'remaining_hours'   => $expired ? 0 : intdiv($seconds % 86400, 3600),
            'is_expired'        => $expired,
        ];
    }
}
