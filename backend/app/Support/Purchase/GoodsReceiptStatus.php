<?php

namespace App\Support\Purchase;

/**
 * Single source of truth for Goods Receipt (GRN) states.
 *
 * Draft → Confirmed, with Cancelled as a terminal state. Only confirming rolls
 * quantities up to the Purchase Order — a Draft GRN affects nothing.
 *
 * Stored on goods_receipts.status as a plain string. Mirrors PurchaseOrderStatus.
 */
final class GoodsReceiptStatus
{
    public const DRAFT     = 'Draft';
    public const CONFIRMED = 'Confirmed';
    public const CANCELLED = 'Cancelled';

    /** All persisted statuses. */
    public const ALL = [
        self::DRAFT, self::CONFIRMED, self::CANCELLED,
    ];

    /** Statuses where the receiver may still edit received lines. */
    public const EDITABLE = [
        self::DRAFT,
    ];

    /** Human-readable labels. */
    public const LABELS = [
        self::DRAFT     => 'Draft',
        self::CONFIRMED => 'Confirmed',
        self::CANCELLED => 'Cancelled',
    ];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? (string) $status;
    }

    public static function isEditable(?string $status): bool
    {
        return in_array($status, self::EDITABLE, true);
    }

    public static function isValid(?string $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}
