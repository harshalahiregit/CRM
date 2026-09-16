<?php

namespace Sire\Support;

/**
 * SIRE — notification event keys.
 *
 * Registered in ModuleEventCatalog and dispatched through the EXISTING
 * SireNotificationProvider. SIRE adds no mailer, no template store, no notification
 * table and no preference system — the engine already resolves per-tenant
 * templates, channel enablement, recipient rules and dedupe, and re-implementing
 * any of that here would be a second architecture by accident.
 */
final class SireEvents
{
    // --- the twelve the brief names ----------------------------------------
    public const REPORT_CREATED       = 'sire.report.created';
    public const REPORT_ASSIGNED      = 'sire.report.assigned';
    public const REPORT_REASSIGNED    = 'sire.report.reassigned';
    public const DEVELOPMENT_STARTED  = 'sire.report.development_started';
    public const READY_FOR_QA         = 'sire.report.ready_for_qa';
    public const QA_FAILED            = 'sire.qa.failed';
    public const QA_PASSED            = 'sire.qa.passed';
    public const SLA_WARNING          = 'sire.sla.warning';
    public const SLA_BREACHED         = 'sire.sla.breached';
    public const REPORT_REOPENED      = 'sire.report.reopened';
    public const RELEASED             = 'sire.report.released';
    public const REPORT_CLOSED        = 'sire.report.closed';

    // --- supporting, low-volume --------------------------------------------
    public const ASSIGNMENT_ACCEPTED  = 'sire.report.assignment_accepted';
    public const QA_STARTED           = 'sire.qa.started';
    public const PRODUCTION_VALIDATED = 'sire.report.production_validated';
    public const REPORT_ON_HOLD       = 'sire.report.on_hold';

    /**
     * Recipient roles per event, resolved by SireNotifier against the report.
     *
     * Tokens: assignee · qa_assignee · reporter · previous_assignee · role:<name>
     *
     * These are DEFAULTS. The engine's own rules still apply on top, and the actor
     * is always removed — nobody is told about a thing they just did themselves.
     */
    public const RECIPIENTS = [
        self::REPORT_CREATED       => ['role:admin'],
        self::REPORT_ASSIGNED      => ['assignee'],
        self::REPORT_REASSIGNED    => ['assignee', 'previous_assignee'],
        self::ASSIGNMENT_ACCEPTED  => ['reporter'],
        self::DEVELOPMENT_STARTED  => ['reporter'],
        self::READY_FOR_QA         => ['qa_assignee', 'role:qa'],
        self::QA_STARTED           => ['assignee'],
        self::QA_PASSED            => ['assignee', 'reporter'],
        self::QA_FAILED            => ['assignee'],
        self::SLA_WARNING          => ['assignee', 'role:lead'],
        self::SLA_BREACHED         => ['assignee', 'role:lead', 'role:admin'],
        self::REPORT_REOPENED      => ['assignee', 'role:admin'],
        self::RELEASED             => ['assignee', 'reporter'],
        self::PRODUCTION_VALIDATED => ['reporter'],
        self::REPORT_CLOSED        => ['reporter', 'assignee'],
        self::REPORT_ON_HOLD       => ['assignee', 'reporter'],

        self::RELEASE_APPROVED     => ['role:lead', 'role:admin'],
        self::RELEASE_RELEASED     => ['role:lead', 'role:admin'],
        self::RELEASE_ROLLED_BACK  => ['role:lead', 'role:admin'],
        // An override is deliberately the widest audience in the module.
        self::RELEASE_OVERRIDDEN   => ['role:lead', 'role:admin', 'role:qa'],
    ];

    // --- release governance (Phase 3) --------------------------------------
    public const RELEASE_APPROVED    = 'sire.release.approved';
    public const RELEASE_RELEASED    = 'sire.release.released';
    public const RELEASE_OVERRIDDEN  = 'sire.release.overridden';
    public const RELEASE_ROLLED_BACK = 'sire.release.rolled_back';

    /**
     * Events considered low-value when they arrive in bulk. SireNotifier collapses
     * repeats of these to one per recipient per report per window; the rest always
     * go through. Nothing that signals "you now have work" is ever collapsed.
     */
    /**
     * What a person actually reads, per event.
     *
     * SIRE owns these because SIRE owns the events. A host is free to ignore them
     * and use its own templates -- the event key and the metadata are enough to
     * do that -- but a host with no template for `sire.qa.failed` still sends
     * something intelligible rather than an event name.
     *
     * Written from the recipient's point of view, not the system's: "QA failed on
     * SIR-000412" rather than "Status changed to QA_FAILED".
     */
    public const TITLES = [
        self::REPORT_CREATED       => 'New issue reported',
        self::REPORT_ASSIGNED      => 'An issue was assigned to you',
        self::REPORT_REASSIGNED    => 'An issue was reassigned',
        self::ASSIGNMENT_ACCEPTED  => 'Your issue has been picked up',
        self::DEVELOPMENT_STARTED  => 'Work has started on your issue',
        self::READY_FOR_QA         => 'An issue is ready for QA',
        self::QA_STARTED           => 'QA has started on your fix',
        self::QA_PASSED            => 'QA passed',
        self::QA_FAILED            => 'QA failed -- back to you',
        self::SLA_WARNING          => 'An issue is approaching its SLA target',
        self::SLA_BREACHED         => 'An issue has breached its SLA target',
        self::REPORT_REOPENED      => 'An issue was reopened',
        self::RELEASED             => 'Your issue shipped',
        self::REPORT_CLOSED        => 'An issue was closed',
        self::PRODUCTION_VALIDATED => 'A fix was validated in production',
        self::REPORT_ON_HOLD       => 'An issue was put on hold',
    ];

    /**
     * Which events warrant interrupting someone.
     *
     * Only three are HIGH, and each means "you are now blocked or blocking":
     * a breach, a failed QA run, and work landing on your desk. If everything is
     * urgent, nothing is.
     */
    public const PRIORITIES = [
        self::SLA_BREACHED    => 'urgent',
        self::QA_FAILED       => 'high',
        self::REPORT_ASSIGNED => 'high',
        self::SLA_WARNING     => 'high',
    ];

    public const COLLAPSIBLE = [
        self::DEVELOPMENT_STARTED,
        self::QA_STARTED,
        self::ASSIGNMENT_ACCEPTED,
        self::REPORT_ON_HOLD,
    ];

    public static function all(): array
    {
        return array_keys(self::RECIPIENTS);
    }
}
