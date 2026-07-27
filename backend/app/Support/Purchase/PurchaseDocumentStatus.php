<?php

namespace App\Support\Purchase;

/**
 * Single source of truth for a Purchase-vendor document's review state.
 *
 * Under_Review (uploaded, awaiting review) → Approved | Rejected. A rejected
 * document is resubmitted, returning it to Under_Review. Expired is set when a
 * document's expiry date has passed. Stored on purchase_documents.status.
 */
final class PurchaseDocumentStatus
{
    public const UNDER_REVIEW = 'Under_Review';
    public const APPROVED     = 'Approved';
    public const REJECTED     = 'Rejected';
    public const EXPIRED      = 'Expired';

    public const ALL = [
        self::UNDER_REVIEW, self::APPROVED, self::REJECTED, self::EXPIRED,
    ];

    public const LABELS = [
        self::UNDER_REVIEW => 'Under Review',
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
