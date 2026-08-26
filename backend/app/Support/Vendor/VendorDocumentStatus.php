<?php

namespace App\Support\Vendor;

/**
 * Single source of truth for a vendor statutory document's review state.
 *
 * Under_Review (uploaded, awaiting review) → Approved | Rejected. A rejected
 * document is resubmitted, returning it to Under_Review. Expired is set when a
 * document's expiry date has passed.
 *
 * Stored on vendor_documents.status as a plain string. Mirrors the other
 * purchase/HR status enums.
 */
final class VendorDocumentStatus
{
    public const UNDER_REVIEW = 'Under_Review';
    // §30 — an intermediate state: the reviewer has verified the file is genuine
    // and legible, but the authority has not yet approved it.
    public const VERIFIED     = 'Verified';
    public const APPROVED     = 'Approved';
    public const REJECTED     = 'Rejected';
    public const EXPIRED      = 'Expired';

    /** All persisted statuses. */
    public const ALL = [
        self::UNDER_REVIEW, self::VERIFIED, self::APPROVED, self::REJECTED, self::EXPIRED,
    ];

    /** Human-readable labels. */
    public const LABELS = [
        self::UNDER_REVIEW => 'Under Review',
        self::VERIFIED     => 'Verified',
        self::APPROVED     => 'Approved',
        self::REJECTED     => 'Rejected',
        self::EXPIRED      => 'Expired',
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
