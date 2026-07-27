<?php

namespace App\Support\Compliance;

/**
 * Single source of truth for a compliance checklist's lifecycle.
 *
 * Draft → Assigned → Submitted → Manager_Approved → Approved, with Rejected
 * reachable from any tier of the signature chain. Rejected is not terminal:
 * a rejected safety checklist has to be reworked, and reopening preserves the
 * original responses and the rejection remark rather than discarding the
 * evidence into a fresh instance.
 *
 * Stored on compliance_checklists.status as a plain string (no enum cast) so
 * API consumers keep receiving strings. Mirrors TpvOnboardingStatus.
 */
final class ComplianceStatus
{
    public const DRAFT            = 'Draft';
    public const ASSIGNED         = 'Assigned';
    public const SUBMITTED        = 'Submitted';
    public const MANAGER_APPROVED = 'Manager_Approved';
    public const APPROVED         = 'Approved';
    public const REJECTED         = 'Rejected';

    public const ALL = [
        self::DRAFT, self::ASSIGNED, self::SUBMITTED,
        self::MANAGER_APPROVED, self::APPROVED, self::REJECTED,
    ];

    public const LABELS = [
        self::DRAFT            => 'Draft',
        self::ASSIGNED         => 'Assigned',
        self::SUBMITTED        => 'Submitted',
        self::MANAGER_APPROVED => 'Manager Approved',
        self::APPROVED         => 'Approved',
        self::REJECTED         => 'Rejected',
    ];

    /** Statuses where the assignee may still change their answers. */
    public const FILLABLE = [self::ASSIGNED];

    /** Statuses where the issuer may still edit the assignment itself. */
    public const EDITABLE = [self::DRAFT];

    /** Nothing further happens to these without an explicit reopen. */
    public const CLOSED = [self::APPROVED, self::REJECTED];

    /**
     * The permitted moves. Anything not listed here is refused by the service,
     * so a caller can never skip a tier of the signature chain by posting the
     * status it wants.
     */
    public const TRANSITIONS = [
        self::DRAFT            => [self::ASSIGNED],
        self::ASSIGNED         => [self::SUBMITTED],
        self::SUBMITTED        => [self::MANAGER_APPROVED, self::REJECTED],
        self::MANAGER_APPROVED => [self::APPROVED, self::REJECTED],
        self::APPROVED         => [],
        // Rework path — a rejected checklist goes back to the assignee.
        self::REJECTED         => [self::ASSIGNED],
    ];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? (string) $status;
    }

    public static function isValid(?string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function isFillable(?string $status): bool
    {
        return in_array($status, self::FILLABLE, true);
    }

    public static function isClosed(?string $status): bool
    {
        return in_array($status, self::CLOSED, true);
    }

    public static function canTransition(?string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
