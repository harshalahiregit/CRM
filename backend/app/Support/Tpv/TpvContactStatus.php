<?php

namespace App\Support\Tpv;

/**
 * A TPV vendor contact's lifecycle. Contacts are never hard-deleted; status is
 * the soft toggle between Active and Inactive.
 *
 * Stored on tpv_contacts.status as a plain string.
 */
final class TpvContactStatus
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
