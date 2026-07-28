<?php

namespace App\Support\Purchase;

/**
 * How a Purchase Vendor entered the system. Stored verbatim on
 * purchase_vendors.registration_type at creation — never inferred afterwards
 * from vendor_type, access windows or any other field.
 *
 * Purchase-owned: TPV has its own separate App\Support\Tpv\TpvRegistrationType.
 * Neither references the other.
 */
final class PurchaseRegistrationType
{
    public const STANDARD  = 'standard_vendor';
    public const TEMPORARY = 'temporary_vendor';

    public const ALL = [self::STANDARD, self::TEMPORARY];

    /** Records created before this column existed read as Standard Vendor. */
    public const DEFAULT = self::STANDARD;

    public const LABELS = [
        self::STANDARD  => 'Standard Vendor',
        self::TEMPORARY => 'Temporary Vendor',
    ];

    public static function label(?string $type): string
    {
        return self::LABELS[$type] ?? self::LABELS[self::DEFAULT];
    }

    public static function isValid(?string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    /** Normalise whatever a caller supplies into a valid stored value. */
    public static function normalize(?string $type): string
    {
        if (self::isValid($type)) {
            return $type;
        }

        // Accept the registration form's shorthand ('standard' | 'temporary').
        return $type === 'temporary' ? self::TEMPORARY : self::DEFAULT;
    }
}
