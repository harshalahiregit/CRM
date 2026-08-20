<?php

namespace App\Support\Shared;

/**
 * Lifecycle of a meeting issue (Meeting.docx §10).
 *
 * Open → In Progress → Resolved → Closed, with Reopened and Cancelled. A move not
 * on the TRANSITIONS map is refused.
 */
final class MeetingIssueStatus
{
    public const OPEN        = 'Open';
    public const IN_PROGRESS = 'In_Progress';
    public const RESOLVED    = 'Resolved';
    public const CLOSED      = 'Closed';
    public const REOPENED    = 'Reopened';
    public const CANCELLED   = 'Cancelled';

    public const ALL = [
        self::OPEN, self::IN_PROGRESS, self::RESOLVED,
        self::CLOSED, self::REOPENED, self::CANCELLED,
    ];

    public const LABELS = [
        self::OPEN        => 'Open',
        self::IN_PROGRESS => 'In Progress',
        self::RESOLVED    => 'Resolved',
        self::CLOSED      => 'Closed',
        self::REOPENED    => 'Reopened',
        self::CANCELLED   => 'Cancelled',
    ];

    /** States that still count as an open/overdue issue. */
    public const OPEN_STATES = [self::OPEN, self::IN_PROGRESS, self::REOPENED];

    public const TRANSITIONS = [
        self::OPEN        => [self::IN_PROGRESS, self::RESOLVED, self::CANCELLED],
        self::IN_PROGRESS => [self::OPEN, self::RESOLVED, self::CANCELLED],
        self::RESOLVED    => [self::IN_PROGRESS, self::CLOSED, self::REOPENED],
        self::CLOSED      => [self::REOPENED],
        self::REOPENED    => [self::IN_PROGRESS, self::RESOLVED, self::CANCELLED],
        self::CANCELLED   => [self::REOPENED],
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
