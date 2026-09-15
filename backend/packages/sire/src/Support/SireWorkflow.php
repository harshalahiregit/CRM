<?php

namespace Sire\Support;

/**
 * SIRE — the workflow, defined once.
 *
 * THIS IS THE AUTHORITY. frontend/src/lib/sire/workflow.generated.json is an
 * export of this file, written by `php artisan sire:export-workflow`, and the SPA
 * uses it only for labels, colours and ordering. Legality is decided here and
 * nowhere else; the client is told what it may do via `available_transitions` on
 * the case payload.
 *
 * Adding a state or a transition is an edit to this file plus a re-export. Do not
 * hand-edit the JSON.
 */
final class SireWorkflow
{
    public const INITIAL = SireStatus::NEW;

    /** state => label, group, tone, terminal, order */
    public const STATES = [
        SireStatus::NEW                  => ['label' => 'New',                  'group' => 'intake',      'tone' => 'slate',  'terminal' => false, 'order' => 10],
        SireStatus::REOPENED             => ['label' => 'Reopened',             'group' => 'intake',      'tone' => 'orange', 'terminal' => false, 'order' => 15],
        SireStatus::TRIAGED              => ['label' => 'Triaged',              'group' => 'intake',      'tone' => 'slate',  'terminal' => false, 'order' => 20],
        SireStatus::BUSINESS_REVIEW      => ['label' => 'Business Review',      'group' => 'change',      'tone' => 'slate',  'terminal' => false, 'order' => 22],
        SireStatus::IMPACT_ANALYSIS      => ['label' => 'Impact Analysis',      'group' => 'change',      'tone' => 'slate',  'terminal' => false, 'order' => 24],
        SireStatus::APPROVAL             => ['label' => 'Awaiting Approval',    'group' => 'change',      'tone' => 'amber',  'terminal' => false, 'order' => 26],
        SireStatus::PLANNED              => ['label' => 'Planned',              'group' => 'change',      'tone' => 'blue',   'terminal' => false, 'order' => 28],
        SireStatus::ASSIGNED             => ['label' => 'Assigned',             'group' => 'development', 'tone' => 'blue',   'terminal' => false, 'order' => 30],
        SireStatus::IN_DEVELOPMENT       => ['label' => 'In Development',       'group' => 'development', 'tone' => 'blue',   'terminal' => false, 'order' => 40],
        SireStatus::READY_FOR_QA         => ['label' => 'Ready for QA',         'group' => 'qa',          'tone' => 'violet', 'terminal' => false, 'order' => 50],
        SireStatus::QA_IN_PROGRESS       => ['label' => 'QA in Progress',       'group' => 'qa',          'tone' => 'violet', 'terminal' => false, 'order' => 60],
        SireStatus::QA_FAILED            => ['label' => 'QA Failed',            'group' => 'qa',          'tone' => 'red',    'terminal' => false, 'order' => 70],
        SireStatus::QA_PASSED            => ['label' => 'QA Passed',            'group' => 'qa',          'tone' => 'green',  'terminal' => false, 'order' => 80],
        SireStatus::READY_FOR_RELEASE    => ['label' => 'Ready for Release',    'group' => 'release',     'tone' => 'amber',  'terminal' => false, 'order' => 90],
        SireStatus::RELEASED             => ['label' => 'Released',             'group' => 'release',     'tone' => 'amber',  'terminal' => false, 'order' => 100],
        SireStatus::PRODUCTION_VALIDATED => ['label' => 'Production Validated', 'group' => 'release',     'tone' => 'green',  'terminal' => false, 'order' => 110],
        SireStatus::ON_HOLD              => ['label' => 'On Hold',              'group' => 'paused',      'tone' => 'amber',  'terminal' => false, 'order' => 0],
        SireStatus::CLOSED               => ['label' => 'Closed',               'group' => 'closed',      'tone' => 'gray',   'terminal' => true,  'order' => 120],
        SireStatus::DUPLICATE            => ['label' => 'Duplicate',            'group' => 'closed',      'tone' => 'gray',   'terminal' => true,  'order' => 130],
        SireStatus::REJECTED             => ['label' => 'Rejected',             'group' => 'closed',      'tone' => 'gray',   'terminal' => true,  'order' => 140],
        SireStatus::WONT_FIX             => ['label' => "Won't Fix",            'group' => 'closed',      'tone' => 'gray',   'terminal' => true,  'order' => 150],
        SireStatus::CANNOT_REPRODUCE     => ['label' => 'Cannot Reproduce',     'group' => 'closed',      'tone' => 'gray',   'terminal' => true,  'order' => 160],
    ];

    /**
     * action => from[], to, label, capability, requires[], guard, clears[],
     *           auto_advance, notifies
     *
     * `requires` names fields that must be non-empty on the report (or supplied
     * in the payload) before the transition is allowed. `guard` names a check in
     * SireWorkflowService::guard().
     */
    public const TRANSITIONS = [
        'triage' => [
            'from' => [SireStatus::NEW, SireStatus::REOPENED], 'to' => SireStatus::TRIAGED,
            'label' => 'Triage', 'capability' => 'sire.report.triage',
            'requires' => ['severity_id', 'priority'],
            // Offered, never demanded. Category is what fills the register's Type
            // column and drives release-note classing, and triage is the only
            // moment anyone is looking at the issue with the context to set it --
            // but a triager who does not know it must still be able to triage.
            'optional' => ['category_id'],
            'note' => 'Severity is impact, priority is scheduling. Both are set here.',
        ],
        'assign' => [
            'from' => [SireStatus::TRIAGED, SireStatus::REOPENED, SireStatus::ON_HOLD, SireStatus::PLANNED],
            'to' => SireStatus::ASSIGNED,
            'label' => 'Assign developer', 'capability' => 'sire.report.assign',
            'requires' => ['assignee_id'],
        ],
        // ---- change-request track ------------------------------------------
        'submit_business_review' => [
            'from' => [SireStatus::TRIAGED], 'to' => SireStatus::BUSINESS_REVIEW,
            'label' => 'Send to business review', 'capability' => 'sire.change.review',
            'tracks' => ['change'],
            'note' => 'Only a change request takes this path. A defect goes straight from triage to a developer.',
        ],
        'complete_business_review' => [
            'from' => [SireStatus::BUSINESS_REVIEW], 'to' => SireStatus::IMPACT_ANALYSIS,
            'label' => 'Complete business review', 'capability' => 'sire.change.review',
            'tracks' => ['change'], 'requires' => ['business_justification'],
        ],
        'return_to_business_review' => [
            'from' => [SireStatus::IMPACT_ANALYSIS], 'to' => SireStatus::BUSINESS_REVIEW,
            'label' => 'Return for business review', 'capability' => 'sire.change.review',
            'tracks' => ['change'],
        ],
        'request_change_approval' => [
            'from' => [SireStatus::IMPACT_ANALYSIS], 'to' => SireStatus::APPROVAL,
            'label' => 'Request approval', 'capability' => 'sire.change.review',
            'tracks' => ['change'], 'requires' => ['impact_summary'],
            'raises_approval' => 'change_request',
            'note' => 'Raises a sire_approvals row using the purchase/tpv register shape. No sixth approval engine.',
        ],
        'approve_change' => [
            'from' => [SireStatus::APPROVAL], 'to' => SireStatus::PLANNED,
            'label' => 'Approve change', 'capability' => 'sire.change.approve',
            'tracks' => ['change'], 'notifies' => 'sire.change.approved',
        ],
        'reject_change' => [
            'from' => [SireStatus::APPROVAL], 'to' => SireStatus::REJECTED,
            'label' => 'Reject change', 'capability' => 'sire.change.approve',
            'tracks' => ['change'], 'requires' => ['resolution_note'],
            'notifies' => 'sire.change.rejected',
        ],

        'unassign' => [
            'from' => [SireStatus::ASSIGNED], 'to' => SireStatus::TRIAGED,
            'label' => 'Return to triage', 'capability' => 'sire.report.assign',
            'clears' => ['assignee_id', 'assignment_accepted_at'],
        ],
        'start_development' => [
            'from' => [SireStatus::ASSIGNED, SireStatus::QA_FAILED], 'to' => SireStatus::IN_DEVELOPMENT,
            'label' => 'Start development', 'capability' => 'sire.report.develop',
            'guard' => 'actor_is_assignee_or_lead',
        ],
        'mark_ready_for_qa' => [
            'from' => [SireStatus::IN_DEVELOPMENT], 'to' => SireStatus::READY_FOR_QA,
            'label' => 'Mark ready for QA', 'capability' => 'sire.report.develop',
            'guard' => 'actor_is_assignee_or_lead', 'requires' => ['fix_summary'],
        ],
        'pull_back_to_development' => [
            'from' => [SireStatus::READY_FOR_QA], 'to' => SireStatus::IN_DEVELOPMENT,
            'label' => 'Pull back to development', 'capability' => 'sire.report.develop',
        ],
        'start_qa' => [
            'from' => [SireStatus::READY_FOR_QA], 'to' => SireStatus::QA_IN_PROGRESS,
            'label' => 'Start QA', 'capability' => 'sire.qa.execute',
        ],
        'qa_pass' => [
            'from' => [SireStatus::QA_IN_PROGRESS], 'to' => SireStatus::QA_PASSED,
            'label' => 'Pass QA', 'capability' => 'sire.qa.execute',
            'auto_advance' => SireStatus::READY_FOR_RELEASE,
            'note' => 'Auto-advance is governed by the tenant setting sire.auto_ready_for_release (default true). With it off the issue rests at qa_passed and a lead advances it explicitly.',
        ],
        'qa_fail' => [
            'from' => [SireStatus::QA_IN_PROGRESS], 'to' => SireStatus::QA_FAILED,
            'label' => 'Fail QA', 'capability' => 'sire.qa.execute',
            'requires' => ['qa_notes'], 'notifies' => 'sire.qa.failed',
            'note' => 'Notifies the assigned developer through SireNotificationProvider.',
        ],
        'mark_ready_for_release' => [
            'from' => [SireStatus::QA_PASSED], 'to' => SireStatus::READY_FOR_RELEASE,
            'label' => 'Mark ready for release', 'capability' => 'sire.release.manage',
        ],
        'release' => [
            'from' => [SireStatus::READY_FOR_RELEASE], 'to' => SireStatus::RELEASED,
            'label' => 'Mark released', 'capability' => 'sire.release.manage',
            'requires' => ['release_ref'],
        ],
        'validate_production' => [
            'from' => [SireStatus::RELEASED], 'to' => SireStatus::PRODUCTION_VALIDATED,
            'label' => 'Validate in production', 'capability' => 'sire.release.manage',
        ],
        'close' => [
            'from' => [SireStatus::PRODUCTION_VALIDATED], 'to' => SireStatus::CLOSED,
            'label' => 'Close', 'capability' => 'sire.report.close',
            // A serious defect does not get to be closed until somebody has said
            // WHY it happened. Applies to close only: reject, won't fix and
            // cannot reproduce are terminal too, and demanding a root cause for a
            // defect nobody could reproduce is asking for fiction.
            'guard' => 'root_cause_confirmed_when_serious',
            'note'  => 'Critical, P1, recurring and reopened issues need a CONFIRMED root cause first.',
        ],
        'hold' => [
            'from' => [
                SireStatus::TRIAGED, SireStatus::ASSIGNED, SireStatus::IN_DEVELOPMENT,
                SireStatus::READY_FOR_QA, SireStatus::QA_FAILED, SireStatus::READY_FOR_RELEASE,
                SireStatus::BUSINESS_REVIEW, SireStatus::IMPACT_ANALYSIS, SireStatus::PLANNED,
            ],
            'to' => SireStatus::ON_HOLD,
            'label' => 'Put on hold', 'capability' => 'sire.report.triage',
            'requires' => ['hold_reason'],
            'note' => 'Remembers held_from_status so resume returns exactly where it paused.',
        ],
        'resume' => [
            'from' => [SireStatus::ON_HOLD], 'to' => '@held_from_status',
            'label' => 'Resume', 'capability' => 'sire.report.triage',
        ],
        'mark_duplicate' => [
            'from' => [SireStatus::NEW, SireStatus::TRIAGED, SireStatus::ASSIGNED, SireStatus::REOPENED],
            'to' => SireStatus::DUPLICATE,
            'label' => 'Mark duplicate', 'capability' => 'sire.report.triage',
            'requires' => ['duplicate_of_id'],
        ],
        'reject' => [
            'from' => [
                SireStatus::NEW, SireStatus::TRIAGED, SireStatus::REOPENED,
                SireStatus::BUSINESS_REVIEW, SireStatus::IMPACT_ANALYSIS,
            ],
            'to' => SireStatus::REJECTED,
            'label' => 'Reject', 'capability' => 'sire.report.triage',
            'requires' => ['resolution_note'],
        ],
        'wont_fix' => [
            'from' => [SireStatus::NEW, SireStatus::TRIAGED, SireStatus::ASSIGNED, SireStatus::IN_DEVELOPMENT, SireStatus::REOPENED],
            'to' => SireStatus::WONT_FIX,
            'label' => "Won't fix", 'capability' => 'sire.report.close',
            'requires' => ['resolution_note'],
        ],
        'cannot_reproduce' => [
            'from' => [SireStatus::NEW, SireStatus::TRIAGED, SireStatus::ASSIGNED, SireStatus::IN_DEVELOPMENT, SireStatus::QA_IN_PROGRESS],
            'to' => SireStatus::CANNOT_REPRODUCE,
            'label' => 'Cannot reproduce', 'capability' => 'sire.report.triage',
            'requires' => ['resolution_note'],
        ],
        'reopen' => [
            'from' => [
                SireStatus::CLOSED, SireStatus::RELEASED, SireStatus::PRODUCTION_VALIDATED,
                SireStatus::DUPLICATE, SireStatus::REJECTED, SireStatus::WONT_FIX, SireStatus::CANNOT_REPRODUCE,
            ],
            'to' => SireStatus::REOPENED,
            'label' => 'Reopen', 'capability' => 'sire.report.reopen',
            'note' => 'Increments reopen_count and clears the resolution.',
        ],
    ];

    /**
     * Actions that record work without moving the issue. Acceptance and starting
     * work are different moments, and a developer adding investigation notes on
     * a Tuesday should not change what the board says.
     */
    public const ACTIONS = [
        'accept_assignment'      => ['states' => [SireStatus::ASSIGNED], 'capability' => 'sire.report.develop', 'guard' => 'actor_is_assignee', 'label' => 'Accept assignment', 'note' => 'Stamps assignment_accepted_at. Deliberately not a status change — acceptance and starting work are different moments.'],
        'add_investigation_notes'=> ['states' => [SireStatus::ASSIGNED, SireStatus::IN_DEVELOPMENT, SireStatus::QA_FAILED], 'capability' => 'sire.report.develop', 'label' => 'Add investigation notes'],
        'add_fix_summary'        => ['states' => [SireStatus::IN_DEVELOPMENT, SireStatus::READY_FOR_QA], 'capability' => 'sire.report.develop', 'label' => 'Add fix summary'],
        'submit_dev_testing'     => ['states' => [SireStatus::IN_DEVELOPMENT], 'capability' => 'sire.report.develop', 'label' => 'Submit developer testing'],
        'add_qa_notes'           => ['states' => [SireStatus::QA_IN_PROGRESS, SireStatus::QA_FAILED, SireStatus::QA_PASSED], 'capability' => 'sire.qa.execute', 'label' => 'Add QA notes'],
    ];

    public static function transition(string $action): ?array
    {
        return self::TRANSITIONS[$action] ?? null;
    }

    /** Raw legality only — capability and guards are applied by the service. */
    public static function allows(string $from, string $action): bool
    {
        $t = self::transition($action);

        return $t !== null && in_array($from, $t['from'], true);
    }

    /** Tracks a transition is available on. Absent = both. */
    public static function tracksFor(string $action): array
    {
        return self::TRANSITIONS[$action]['tracks'] ?? [SireTrack::DEFECT, SireTrack::CHANGE];
    }

    public static function allowsOnTrack(string $from, string $action, string $track): bool
    {
        return self::allows($from, $action) && in_array($track, self::tracksFor($action), true);
    }

    public static function actionsFrom(string $from): array
    {
        return array_keys(array_filter(
            self::TRANSITIONS,
            static fn (array $t) => in_array($from, $t['from'], true),
        ));
    }
}
