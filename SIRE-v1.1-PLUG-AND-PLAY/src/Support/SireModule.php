<?php

namespace Sire\Support;

/**
 * SIRE — the module map, as data.
 *
 * SIRE is one Laravel module by deployment, but five by responsibility. Naming
 * the boundaries is only worth doing if something enforces them, so this map is
 * DATA and tests/module-boundaries.test.mjs reads it and fails the build when a
 * dependency runs the wrong way.
 *
 * THREE LAYERS
 *
 *   0  core      the workflow. Reports, transitions, timeline, access, SLA,
 *                notifications, context capture. Depends on NOTHING else in SIRE.
 *
 *   1  quality   duplicates, root cause, recurrence, regression, CAPA, metrics
 *      knowledge links to the Helpdesk knowledge base
 *      release   releases, gates, governance, release notes
 *                Peers. They may reference each other and anything in core.
 *
 *   2  ai        suggestion gateway, providers, redaction, suggestion store.
 *                May READ every layer below. Nothing may depend on it.
 *
 * THE RULE THAT MATTERS
 *
 * Nothing outside layer 2 may reference layer 2. That is what "AI can be added
 * without changing the core workflow" actually means: not a promise in a document,
 * but a property a test can check. Delete the whole Ai namespace and SIRE still
 * compiles, still runs, still ships releases.
 */
final class SireModule
{
    public const INTEGRATION = 'integration';
    public const CORE      = 'core';
    public const QUALITY   = 'quality';
    public const KNOWLEDGE = 'knowledge';
    public const RELEASE   = 'release';
    public const AI        = 'ai';

    public const ALL = [self::INTEGRATION, self::CORE, self::QUALITY, self::KNOWLEDGE, self::RELEASE, self::AI];

    /** module => layer number. A module may depend on lower layers and its own peers. */
    public const LAYERS = [
        // Layer -1. The adapters SIRE reaches the CRM through. Everything may
        // depend on them; they depend on nothing in SIRE, which is what lets a
        // developer rewire a CRM subsystem without reading any SIRE service.
        self::INTEGRATION => -1,
        self::CORE      => 0,
        self::QUALITY   => 1,
        self::KNOWLEDGE => 1,
        self::RELEASE   => 1,
        self::AI        => 2,
    ];

    /**
     * Class basename => module. Namespace alone cannot express this: everything
     * lives under Sire\Services, and moving 20 files into subdirectories would
     * be exactly the core change this phase exists to avoid.
     */
    public const CLASS_MODULE = [
        // ---- layer -1: integration (the SDK) --------------------------------
        // Thirteen contracts, thirteen shipped implementations, the value objects
        // they exchange, and the SIRE-owned traits that replaced host
        // dependencies. Nothing here may reference anything above it.
        'SireTenantProvider'                => self::INTEGRATION,
        'SireUserProvider'                  => self::INTEGRATION,
        'SireAuthorizationProvider'         => self::INTEGRATION,
        'SireNotificationProvider'          => self::INTEGRATION,
        'SireAttachmentProvider'            => self::INTEGRATION,
        'SireAuditProvider'                 => self::INTEGRATION,
        'SireNotesProvider'                 => self::INTEGRATION,
        'SireNumberingProvider'             => self::INTEGRATION,
        'SireSettingsProvider'              => self::INTEGRATION,
        'SireSlaProvider'                   => self::INTEGRATION,
        'SireKnowledgeProvider'             => self::INTEGRATION,
        'SireVersionProvider'               => self::INTEGRATION,
        'SireContextProvider'               => self::INTEGRATION,
        'SireLocalTenantProvider'           => self::INTEGRATION,
        'SireLocalUserProvider'             => self::INTEGRATION,
        'SireLocalAuthorizationProvider'    => self::INTEGRATION,
        'SireLocalNotificationProvider'     => self::INTEGRATION,
        'SireLocalAttachmentProvider'       => self::INTEGRATION,
        'SireLocalAuditProvider'            => self::INTEGRATION,
        'SireLocalNotesProvider'            => self::INTEGRATION,
        'SireLocalNumberingProvider'        => self::INTEGRATION,
        'SireLocalSettingsProvider'         => self::INTEGRATION,
        'SireLocalSlaProvider'              => self::INTEGRATION,
        'SireLocalKnowledgeProvider'        => self::INTEGRATION,
        'SireLocalVersionProvider'          => self::INTEGRATION,
        'SireLocalContextProvider'          => self::INTEGRATION,
        'SireUserIdentity'                  => self::INTEGRATION,
        'SireTenantIdentity'                => self::INTEGRATION,
        'SireNotification'                  => self::INTEGRATION,
        'SireAuditEvent'                    => self::INTEGRATION,
        'SireNote'                          => self::INTEGRATION,
        'SireAttachment'                    => self::INTEGRATION,
        'SireKnowledgeArticle'              => self::INTEGRATION,
        'SireScreenContext'                 => self::INTEGRATION,
        'SireCapability'                    => self::INTEGRATION,
        'BelongsToSireTenant'               => self::INTEGRATION,
        'RecordsSireAudit'                  => self::INTEGRATION,
        'SireApiResponse'                   => self::INTEGRATION,
        'AssertsSireTenantOwnership'        => self::INTEGRATION,
        'ResolvesSireUser'                  => self::INTEGRATION,
        'SireException'                     => self::INTEGRATION,
        'SireRouteMap'                      => self::INTEGRATION,
        'SireServiceProvider'               => self::INTEGRATION,
        'HostUser'                          => self::INTEGRATION,
        'Setting'                           => self::INTEGRATION,
        'Note'                              => self::INTEGRATION,
        'AuditEvent'                        => self::INTEGRATION,

        // ---- layer 0: core -------------------------------------------------
        'SireWorkflowService'   => self::CORE,
        'SireReportService'     => self::CORE,
        'SireAccessService'     => self::CORE,
        'SireNotifier'          => self::CORE,
        'SireSlaService'        => self::CORE,
        'SireTimelineService'   => self::CORE,
        'SireContextService'    => self::CORE,
        'SireDashboardService'  => self::CORE,
        'Report'                => self::CORE,
        'ReportContext'         => self::CORE,
        'WorkCycle'             => self::CORE,
        'ReportCategory'        => self::CORE,
        'ReportSeverity'        => self::CORE,
        'ReportApproval'        => self::CORE,
        'TimelineContributor'   => self::CORE,
        'SireTestCaseService'   => self::CORE,
        'SireTestCategory'      => self::CORE,
        'IssueTestCase'         => self::CORE,

        // ---- layer 1: quality ----------------------------------------------
        'SireDuplicateService'      => self::QUALITY,
        'SireRootCauseService'      => self::QUALITY,
        'SireRecurrenceService'     => self::QUALITY,
        'SireRegressionService'     => self::QUALITY,
        'SireCapaService'           => self::QUALITY,
        'SireQualityMetricsService' => self::QUALITY,
        'RootCause'                 => self::QUALITY,
        'RecurrenceGroup'           => self::QUALITY,
        'ReportLink'                => self::QUALITY,
        'CorrectiveAction'          => self::QUALITY,

        // ---- layer 1: knowledge --------------------------------------------
        'SireKnowledgeLinkService' => self::KNOWLEDGE,
        'KbLink'                   => self::KNOWLEDGE,

        // ---- layer 1: release ----------------------------------------------
        'SireReleaseService'           => self::RELEASE,
        'SireReleaseNotesService'      => self::RELEASE,
        'SireReleaseGateService'       => self::RELEASE,
        'SireReleaseGovernanceService' => self::RELEASE,
        'SireReleaseDashboardService'  => self::RELEASE,
        'Release'                      => self::RELEASE,
        'ReleaseNote'                  => self::RELEASE,
        'ReleaseOverride'              => self::RELEASE,

        // ---- layer 2: ai ----------------------------------------------------
        'SireAiGateway'           => self::AI,
        'SireAiRedactor'          => self::AI,
        'SireAiSuggestionService' => self::AI,
        'SireAiRegistry'          => self::AI,
        'NullAiProvider'          => self::AI,
        'AiSuggestion'            => self::AI,
        'AiTimelineContributor'   => self::AI,
        'SireAiContextBuilder'    => self::AI,
        // Classification and duplicate detection. The inverted index is a derived
        // AI artefact: truncate it and `sire:index-issues` rebuilds it.
        'SireTextAnalyzer'        => self::AI,
        'SireSimilarityScorer'    => self::AI,
        'SireIssueIndexer'        => self::AI,
        'SireDuplicateDetector'   => self::AI,
        'SireClassifier'          => self::AI,
        'SireLocalInsights'       => self::AI,
        'SireRootCauseSuggester'  => self::AI,
        'SireTestCaseGenerator'   => self::AI,
        // Phase 3 — remaining assistance
        'SireRiskEngine'          => self::AI,
        'SireKnowledgeRecommender' => self::AI,
        'SireReleaseNotesReviewer' => self::AI,
        'SireInsightsService'     => self::AI,
        'SireRiskInputBuilder'    => self::AI,
        'IssueToken'              => self::AI,
    ];

    public static function of(string $className): ?string
    {
        $base = class_basename($className);

        return self::CLASS_MODULE[$base] ?? null;
    }

    /** May $from reference $to? Lower layers and peers only — never upward. */
    public static function mayDependOn(string $from, string $to): bool
    {
        $fromLayer = self::LAYERS[$from] ?? null;
        $toLayer = self::LAYERS[$to] ?? null;

        if ($fromLayer === null || $toLayer === null) {
            return false;
        }

        return $toLayer <= $fromLayer;
    }
}
