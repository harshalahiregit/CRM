<?php

namespace App\Support\Purchase;

/**
 * Single source of truth for Purchase Order lifecycle states.
 *
 * Draft → Issued → Partially_Received → Received → Closed, with Cancelled as a
 * terminal state. Received progression is driven by confirmed Goods Receipts.
 *
 * Stored on purchase_orders.status as a plain string (no enum cast) so API
 * consumers keep receiving strings. Mirrors PurchaseRequestStatus.
 */
final class PurchaseOrderStatus
{
    public const DRAFT              = 'Draft';
    public const ISSUED             = 'Issued';
    public const PARTIALLY_RECEIVED = 'Partially_Received';
    public const RECEIVED           = 'Received';
    public const CLOSED             = 'Closed';
    public const CANCELLED          = 'Cancelled';

    /** All persisted statuses. */
    public const ALL = [
        self::DRAFT, self::ISSUED, self::PARTIALLY_RECEIVED,
        self::RECEIVED, self::CLOSED, self::CANCELLED,
    ];

    /** Statuses where the buyer may still edit line items. */
    public const EDITABLE = [
        self::DRAFT,
    ];

    /** Statuses that accept incoming Goods Receipts. */
    public const RECEIVABLE = [
        self::ISSUED, self::PARTIALLY_RECEIVED,
    ];

    /** Human-readable labels. */
    public const LABELS = [
        self::DRAFT              => 'Draft',
        self::ISSUED             => 'Issued',
        self::PARTIALLY_RECEIVED => 'Partially Received',
        self::RECEIVED           => 'Received',
        self::CLOSED             => 'Closed',
        self::CANCELLED          => 'Cancelled',
    ];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? (string) $status;
    }

    public static function isEditable(?string $status): bool
    {
        return in_array($status, self::EDITABLE, true);
    }

    public static function isReceivable(?string $status): bool
    {
        return in_array($status, self::RECEIVABLE, true);
    }

    public static function isValid(?string $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}
