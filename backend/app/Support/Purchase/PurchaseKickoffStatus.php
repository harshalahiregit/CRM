<?php

namespace App\Support\Purchase;

/**
 * Lifecycle of a Purchase kickoff meeting. Scheduled → Delayed → Completed, with
 * Cancelled reachable from any open state (and reopenable). Acknowledgement is
 * orthogonal to status (lives on acknowledged_at). Stored on
 * purchase_kickoff_meetings.status as a plain string. Purchase-owned mirror of
 * the shared KickoffStatus — the two engines never share code or tables.
 */
final class PurchaseKickoffStatus
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

    public const OPEN   = [self::SCHEDULED, self::DELAYED];
    public const CLOSED = [self::COMPLETED, self::CANCELLED];

    public const TRANSITIONS = [
        self::SCHEDULED => [self::DELAYED, self::COMPLETED, self::CANCELLED],
        self::DELAYED   => [self::SCHEDULED, self::COMPLETED, self::CANCELLED],
        self::COMPLETED => [],
        self::CANCELLED => [self::SCHEDULED],
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
