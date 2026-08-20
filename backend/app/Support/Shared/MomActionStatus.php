<?php

namespace App\Support\Shared;

/**
 * Lifecycle of a MOM action item (Meeting.docx §8 — the Action Engine).
 *
 * Open → In Progress → Pending Verification → Closed, with Reopened and Cancelled
 * as the escape hatches. A move not on the TRANSITIONS map is refused, so an
 * action cannot jump straight from Open to Closed without being verified.
 */
final class MomActionStatus
{
    public const OPEN                 = 'Open';
    public const IN_PROGRESS          = 'In_Progress';
    public const PENDING_VERIFICATION = 'Pending_Verification';
    public const CLOSED               = 'Closed';
    public const REOPENED             = 'Reopened';
    public const CANCELLED            = 'Cancelled';

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

    /** States where the action is still live (counts as an open/overdue action). */
    public const OPEN_STATES = [
        self::OPEN, self::IN_PROGRESS, self::PENDING_VERIFICATION, self::REOPENED,
    ];

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
        return self::LABELS[$v] ?? (string) $v;
    }

    public static function isValid(?string $v): bool
    {
        return in_array($v, self::ALL, true);
    }

    public static function isOpen(?string $v): bool
    {
        return in_array($v, self::OPEN_STATES, true);
    }

    public static function canTransition(?string $from, ?string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
