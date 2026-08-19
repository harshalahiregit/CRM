<?php

namespace App\Support\Vendor;

/**
 * Single source of truth for Vendor lifecycle states.
 *
 * Draft → Pending_Approval → Active, with On_Hold / Rejected / Blacklisted as
 * branch/terminal states.
 *
 * Stored on vendors.status as a plain string (no enum cast) so API consumers
 * keep receiving strings. Mirrors JobPostingStatus.
 */
final class VendorStatus
{
    public const DRAFT            = 'Draft';
    public const PENDING_APPROVAL = 'Pending_Approval';
    public const ACTIVE           = 'Active';
    public const INACTIVE         = 'Inactive';
    public const ON_HOLD          = 'On_Hold';
    public const REJECTED         = 'Rejected';
    public const BLACKLISTED      = 'Blacklisted';
    // Compliance suspension — an active vendor whose statutory cover lapsed
    // (expired insurance/licence, a fatal incident, a stop-work). Reversible:
    // Suspended → Active once the breach is cleared. Offboarded is terminal
    // (engagement ended). Both lock the login out (loginStatusFor).
    public const SUSPENDED        = 'Suspended';
    public const OFFBOARDED       = 'Offboarded';

    /** All persisted statuses. */
    public const ALL = [
        self::DRAFT, self::PENDING_APPROVAL, self::ACTIVE, self::INACTIVE,
        self::ON_HOLD, self::REJECTED, self::BLACKLISTED, self::SUSPENDED, self::OFFBOARDED,
    ];

    /** Statuses where the vendor may be transacted with (PR/PO, site access). */
    public const ENGAGEABLE = [
        self::ACTIVE,
    ];

    /** Human-readable labels. */
    public const LABELS = [
        self::DRAFT            => 'Draft',
        self::PENDING_APPROVAL => 'Pending Approval',
        self::ACTIVE           => 'Active',
        self::INACTIVE         => 'Inactive',
        self::ON_HOLD          => 'On Hold',
        self::REJECTED         => 'Rejected',
        self::BLACKLISTED      => 'Blacklisted',
        self::SUSPENDED        => 'Suspended',
        self::OFFBOARDED       => 'Offboarded',
    ];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? (string) $status;
    }

    public static function isEngageable(?string $status): bool
    {
        return in_array($status, self::ENGAGEABLE, true);
    }

    public static function isValid(?string $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}
