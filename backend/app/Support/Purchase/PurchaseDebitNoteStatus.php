<?php

namespace App\Support\Purchase;

/**
 * Single source of truth for Purchase Debit Note lifecycle states.
 *
 * Draft → Open (issued; inventory adjusted, claim opened) → Settled (fully
 * refunded), with Cancelled as a terminal state. Refund progression drives
 * Open → Settled.
 *
 * Stored on purchase_debit_notes.status as a plain string. Mirrors PurchaseOrderStatus.
 */
final class PurchaseDebitNoteStatus
{
    public const DRAFT     = 'Draft';
    public const OPEN      = 'Open';
    public const SETTLED   = 'Settled';
    public const CANCELLED = 'Cancelled';

    /** All persisted statuses. */
    public const ALL = [
        self::DRAFT, self::OPEN, self::SETTLED, self::CANCELLED,
    ];

    /** Statuses where line items may still be edited. */
    public const EDITABLE = [
        self::DRAFT,
    ];

    /** Statuses that accept vendor refunds. */
    public const REFUNDABLE = [
        self::OPEN,
    ];

    /** Human-readable labels. */
    public const LABELS = [
        self::DRAFT     => 'Draft',
        self::OPEN      => 'Open',
        self::SETTLED   => 'Settled',
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

    public static function isRefundable(?string $status): bool
    {
        return in_array($status, self::REFUNDABLE, true);
    }

    public static function isValid(?string $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}
