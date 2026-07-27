<?php

namespace App\Support\Hr;

/**
 * Single source of truth for the Manpower Request workflow states.
 *
 * The recruitment workflow moves a request through:
 *   Draft → L1_Pending → L2_Pending → Ready_for_HR
 *         → Converted_to_JD → Job_Posted → Hiring_in_Progress → Closed
 * with Rejected as a terminal branch off either approval level.
 *
 * Stored on hr_manpower_requests.status as a plain string so existing API
 * consumers keep receiving strings (no enum casting on the column).
 */
final class ManpowerRequestStatus
{
    public const DRAFT              = 'Draft';
    public const SUBMITTED          = 'Submitted';       // transient label (logged in history)
    public const L1_PENDING         = 'L1_Pending';
    public const L1_APPROVED        = 'L1_Approved';     // transient label (logged in history)
    public const L2_PENDING         = 'L2_Pending';
    public const L2_APPROVED        = 'L2_Approved';      // transient label (logged in history)
    public const READY_FOR_HR       = 'Ready_for_HR';
    public const CONVERTED_TO_JD    = 'Converted_to_JD';
    public const JOB_POSTED         = 'Job_Posted';
    public const HIRING_IN_PROGRESS = 'Hiring_in_Progress';
    public const CLOSED             = 'Closed';
    public const REJECTED           = 'Rejected';

    /** Canonical persisted statuses (what the `status` column actually holds). */
    public const ALL = [
        self::DRAFT,
        self::L1_PENDING,
        self::L2_PENDING,
        self::READY_FOR_HR,
        self::CONVERTED_TO_JD,
        self::JOB_POSTED,
        self::HIRING_IN_PROGRESS,
        self::CLOSED,
        self::REJECTED,
    ];

    /**
     * Statuses that belong in the HR queue — the request has cleared both
     * approvals and HR now owns it. (Everything from Ready-for-HR onward.)
     */
    public const HR_QUEUE = [
        self::READY_FOR_HR,
        self::CONVERTED_TO_JD,
        self::JOB_POSTED,
        self::HIRING_IN_PROGRESS,
    ];

    /** Human-readable labels for UI/history. */
    public const LABELS = [
        self::DRAFT              => 'Draft',
        self::SUBMITTED          => 'Submitted',
        self::L1_PENDING         => 'L1 Pending',
        self::L1_APPROVED        => 'L1 Approved',
        self::L2_PENDING         => 'L2 Pending',
        self::L2_APPROVED        => 'L2 Approved',
        self::READY_FOR_HR       => 'Ready for HR',
        self::CONVERTED_TO_JD    => 'Converted to JD',
        self::JOB_POSTED         => 'Job Posted',
        self::HIRING_IN_PROGRESS => 'Hiring in Progress',
        self::CLOSED             => 'Closed',
        self::REJECTED           => 'Rejected',
    ];

    /**
     * Maps the legacy status vocabulary (pre-enhancement) to the new canonical
     * values. Used by the enhancement migration to remap existing rows.
     */
    public const LEGACY_MAP = [
        'Pending'    => self::L1_PENDING,
        'Pending_L1' => self::L1_PENDING,
        'Pending_L2' => self::L2_PENDING,
        'Approved'   => self::READY_FOR_HR,
    ];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? (string) $status;
    }

    /** A request is fully approved once it reaches (or passes) Ready-for-HR. */
    public static function isFullyApproved(?string $status): bool
    {
        return in_array($status, self::HR_QUEUE, true);
    }
}
