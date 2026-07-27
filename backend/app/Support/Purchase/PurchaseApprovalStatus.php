<?php

namespace App\Support\Purchase;

/**
 * Decision state of a single Purchase approval stage. Stored on
 * purchase_approvals.status as a plain string. Purchase-owned.
 */
final class PurchaseApprovalStatus
{
    public const PENDING  = 'Pending';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';

    public const ALL = [self::PENDING, self::APPROVED, self::REJECTED];

    public const LABELS = [
        self::PENDING  => 'Pending',
        self::APPROVED => 'Approved',
        self::REJECTED => 'Rejected',
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
