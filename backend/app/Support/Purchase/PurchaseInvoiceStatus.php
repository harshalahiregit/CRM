<?php

namespace App\Support\Purchase;

/**
 * Single source of truth for Purchase Invoice lifecycle states.
 *
 * Draft → Awaiting_Payment → Partially_Paid → Paid, with Cancelled as a terminal
 * state. Paid progression is driven by recorded payments (amount_paid vs total).
 *
 * Stored on purchase_invoices.status as a plain string. Mirrors PurchaseOrderStatus.
 */
final class PurchaseInvoiceStatus
{
    public const DRAFT            = 'Draft';
    public const AWAITING_PAYMENT = 'Awaiting_Payment';
    public const PARTIALLY_PAID   = 'Partially_Paid';
    public const PAID             = 'Paid';
    public const CANCELLED        = 'Cancelled';

    /** All persisted statuses. */
    public const ALL = [
        self::DRAFT, self::AWAITING_PAYMENT, self::PARTIALLY_PAID, self::PAID, self::CANCELLED,
    ];

    /** Statuses where line items may still be edited. */
    public const EDITABLE = [
        self::DRAFT,
    ];

    /** Statuses that accept payments. */
    public const PAYABLE = [
        self::AWAITING_PAYMENT, self::PARTIALLY_PAID,
    ];

    /** Human-readable labels. */
    public const LABELS = [
        self::DRAFT            => 'Draft',
        self::AWAITING_PAYMENT => 'Awaiting Payment',
        self::PARTIALLY_PAID   => 'Partially Paid',
        self::PAID             => 'Paid',
        self::CANCELLED        => 'Cancelled',
    ];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? (string) $status;
    }

    public static function isEditable(?string $status): bool
    {
        return in_array($status, self::EDITABLE, true);
    }

    public static function isPayable(?string $status): bool
    {
        return in_array($status, self::PAYABLE, true);
    }

    public static function isValid(?string $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}
