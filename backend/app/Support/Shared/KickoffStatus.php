<?php

namespace App\Support\Shared;

/**
 * Lifecycle of a kickoff meeting.
 *
 * Scheduled → Delayed → Completed, with Cancelled reachable from any open state.
 * Delayed is its own status rather than a flag because "we slipped the date"
 * is a thing a coordinator reports on ("how many kickoffs are running late?"),
 * not just a new scheduled_at.
 *
 * Sign-off is deliberately NOT a status: whether the vendor has acknowledged the
 * minutes is orthogonal to whether the meeting happened (a meeting can be
 * Completed and still awaiting the vendor's acknowledgement). It lives on its
 * own acknowledged_at field, the same way compliance kept submission separate
 * from the approval status.
 *
 * Stored on kickoff_meetings.status as a plain string (no enum cast) so API
 * consumers keep receiving strings. Mirrors TpvOnboardingStatus.
 */
final class KickoffStatus
{
    public const SCHEDULED = 'Scheduled';
    public const DELAYED   = 'Delayed';
    public const COMPLETED = 'Completed';
    public const CANCELLED = 'Cancelled';

    public const ALL = [self::SCHEDULED, self::DELAYED, self::COMPLETED, self::CANCELLED];

    public const LABELS = [
        self::SCHEDULED => 'Scheduled',
        self::DELAYED   => 'Delayed',
        self::COMPLETED => 'Completed',
        self::CANCELLED => 'Cancelled',
    ];

    /** States where the meeting has not yet happened and details may still change. */
    public const OPEN = [self::SCHEDULED, self::DELAYED];

    /** Nothing further happens without an explicit reopen/reschedule. */
    public const CLOSED = [self::COMPLETED, self::CANCELLED];

    /**
     * Permitted moves. Anything not listed is refused by the service, so a
     * caller cannot post its way from Cancelled straight to Completed.
     */
    public const TRANSITIONS = [
        self::SCHEDULED => [self::DELAYED, self::COMPLETED, self::CANCELLED],
        self::DELAYED   => [self::SCHEDULED, self::COMPLETED, self::CANCELLED],
        self::COMPLETED => [],
        self::CANCELLED => [self::SCHEDULED],   // reopen a cancelled meeting
    ];

    public static function label(?string $s): string
    {
        return self::LABELS[$s] ?? (string) $s;
    }

    public static function isValid(?string $s): bool
    {
        return in_array($s, self::ALL, true);
    }

    public static function isOpen(?string $s): bool
    {
        return in_array($s, self::OPEN, true);
    }

    public static function isClosed(?string $s): bool
    {
        return in_array($s, self::CLOSED, true);
    }

    public static function canTransition(?string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
