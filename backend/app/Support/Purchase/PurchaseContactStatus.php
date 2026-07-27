<?php

namespace App\Support\Purchase;

/**
 * A Purchase-vendor contact's lifecycle. Contacts are never hard-deleted; status
 * is the soft toggle between Active and Inactive. Stored on
 * purchase_contacts.status as a plain string.
 */
final class PurchaseContactStatus
{
    public const ACTIVE   = 'Active';
    public const INACTIVE = 'Inactive';

    public const ALL = [
        self::ACTIVE, self::INACTIVE,
    ];

    public const LABELS = [
        self::ACTIVE   => 'Active',
        self::INACTIVE => 'Inactive',
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
