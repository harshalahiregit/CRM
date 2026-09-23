<?php

namespace App\Support\Hr\Approval;

/**
 * The state of one approval request — the ENGINE's state, not the business one.
 *
 * Deliberately separate from every HR status vocabulary. A leave application is
 * Draft/Submitted/Approved/Rejected/Cancelled, a declaration is Verified rather
 * than Approved, a variable earning is lowercase, and an exit settlement runs
 * through five states of its own. An engine that owned those would have to
 * rewrite eleven state machines and their history.
 *
 * So it owns none of them. This says only how far along the ladder a request
 * is; the process adapter tells the domain service to make its own transition
 * when the last rung is cleared.
 */
final class ApprovalState
{
    /** Waiting on the approver of current_step. */
    public const PENDING = 'pending';

    /** Every step cleared. The domain service has been told.  */
    public const APPROVED = 'approved';

    /** Refused at some step. Terminal at any rung, matching every existing flow. */
    public const REJECTED = 'rejected';

    /** The subject was withdrawn or cancelled by its own flow. */
    public const CANCELLED = 'cancelled';

    /**
     * No approver could be resolved for the current step.
     *
     * A distinct state on purpose. The two obvious alternatives are both wrong:
     * auto-approving because nobody was found hands out the decision the
     * workflow existed to require, and leaving it pending forever hides the
     * misconfiguration until somebody complains their leave was never answered.
     * This one is visible and countable.
     */
    public const BLOCKED = 'blocked';

    /**
     * Decided outside the engine.
     *
     * The attendance app approves leave through LeaveApprovalService directly,
     * with its own reporting-line check, and this phase does not change that.
     * When the engine later finds the subject already decided, the request is
     * closed as superseded rather than left contradicting the record.
     */
    public const SUPERSEDED = 'superseded';

    public const ALL = [
        self::PENDING, self::APPROVED, self::REJECTED,
        self::CANCELLED, self::BLOCKED, self::SUPERSEDED,
    ];

    /** Still waiting on somebody. */
    public const OPEN = [self::PENDING, self::BLOCKED];

    public static function isOpen(?string $state): bool
    {
        return in_array($state, self::OPEN, true);
    }
}
