<?php

namespace App\Support\Hr;

/**
 * Recruitment Services — client review status of a delivered candidate (Phase 2).
 *
 *   Pending Client Review → Accepted | Rejected | Need More Profiles
 *
 * The client sets these from the public tracking portal; the full comment
 * history lives in audit_logs (via Auditable), not on the row.
 */
class SubmissionStatus
{
    public const PENDING     = 'Pending Client Review';
    public const SHORTLISTED = 'Shortlisted';
    public const ACCEPTED    = 'Accepted';
    public const REJECTED    = 'Rejected';
    public const NEED_MORE   = 'Need More Profiles';
    public const HOLD        = 'Hold';

    public const ALL = [
        self::PENDING, self::SHORTLISTED, self::ACCEPTED, self::REJECTED, self::NEED_MORE, self::HOLD,
    ];

    /** Maps the client decision keyword → stored status. */
    public const DECISION_MAP = [
        'shortlist' => self::SHORTLISTED,
        'accept'    => self::ACCEPTED,
        'reject'    => self::REJECTED,
        'more'      => self::NEED_MORE,
        'hold'      => self::HOLD,
    ];
}
