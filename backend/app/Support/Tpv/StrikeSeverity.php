<?php

namespace App\Support\Tpv;

/**
 * Safety-strike severity and the termination policy.
 *
 * Three active strikes of any severity terminate site access. A Critical strike
 * terminates immediately on its own — some violations don't get three chances.
 */
final class StrikeSeverity
{
    public const MINOR    = 'Minor';
    public const MAJOR    = 'Major';
    public const CRITICAL = 'Critical';

    public const ALL = [self::MINOR, self::MAJOR, self::CRITICAL];

    public const LABELS = [
        self::MINOR    => 'Minor',
        self::MAJOR    => 'Major',
        self::CRITICAL => 'Critical',
    ];

    /** Active strikes at which site access is terminated automatically. */
    public const LIMIT = 3;

    /** Active strikes at which the gate starts warning (one away from the limit). */
    public const WARN_AT = self::LIMIT - 1;

    public static function label(?string $s): string
    {
        return self::LABELS[$s] ?? (string) $s;
    }

    /** A Critical strike terminates on its own, without reaching the limit. */
    public static function terminatesImmediately(?string $s): bool
    {
        return $s === self::CRITICAL;
    }

    public static function isValid(?string $s): bool
    {
        return in_array($s, self::ALL, true);
    }
}
