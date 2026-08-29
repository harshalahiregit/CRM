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
    // §5 lifecycle stages the doc names before a vendor is fully active. All
    // additive and optional — the core Draft→Pending_Approval→Active flow is
    // unchanged; these give finer-grained states where a tenant wants them.
    //
    // Intentionally NOT auto-assigned by the standard flow: the mid-registration
    // granularity (Submitted → Under_Review → Approved) lives on the ONBOARDING
    // record (TpvOnboardingStatus), which is the system of record for review
    // progress; the vendor's own status stays coarse. Kept here as reserved,
    // valid target states for tenants/integrations that drive them explicitly.
    public const INVITED          = 'Invited';
    public const REGISTERED       = 'Registered';
    public const UNDER_REVIEW     = 'Under_Review';
    public const APPROVED         = 'Approved';
    public const EXPIRED          = 'Expired';
    // Compliance suspension — an active vendor whose statutory cover lapsed
    // (expired insurance/licence, a fatal incident, a stop-work). Reversible:
    // Suspended → Active once the breach is cleared. Offboarded is terminal
    // (engagement ended). Both lock the login out (loginStatusFor).
    public const SUSPENDED        = 'Suspended';
    public const OFFBOARDED       = 'Offboarded';

    /** All persisted statuses. */
    public const ALL = [
        self::INVITED, self::REGISTERED, self::DRAFT, self::PENDING_APPROVAL,
        self::UNDER_REVIEW, self::APPROVED, self::ACTIVE, self::INACTIVE,
        self::ON_HOLD, self::REJECTED, self::BLACKLISTED, self::SUSPENDED,
        self::EXPIRED, self::OFFBOARDED,
    ];

    /** Statuses where the vendor may be transacted with (PR/PO, site access). */
    public const ENGAGEABLE = [
        self::ACTIVE,
    ];

    /** Human-readable labels. */
    public const LABELS = [
        self::INVITED          => 'Invited',
        self::REGISTERED       => 'Registered',
        self::DRAFT            => 'Draft',
        self::PENDING_APPROVAL => 'Pending Approval',
        self::UNDER_REVIEW     => 'Under Review',
        self::APPROVED         => 'Approved',
        self::ACTIVE           => 'Active',
        self::INACTIVE         => 'Inactive',
        self::ON_HOLD          => 'On Hold',
        self::REJECTED         => 'Rejected',
        self::BLACKLISTED      => 'Blacklisted',
        self::SUSPENDED        => 'Suspended',
        self::EXPIRED          => 'Expired',
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
