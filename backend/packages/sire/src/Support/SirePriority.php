<?php

namespace Sire\Support;

/**
 * SIRE — priority.
 *
 * Severity and priority are different axes and the developer view shows both:
 *   severity = how bad it is when it happens        (sire_severities, per tenant, carries SLA targets)
 *   priority = when we are going to deal with it    (this, a fixed scale)
 *
 * A cosmetic bug on the login page can be low severity and P1 priority. Folding
 * them into one field loses that, which is why there are two.
 */
final class SirePriority
{
    public const P1 = 'p1';
    public const P2 = 'p2';
    public const P3 = 'p3';
    public const P4 = 'p4';

    public const ALL = [self::P1, self::P2, self::P3, self::P4];

    public const LABELS = [
        self::P1 => 'P1 — Urgent',
        self::P2 => 'P2 — High',
        self::P3 => 'P3 — Medium',
        self::P4 => 'P4 — Low',
    ];

    public static function label(?string $priority): ?string
    {
        return $priority ? (self::LABELS[$priority] ?? $priority) : null;
    }
}
