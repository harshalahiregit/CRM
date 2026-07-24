<?php

namespace App\Support\Tpv;

/**
 * Single source of truth for TPV onboarding workflow states.
 *
 * Draft → In_Progress → Submitted → Under_Review → Approved, with
 * Resubmit_Required / Rejected as branch/terminal states.
 *
 * Stored on tpv_onboardings.status as a plain string (no enum cast) so API
 * consumers keep receiving strings. Mirrors JobPostingStatus.
 */
final class TpvOnboardingStatus
{
    public const DRAFT             = 'Draft';
    public const IN_PROGRESS       = 'In_Progress';
    public const SUBMITTED         = 'Submitted';
    public const UNDER_REVIEW      = 'Under_Review';
    public const RESUBMIT_REQUIRED = 'Resubmit_Required';
    public const ON_HOLD           = 'On_Hold';
    public const APPROVED          = 'Approved';
    public const REJECTED          = 'Rejected';

    /** All persisted statuses. */
    public const ALL = [
        self::DRAFT, self::IN_PROGRESS, self::SUBMITTED, self::UNDER_REVIEW,
        self::RESUBMIT_REQUIRED, self::ON_HOLD, self::APPROVED, self::REJECTED,
    ];

    /** Statuses where the vendor may still edit their submission. */
    public const EDITABLE = [
        self::DRAFT, self::IN_PROGRESS, self::RESUBMIT_REQUIRED,
    ];

    /** States an admin can act on from the Final Approval screen. */
    public const DECIDABLE = [
        self::SUBMITTED, self::UNDER_REVIEW,
    ];

    /** Total steps in the onboarding wizard. */
    public const TOTAL_STEPS = 6;

    /** Human-readable labels. */
    public const LABELS = [
        self::DRAFT             => 'Draft',
        self::IN_PROGRESS       => 'In Progress',
        self::SUBMITTED         => 'Submitted',
        self::UNDER_REVIEW      => 'Under Review',
        self::RESUBMIT_REQUIRED => 'Resubmit Required',
        self::ON_HOLD           => 'On Hold',
        self::APPROVED          => 'Approved',
        self::REJECTED          => 'Rejected',
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
