<?php

namespace Sire\Support;

/**
 * SIRE — engineering workflow states.
 *
 * SUPERSEDES the observation/incident lifecycle in the original spec. SIRE is an
 * internal engineering issue system: it tracks a defect from report through
 * development, QA, release and production validation. It is not an incident
 * register and it is not a second Helpdesk.
 *
 * These are code constants, not tenant-editable rows (decision D4). Transitions
 * are enforced, so a status has to mean the same thing in every tenant.
 */
final class SireStatus
{
    public const NEW                  = 'new';
    public const TRIAGED              = 'triaged';
    public const ASSIGNED             = 'assigned';
    public const IN_DEVELOPMENT       = 'in_development';
    public const READY_FOR_QA         = 'ready_for_qa';
    public const QA_IN_PROGRESS       = 'qa_in_progress';
    public const QA_FAILED            = 'qa_failed';
    public const QA_PASSED            = 'qa_passed';
    public const READY_FOR_RELEASE    = 'ready_for_release';
    public const RELEASED             = 'released';
    public const PRODUCTION_VALIDATED = 'production_validated';
    public const CLOSED               = 'closed';

    // --- change-request track (Phase 2) ------------------------------------
    // A change request is a sire_reports row with workflow_track = 'change'. It
    // runs its own approval-gated path up front, then REJOINS the defect track at
    // ASSIGNED and shares the whole QA and release pipeline from there. A parallel
    // entity would have meant a second numbering series, a second timeline, a
    // second SLA and a second dashboard.
    public const BUSINESS_REVIEW  = 'business_review';
    public const IMPACT_ANALYSIS  = 'impact_analysis';
    public const APPROVAL         = 'approval';
    public const PLANNED          = 'planned';

    public const REOPENED         = 'reopened';
    public const ON_HOLD          = 'on_hold';
    public const DUPLICATE        = 'duplicate';
    public const REJECTED         = 'rejected';
    public const WONT_FIX         = 'wont_fix';
    public const CANNOT_REPRODUCE = 'cannot_reproduce';

    /** Nothing moves out of these without an explicit reopen. */
    public const TERMINAL = [
        self::CLOSED, self::DUPLICATE, self::REJECTED, self::WONT_FIX, self::CANNOT_REPRODUCE,
    ];

    /**
     * The fix exists. These are not TERMINAL -- the issue is still open, and it
     * still belongs on a work queue, because somebody owes it a QA pass, a
     * release or a validation.
     *
     * They are a different question from "what do I have to write code for",
     * and conflating the two is what made the developer brief carry issues that
     * were already fixed, already shipped, even already validated in production.
     * NOT-TERMINAL was standing in for NOT-DONE, and the two parted company the
     * moment a fix was submitted.
     */
    public const FIX_SUBMITTED = [
        self::READY_FOR_QA, self::QA_IN_PROGRESS, self::QA_PASSED,
        self::READY_FOR_RELEASE, self::RELEASED, self::PRODUCTION_VALIDATED,
    ];

    /**
     * The resolve clock stops while the issue is parked or waiting on a release
     * train. It keeps running through development and QA — that time is the
     * team's own and hiding it would defeat the point of measuring it.
     */
    public const SLA_PAUSED = [
        self::ON_HOLD, self::READY_FOR_RELEASE, self::RELEASED,
        // A change request waiting on a business decision is not engineering's
        // clock. Approval queues are governed, not raced.
        self::BUSINESS_REVIEW, self::APPROVAL,
    ];

    /** States only a change request can occupy. */
    public const CHANGE_ONLY = [
        self::BUSINESS_REVIEW, self::IMPACT_ANALYSIS, self::APPROVAL, self::PLANNED,
    ];

    /** Engineering owns the issue in these states; they drive the "my work" queues. */
    public const DEVELOPMENT = [self::ASSIGNED, self::IN_DEVELOPMENT, self::QA_FAILED];
    public const QA          = [self::READY_FOR_QA, self::QA_IN_PROGRESS, self::QA_PASSED];

    public static function all(): array
    {
        return array_keys(SireWorkflow::STATES);
    }

    public static function label(string $status): string
    {
        return SireWorkflow::STATES[$status]['label'] ?? $status;
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    public static function pausesSla(string $status): bool
    {
        return in_array($status, self::SLA_PAUSED, true);
    }

    /** True once a fix has been submitted, whatever is left to verify. */
    public static function hasFix(string $status): bool
    {
        return in_array($status, self::FIX_SUBMITTED, true);
    }
}
