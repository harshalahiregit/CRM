<?php

namespace Sire\Support\Ai;

use Sire\Support\SireModule;

/**
 * SIRE AI — the capability catalogue.
 *
 * NOTHING HERE IS IMPLEMENTED. This phase builds the boundary, the contracts and
 * the persistence; no capability performs any analysis and no provider ships. The
 * only provider in the codebase is NullAiProvider, which declines everything.
 *
 * A capability is declared before it exists so that the shape of a suggestion —
 * what it advises, what it may see, what a human does with it — is settled while
 * it is cheap to change.
 */
final class AiCapability
{
    public const CLASSIFICATION            = 'classification';
    public const SEVERITY_RECOMMENDATION   = 'severity_recommendation';
    public const PRIORITY_RECOMMENDATION   = 'priority_recommendation';
    public const DUPLICATE_DETECTION       = 'duplicate_detection';
    public const ROOT_CAUSE_SUGGESTION     = 'root_cause_suggestion';
    public const DEVELOPER_TEST_CASES      = 'developer_test_cases';
    public const QA_TEST_CASES             = 'qa_test_cases';
    public const REGRESSION_RISK           = 'regression_risk';
    public const RECURRENCE_RISK           = 'recurrence_risk';
    public const KNOWLEDGE_RECOMMENDATION  = 'knowledge_recommendation';
    public const RELEASE_RISK              = 'release_risk';
    public const RELEASE_NOTE_REFINEMENT   = 'release_note_refinement';
    public const ENGINEERING_INSIGHTS      = 'engineering_insights';

    public const ALL = [
        self::CLASSIFICATION, self::SEVERITY_RECOMMENDATION, self::PRIORITY_RECOMMENDATION,
        self::DUPLICATE_DETECTION, self::ROOT_CAUSE_SUGGESTION, self::DEVELOPER_TEST_CASES,
        self::QA_TEST_CASES, self::REGRESSION_RISK, self::RECURRENCE_RISK,
        self::KNOWLEDGE_RECOMMENDATION, self::RELEASE_RISK, self::RELEASE_NOTE_REFINEMENT,
        self::ENGINEERING_INSIGHTS,
    ];

    /** What a suggestion is attached to. */
    public const SUBJECT_REPORT           = 'report';
    public const SUBJECT_RECURRENCE_GROUP = 'recurrence_group';
    public const SUBJECT_RELEASE          = 'release';
    public const SUBJECT_TENANT           = 'tenant';

    /**
     * capability => [label, subject, module, advises]
     *
     * `advises` names the field or decision a suggestion informs. It is
     * deliberately never the field a suggestion WRITES — see requirement 3. An
     * accepted suggestion is applied by a human through the ordinary service, and
     * the original value is never overwritten by the AI layer itself.
     */
    public const CATALOGUE = [
        self::CLASSIFICATION => [
            'label'   => 'Issue classification',
            'subject' => self::SUBJECT_REPORT,
            'module'  => SireModule::CORE,
            'advises' => 'category_id, module, release_class',
        ],
        self::SEVERITY_RECOMMENDATION => [
            'label'   => 'Severity recommendation',
            'subject' => self::SUBJECT_REPORT,
            'module'  => SireModule::CORE,
            'advises' => 'severity_id',
        ],
        self::PRIORITY_RECOMMENDATION => [
            'label'   => 'Priority recommendation',
            'subject' => self::SUBJECT_REPORT,
            'module'  => SireModule::CORE,
            'advises' => 'priority',
        ],
        self::DUPLICATE_DETECTION => [
            'label'   => 'Possible duplicates',
            'subject' => self::SUBJECT_REPORT,
            'module'  => SireModule::QUALITY,
            'advises' => 'duplicate_of_id',
        ],
        self::ROOT_CAUSE_SUGGESTION => [
            'label'   => 'Root cause suggestion',
            'subject' => self::SUBJECT_REPORT,
            'module'  => SireModule::QUALITY,
            'advises' => 'sire_root_causes.category, contributing_factors',
        ],
        self::DEVELOPER_TEST_CASES => [
            'label'   => 'Developer test cases',
            'subject' => self::SUBJECT_REPORT,
            'module'  => SireModule::CORE,
            'advises' => 'dev_test_notes',
        ],
        self::QA_TEST_CASES => [
            'label'   => 'QA test cases',
            'subject' => self::SUBJECT_REPORT,
            'module'  => SireModule::CORE,
            'advises' => 'qa_notes',
        ],
        self::REGRESSION_RISK => [
            'label'   => 'Regression risk',
            'subject' => self::SUBJECT_REPORT,
            'module'  => SireModule::QUALITY,
            'advises' => 'requires_regression_test',
        ],
        self::RECURRENCE_RISK => [
            'label'   => 'Recurrence risk',
            'subject' => self::SUBJECT_RECURRENCE_GROUP,
            'module'  => SireModule::QUALITY,
            // NOTE: SireRecurrenceService already computes a deterministic risk
            // score. An AI suggestion here ADVISES ALONGSIDE it and never replaces
            // it — a formula you can explain beats a score you cannot.
            'advises' => 'recurrence_risk (advisory only)',
        ],
        self::KNOWLEDGE_RECOMMENDATION => [
            'label'   => 'Relevant knowledge base articles',
            'subject' => self::SUBJECT_REPORT,
            'module'  => SireModule::KNOWLEDGE,
            'advises' => 'sire_kb_links',
        ],
        self::RELEASE_RISK => [
            'label'   => 'Release risk',
            'subject' => self::SUBJECT_RELEASE,
            'module'  => SireModule::RELEASE,
            // Advisory only, and explicitly NOT a gate. Gates are deterministic
            // and explainable; a release must never be blocked by something whose
            // reasoning nobody can reconstruct.
            'advises' => 'release readiness commentary (never a gate)',
        ],
        self::RELEASE_NOTE_REFINEMENT => [
            'label'   => 'Release note wording',
            'subject' => self::SUBJECT_RELEASE,
            'module'  => SireModule::RELEASE,
            'advises' => 'sire_release_notes.body_override',
        ],
        self::ENGINEERING_INSIGHTS => [
            'label'   => 'Engineering insights',
            'subject' => self::SUBJECT_TENANT,
            'module'  => SireModule::QUALITY,
            'advises' => 'commentary on quality trends',
        ],
    ];

    public static function isValid(?string $capability): bool
    {
        return $capability !== null && in_array($capability, self::ALL, true);
    }

    public static function label(string $capability): string
    {
        return self::CATALOGUE[$capability]['label'] ?? $capability;
    }

    public static function subjectFor(string $capability): ?string
    {
        return self::CATALOGUE[$capability]['subject'] ?? null;
    }

    /** Capabilities that may never gate, block or auto-apply. All of them, today. */
    public static function isAdvisoryOnly(string $capability): bool
    {
        return true;
    }
}
