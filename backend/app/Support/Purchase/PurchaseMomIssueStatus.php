<?php

namespace App\Support\Purchase;

/**
 * Lifecycle of a Purchase MOM issue (Sangoe TPV §9 — issues raised in a meeting
 * that need tracking or escalation).
 *
 * Open → In Progress → Resolved → Closed, with Reopened and Cancelled reachable
 * per the transition map. An issue may also be converted to an NCR or a CAPA
 * (tracked separately on converted_to, orthogonal to status). A move not on the
 * TRANSITIONS map is refused.
 *
 * Purchase-owned mirror of the shared MeetingIssueStatus — the two engines never
 * share code or tables. Stored on purchase_mom_issues.status.
 */
final class PurchaseMomIssueStatus
{
    public const OPEN = 'Open';

    public const IN_PROGRESS = 'In_Progress';

    public const RESOLVED = 'Resolved';

    public const CLOSED = 'Closed';

    public const REOPENED = 'Reopened';

    public const CANCELLED = 'Cancelled';

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

    /** States where the issue is still live (counts as outstanding). */
    public const OPEN_STATES = [self::OPEN, self::IN_PROGRESS, self::REOPENED];

    public const TRANSITIONS = [
        self::OPEN        => [self::IN_PROGRESS, self::RESOLVED, self::CANCELLED],
        self::IN_PROGRESS => [self::OPEN, self::RESOLVED, self::CANCELLED],
        self::RESOLVED    => [self::IN_PROGRESS, self::CLOSED, self::REOPENED],
        self::CLOSED      => [self::REOPENED],
        self::REOPENED    => [self::IN_PROGRESS, self::RESOLVED, self::CANCELLED],
        self::CANCELLED   => [self::REOPENED],
    ];

    public const SEVERITIES = ['Low', 'Medium', 'High', 'Critical'];

    public const CATEGORIES = ['Safety', 'Compliance', 'Quality', 'Commercial', 'Workforce', 'Schedule', 'Technical', 'Environmental', 'Other'];

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

    /** Map an issue severity onto the NCR severity vocab (Minor/Major/Critical). */
    public static function ncrSeverityFor(?string $severity): string
    {
        return match ($severity) {
            'Critical' => 'Critical',
            'High', 'Medium' => 'Major',
            default => 'Minor',
        };
    }
}
