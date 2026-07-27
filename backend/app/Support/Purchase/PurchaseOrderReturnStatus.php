<?php

namespace App\Support\Purchase;

/**
 * A Purchase Order Return's lifecycle: Draft → Issued → Completed, or Cancelled.
 * Stored on purchase_order_returns.status as a plain string. Independent of the
 * debit-note lifecycle.
 */
final class PurchaseOrderReturnStatus
{
    public const DRAFT     = 'Draft';
    public const ISSUED    = 'Issued';
    public const COMPLETED = 'Completed';
    public const CANCELLED = 'Cancelled';

    public const ALL = [
        self::DRAFT, self::ISSUED, self::COMPLETED, self::CANCELLED,
    ];

    public const LABELS = [
        self::DRAFT     => 'Draft',
        self::ISSUED    => 'Issued',
        self::COMPLETED => 'Completed',
        self::CANCELLED => 'Cancelled',
    ];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? (string) $status;
    }

    public static function isValid(?string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    /** Only a Draft may be edited or deleted. */
    public static function isEditable(?string $status): bool
    {
        return $status === self::DRAFT;
    }
}
