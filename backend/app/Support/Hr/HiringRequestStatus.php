<?php

namespace App\Support\Hr;

/**
 * Recruitment Services hiring-request workflow (Phase 1).
 *
 *   Submitted → Under_Review → Approved → Converted
 *                     └────────→ Rejected
 *
 * Approved is the gate for "Convert to Manpower Request", which hands off to the
 * existing recruitment flow.
 */
class HiringRequestStatus
{
    public const DRAFT        = 'Draft';      // company-created, editable before submit
    public const SUBMITTED    = 'Submitted';
    public const UNDER_REVIEW = 'Under_Review';
    public const APPROVED     = 'Approved';
    public const REJECTED     = 'Rejected';
    public const CONVERTED    = 'Converted';
    public const COMPLETED    = 'Completed'; // Phase 2: project delivered to client

    public const ALL = [
        self::DRAFT, self::SUBMITTED, self::UNDER_REVIEW, self::APPROVED, self::REJECTED, self::CONVERTED, self::COMPLETED,
    ];

    public const LABELS = [
        self::DRAFT        => 'Draft',
        self::SUBMITTED    => 'Submitted',
        self::UNDER_REVIEW => 'Under Review',
        self::APPROVED     => 'Approved',
        self::REJECTED     => 'Rejected',
        self::CONVERTED    => 'Converted',
        self::COMPLETED    => 'Completed',
    ];
}
