<?php

namespace App\Support\Purchase;

/**
 * Purchase catalog (item master) lifecycle.
 *
 * Draft (being set up) → Active (selectable on procurement lines) →
 * Discontinued (retired; not selectable on NEW lines, but historic lines that
 * reference it still resolve). Reactivation Discontinued → Active is allowed.
 *
 * Stored on purchase_catalog_items.status as a plain string.
 */
final class PurchaseCatalogStatus
{
    public const DRAFT        = 'Draft';
    public const ACTIVE       = 'Active';
    public const DISCONTINUED = 'Discontinued';

    public const ALL = [self::DRAFT, self::ACTIVE, self::DISCONTINUED];

    /** The only status an item may be selected on a new procurement line. */
    public const SELECTABLE = [self::ACTIVE];

    public const LABELS = [
        self::DRAFT        => 'Draft',
        self::ACTIVE       => 'Active',
        self::DISCONTINUED => 'Discontinued',
    ];

    public static function label(?string $s): string
    {
        return self::LABELS[$s] ?? (string) $s;
    }

    public static function isValid(?string $s): bool
    {
        return in_array($s, self::ALL, true);
    }

    /** May this item be picked onto a new PR/RFQ/PO line? */
    public static function isSelectable(?string $s): bool
    {
        return in_array($s, self::SELECTABLE, true);
    }
}
