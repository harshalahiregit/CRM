<?php

namespace App\Support\Hr;

/**
 * Single source of truth for Job Posting lifecycle states.
 *
 * Draft → Ready_for_HR → Published → Hiring → Partially_Filled → Completed
 * with Closed / Cancelled / On_Hold as branch/terminal states.
 *
 * Stored on hr_job_postings.status as a plain string (no enum cast) so API
 * consumers keep receiving strings. Mirrors ManpowerRequestStatus.
 */
final class JobPostingStatus
{
    public const DRAFT            = 'Draft';
    public const READY_FOR_HR     = 'Ready_for_HR';
    public const PUBLISHED        = 'Published';
    public const HIRING           = 'Hiring';
    public const PARTIALLY_FILLED = 'Partially_Filled';
    public const COMPLETED        = 'Completed';
    public const CLOSED           = 'Closed';
    public const CANCELLED        = 'Cancelled';
    public const ON_HOLD          = 'On_Hold';

    /** All persisted statuses. */
    public const ALL = [
        self::DRAFT, self::READY_FOR_HR, self::PUBLISHED, self::HIRING,
        self::PARTIALLY_FILLED, self::COMPLETED, self::CLOSED, self::CANCELLED, self::ON_HOLD,
    ];

    /** Statuses where the job is publicly live / accepting candidates. */
    public const LIVE = [
        self::PUBLISHED, self::HIRING, self::PARTIALLY_FILLED,
    ];

    /** Human-readable labels. */
    public const LABELS = [
        self::DRAFT            => 'Draft',
        self::READY_FOR_HR     => 'Ready for HR',
        self::PUBLISHED        => 'Published',
        self::HIRING           => 'Hiring',
        self::PARTIALLY_FILLED => 'Partially Filled',
        self::COMPLETED        => 'Completed',
        self::CLOSED           => 'Closed',
        self::CANCELLED        => 'Cancelled',
        self::ON_HOLD          => 'On Hold',
    ];

    /** Legacy (pre-enhancement) status → canonical. Used by the migration. */
    public const LEGACY_MAP = [
        'Active' => self::PUBLISHED,
        // 'Draft' and 'Closed' already match canonical values.
    ];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? (string) $status;
    }

    public static function isLive(?string $status): bool
    {
        return in_array($status, self::LIVE, true);
    }

    public static function isValid(?string $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}
