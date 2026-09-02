<?php

namespace App\Support\Hr;

/**
 * The status vocabulary, in one place.
 *
 * SangoeTrack validates its status set in at least four separate files, so adding
 * a value to three of them produces a status that saves through one path and is
 * rejected by another. Every validator here derives from this list, so that
 * cannot happen.
 */
final class ReimbursementStatus
{
    /** Waiting on an admin. The state a request returns to when a hold is cleared. */
    public const PENDING = 'pending';

    /** An admin needs something before deciding, and has said what. */
    public const ON_HOLD = 'on_hold';

    public const APPROVED = 'approved';
    public const DECLINED = 'declined';

    public const ALL = [self::PENDING, self::ON_HOLD, self::APPROVED, self::DECLINED];

    /** Decided. No further action, and no more holds. */
    public const TERMINAL = [self::APPROVED, self::DECLINED];

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    /** For a validation rule: `Rule::in(ReimbursementStatus::ALL)`. */
    public static function rule(): string
    {
        return 'in:' . implode(',', self::ALL);
    }
}
