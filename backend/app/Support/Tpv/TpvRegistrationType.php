<?php

namespace App\Support\Tpv;

/**
 * How a Third-Party Vendor entered the system. Stored verbatim on
 * vendors.registration_type at registration — never inferred afterwards from
 * vendor_type, is_temporary or the access window.
 *
 * TPV-owned: Purchase has its own separate
 * App\Support\Purchase\PurchaseRegistrationType. Neither references the other.
 */
final class TpvRegistrationType
{
    public const LONG_TERM = 'long_term_tpv';
    public const TEMPORARY = 'temporary_tpv';

    public const ALL = [self::LONG_TERM, self::TEMPORARY];

    /** Records created before this column existed read as Long-Term TPV. */
    public const DEFAULT = self::LONG_TERM;

    public const LABELS = [
        self::LONG_TERM => 'Long-Term TPV',
        self::TEMPORARY => 'Temporary TPV',
    ];

    public static function label(?string $type): string
    {
        return self::LABELS[$type] ?? self::LABELS[self::DEFAULT];
    }

    public static function isValid(?string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    /** Normalise the registration form's tpv_type ('permanent'|'temporary'). */
    public static function normalize(?string $type): string
    {
        if (self::isValid($type)) {
            return $type;
        }

        return $type === 'temporary' ? self::TEMPORARY : self::DEFAULT;
    }
}
