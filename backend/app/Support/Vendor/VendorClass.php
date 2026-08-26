<?php

namespace App\Support\Vendor;

/**
 * §5 Vendor class — what kind of third party this is. Was a free string; this
 * catalogue turns it into a validated vocabulary. Kept lenient (nullable) so
 * existing rows without a class are unaffected.
 */
final class VendorClass
{
    public const MANUFACTURER    = 'Manufacturer';
    public const DISTRIBUTOR     = 'Distributor';
    public const SERVICE_PROVIDER = 'Service_Provider';
    public const CONTRACTOR      = 'Contractor';
    public const CONSULTANT      = 'Consultant';
    public const SUPPLIER        = 'Supplier';
    public const OTHER           = 'Other';

    public const ALL = [
        self::MANUFACTURER, self::DISTRIBUTOR, self::SERVICE_PROVIDER,
        self::CONTRACTOR, self::CONSULTANT, self::SUPPLIER, self::OTHER,
    ];

    public static function label(?string $v): string
    {
        return $v ? str_replace('_', ' ', $v) : 'Unclassified';
    }
}
