<?php

namespace App\Support\Purchase;

/**
 * Lifecycle of a Purchase MOM action item (Sangoe TPV §9 —
 * Meeting → MOM → Action → Owner → Due → Evidence → Verification → Closure).
 *
 * Open → In Progress → Pending Verification → Closed, with Reopened and
 * Cancelled reachable per the transition map. Closing requires evidence or a
 * verification note (Business Rule 12 — every closure requires evidence). A move
 * not on the TRANSITIONS map is refused.
 *
 * Purchase-owned mirror of the shared MomActionStatus — the two engines never
 * share code or tables. Stored on purchase_mom_action_items.status.
 */
final class PurchaseMomActionStatus
{
    public const OPEN = 'Open';

    public const IN_PROGRESS = 'In_Progress';

    public const PENDING_VERIFICATION = 'Pending_Verification';

    public const CLOSED = 'Closed';

    public const REOPENED = 'Reopened';

    public const CANCELLED = 'Cancelled';

    public const ALL = [
        self::OPEN, self::IN_PROGRESS, self::PENDING_VERIFICATION,
        self::CLOSED, self::REOPENED, self::CANCELLED,
    ];

    public const LABELS = [
        self::OPEN                 => 'Open',
        self::IN_PROGRESS          => 'In Progress',
        self::PENDING_VERIFICATION => 'Pending Verification',
        self::CLOSED               => 'Closed',
        self::REOPENED             => 'Reopened',
        self::CANCELLED            => 'Cancelled',
    ];

    /** States where the action is still live (counts as outstanding). */
    public const OPEN_STATES = [self::OPEN, self::IN_PROGRESS, self::PENDING_VERIFICATION, self::REOPENED];

    public const TRANSITIONS = [
        self::OPEN                 => [self::IN_PROGRESS, self::PENDING_VERIFICATION, self::CANCELLED],
        self::IN_PROGRESS          => [self::OPEN, self::PENDING_VERIFICATION, self::CANCELLED],
        self::PENDING_VERIFICATION => [self::IN_PROGRESS, self::CLOSED, self::REOPENED, self::CANCELLED],
        self::CLOSED               => [self::REOPENED],
        self::REOPENED             => [self::IN_PROGRESS, self::PENDING_VERIFICATION, self::CLOSED, self::CANCELLED],
        self::CANCELLED            => [self::REOPENED],
    ];

    public static function label(?string $v): string
    {
        return self::LABELS[$v] ?? (string) ($v ?: self::OPEN);
    }

    public static function isValid(?string $v): bool
    {
        return in_array($v, self::ALL, true);
    }

    public static function isOpen(?string $v): bool
    {
        return in_array($v ?: self::OPEN, self::OPEN_STATES, true);
    }

    public static function canTransition(?string $from, ?string $to): bool
    {
        $from = $from ?: self::OPEN;

        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
