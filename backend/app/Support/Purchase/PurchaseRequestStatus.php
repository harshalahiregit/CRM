<?php

namespace App\Support\Purchase;

/**
 * Single source of truth for Purchase Request lifecycle states.
 *
 * Draft → Submitted → Approved → Converted (to PO), with Rejected / Cancelled
 * as terminal states.
 *
 * Stored on purchase_requests.status as a plain string (no enum cast) so API
 * consumers keep receiving strings. Mirrors JobPostingStatus.
 */
final class PurchaseRequestStatus
{
    public const DRAFT     = 'Draft';
    public const SUBMITTED = 'Submitted';
    public const APPROVED  = 'Approved';
    public const CONVERTED = 'Converted';
    public const REJECTED  = 'Rejected';
    public const CANCELLED = 'Cancelled';

    /** All persisted statuses. */
    public const ALL = [
        self::DRAFT, self::SUBMITTED, self::APPROVED,
        self::CONVERTED, self::REJECTED, self::CANCELLED,
    ];

    /** Statuses where the requester may still edit line items. */
    public const EDITABLE = [
        self::DRAFT,
    ];

    /** Human-readable labels. */
    public const LABELS = [
        self::DRAFT     => 'Draft',
        self::SUBMITTED => 'Submitted',
        self::APPROVED  => 'Approved',
        self::CONVERTED => 'Converted to PO',
        self::REJECTED  => 'Rejected',
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
