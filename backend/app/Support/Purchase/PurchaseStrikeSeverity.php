<?php

namespace App\Support\Purchase;

/**
 * Safety-strike severity and the termination policy, Purchase side.
 *
 * Three active strikes of any severity terminate site access. A Critical strike
 * terminates on its own — some violations do not get three chances.
 *
 * A deliberate mirror of App\Support\Tpv\StrikeSeverity rather than a shared
 * class. Purchase and TPV are separate registers by design and neither module
 * reaches into the other's support namespace; PurchaseMedicalFitness mirrors
 * TpvMedicalFitness for exactly the same reason. The vocabulary is identical on
 * purpose — a Minor strike has to mean the same thing to a worker on either
 * side of the fence — so the two files are kept word for word in step.
 */
final class PurchaseStrikeSeverity
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
