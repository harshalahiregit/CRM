<?php

namespace App\Support\Purchase;

/**
 * A Purchase Vendor ↔ Inventory Item mapping's lifecycle. Stored on
 * purchase_vendor_items.status as a plain string. The mapping is Purchase-owned;
 * this status describes the SUPPLY RELATIONSHIP only — it never touches the
 * Inventory item's own status.
 */
final class PurchaseVendorItemStatus
{
    public const ACTIVE   = 'Active';
    public const INACTIVE = 'Inactive';

    public const ALL = [
        self::ACTIVE, self::INACTIVE,
    ];

    public const LABELS = [
        self::ACTIVE   => 'Active',
        self::INACTIVE => 'Inactive',
    ];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? (string) $status;
    }

    public static function isValid(?string $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}
