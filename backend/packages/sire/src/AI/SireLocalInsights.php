<?php

namespace Sire\AI;

use Sire\Models\AiSuggestion;
use Sire\Models\Release;
use Sire\Models\Report;
use Sire\Dto\SireUserIdentity;
use Sire\Support\Ai\AiCapability;
use Throwable;

/**
 * SIRE — locally computed suggestions.
 *
 * WHY THIS IS NOT AN AiProvider
 *
 * The AiProvider contract is deliberately strict: a provider receives context that
 * SireAiRedactor has already filtered and MUST NOT reach back for more. That rule
 * is what makes an external provider safe — it cannot see what the redactor
 * withheld, because it has no way to look.
 *
 * A local engine has the opposite requirement: duplicate detection is a SEARCH,
 * and a search needs the database. Forcing it through the provider interface would
 * mean either giving providers database access — putting a hole in the one rule
 * that protects tenant data — or crippling the local engine into uselessness.
 *
 * So there are two paths, and the difference between them is the point:
 *
 *   SireAiGateway::suggest()   external providers · redacted context · data leaves
 *   SireLocalInsights          local engines · full tenant data · NOTHING leaves
 *
 * Both record their output in the same store, so a suggestion is a suggestion
 * however it was produced, and the human feedback loop is identical.
 *
 * Everything here is deterministic. Same inputs, same output, every time — which
 * also means these suggestions can be regenerated and compared when the scoring
 * changes.
 */
class SireLocalInsights
{
    public const PROVIDER = 'local';
    public const MODEL    = 'heuristic';
    public const VERSION  = 'v1';

    public function __construct(
        private readonly SireAiGateway $gateway,
        private readonly SireClassifier $classifier,
        private readonly SireDuplicateDetector $detector,
        private readonly SireRootCauseSuggester $rootCauses,
        private readonly SireTestCaseGenerator $testCases,
        private readonly SireRiskEngine $risk,
        private readonly SireRiskInputBuilder $riskInputs,
        private readonly SireKnowledgeRecommender $knowledge,
        private readonly SireReleaseNotesReviewer $notesReviewer,
        private readonly SireInsightsService $insights,
    ) {
    }

    /**
     * Recommend a severity on its own.
     *
     * The engine is the same weighted vote classification uses -- severity was
     * always computed, it simply had no capability of its own and so could not be
     * enabled, declined or audited separately. AiCapability declared it from the
     * start; nothing implemented it, which made "all 13 run locally" untrue for
     * two of them.
     *
     * Its own capability flag, deliberately: a workspace may well want SIRE
     * proposing a module while keeping severity a human judgement, and severity
     * drives the SLA clock.
     */
    public function recommendSeverity(Report $report, SireUserIdentity $actor): ?AiSuggestion
    {
        return $this->recommendField($report, $actor, AiCapability::SEVERITY_RECOMMENDATION, 'severity');
    }

    /** Recommend a priority on its own. See recommendSeverity(). */
    public function recommendPriority(Report $report, SireUserIdentity $actor): ?AiSuggestion
    {
        return $this->recommendField($report, $actor, AiCapability::PRIORITY_RECOMMENDATION, 'priority');
    }

    /** One voted field, recorded under its own capability. */
    private function recommendField(
        Report $report,
        SireUserIdentity $actor,
        string $capability,
        string $field,
    ): ?AiSuggestion {
        return $this->guard($report, $capability, function () use ($report, $actor, $capability, $field) {
            $result = $this->classifier->classify($report, $actor);
            $vote = $result[$field];

            // Abstention is an answer, not a failure -- and recording it would put
            // an empty panel on the screen.
            if ($vote['abstained']) {
                return null;
            }

            return $this->record(
                $report,
                $capability,
                payload: [$field => $vote],
                confidence: $vote['confidence'] ?? null,
                evidence: [
                    'summary' => sprintf(
                        'Based on %d similar issues already in this workspace.',
                        $result['neighbour_count'],
                    ),
                    'signals'    => [$vote['reason']],
                    // The neighbours the vote was taken over -- the check a
                    // reader needs to disagree with it.
                    'references' => $result['neighbours'] ?? [],
                ],
                actor: $actor,
            );
        });
    }

    /**
     * Recommend issue type, module, severity and priority.
     *
     * Returns null when the capability is off or nothing useful could be said.
     * Callers treat null as "no suggestions" and carry on — this is never on a
     * critical path.
     */
    public function classify(Report $report, SireUserIdentity $actor): ?AiSuggestion
    {
        return $this->guard($report, AiCapability::CLASSIFICATION, function () use ($report, $actor) {
            $result = $this->classifier->classify($report, $actor);

            $offered = array_filter(
                ['module', 'category', 'severity', 'priority'],
                fn (string $f) => ! $result[$f]['abstained'],
            );

            if ($offered === []) {
                // Every field abstained. Recording an empty suggestion would put a
                // panel on screen that says nothing.
                return null;
            }

            return $this->record(
                $report,
                AiCapability::CLASSIFICATION,
                payload: [
                    'module'   => $result['module'],
                    'category' => $result['category'],
                    'severity' => $result['severity'],
                    'priority' => $result['priority'],
                ],
                confidence: $this->classifier->overallConfidence($result),
                evidence: [
                    'summary' => sprintf(
                        'Based on %d similar issues already in this workspace.',
                        $result['neighbour_count'],
                    ),
                    'signals'    => array_values(array_map(
                        fn (string $f) => $result[$f]['reason'],
                        $offered,
                    )),
                    // The neighbours themselves, so the reason can be checked
                    // rather than taken on trust.
                    'references' => $result['neighbours'],
                ],
                actor: $actor,
            );
        });
    }

    /**
     * Find issues that may already describe this one.
     *
     * Never marks anything a duplicate. It produces candidates with evidence; the
     * human links, ignores or defers.
     */
    public function duplicates(Report $report, SireUserIdentity $actor): ?AiSuggestion
    {
        return $this->guard($report, AiCapability::DUPLICATE_DETECTION, function () use ($report, $actor) {
            $found = $this->detector->detect($report, $actor);

            if ($found['candidates'] === []) {
                return null;
            }

            $top = $found['candidates'][0];

            return $this->record(
                $report,
                AiCapability::DUPLICATE_DETECTION,
                payload: ['candidates' => $found['candidates']],
                // The strongest candidate's score. Reporting a mean across five
                // candidates would understate a single obvious match.
                confidence: (float) $top['score'],
                evidence: [
                    'summary' => sprintf(
                        '%d possible duplicate%s found among %d issues sharing terms with this one.',
                        count($found['candidates']),
                        count($found['candidates']) === 1 ? '' : 's',
                        $found['searched'],
                    ),
                    'signals' => [
                        'Shared terms: '.implode(', ', array_slice($top['signals']['shared_terms'], 0, 8)),
                        $top['signals']['structural_matched'] === []
                            ? 'No structural match — text only.'
                            : 'Same '.implode(', ', $top['signals']['structural_matched']).'.',
                    ],
                    'references' => array_column(array_column($found['candidates'], 'candidate'), 'report_number'),
                ],
                actor: $actor,
            );
        });
    }

    /**
     * Suggest a root cause, grounded in analyses a human already confirmed.
     *
     * Labelled "AI Suggested Root Cause" wherever it appears, and never written
     * into the confirmed analysis. Confirming stays SireRootCauseService::confirm(),
     * performed by a person, with its own capability.
     */
    public function rootCause(Report $report, SireUserIdentity $actor): ?AiSuggestion
    {
        return $this->guard($report, AiCapability::ROOT_CAUSE_SUGGESTION, function () use ($report, $actor) {
            $result = $this->rootCauses->suggest($report, $actor);

            if ($result['abstained']) {
                // Nothing grounded to say. A blank panel beats a confident guess
                // about why something broke.
                return null;
            }

            return $this->record(
                $report,
                AiCapability::ROOT_CAUSE_SUGGESTION,
                payload: [
                    'category'             => $result['category'],
                    // Always attributed. Rendered as a quotation, never as a finding.
                    'description'          => $result['description'],
                    'quoted_from'          => $result['quoted_from'],
                    'detection_gap'        => $result['detection_gap'],
                    'contributing_factors' => $result['contributing_factors'],
                    'tally'                => $result['tally'],
                    // Belt and braces for any consumer that forgets the label.
                    'is_confirmed'         => false,
                ],
                confidence: (float) $result['confidence'],
                evidence: [
                    'summary' => sprintf(
                        'Drawn from %d similar issues whose root cause was confirmed by a person.',
                        count($result['related']),
                    ),
                    'signals'    => [
                        $result['reason'],
                        "Description quoted from {$result['quoted_from']}.",
                    ],
                    'references' => $result['related'],
                ],
                actor: $actor,
            );
        });
    }

    /**
     * Generate a suggested test checklist.
     *
     * The suggestion HOLDS the proposed tests; it does not create them. A human
     * accepts, edits or discards, and the accepted definitions are then posted to
     * the ordinary test-case endpoint — where a person is recorded as the author
     * and no result can be set.
     *
     * @param  string  $phase  developer | qa — changes only which tests are offered first
     */
    public function generateTestCases(Report $report, SireUserIdentity $actor, string $phase = 'both'): ?AiSuggestion
    {
        $capability = $phase === 'qa'
            ? AiCapability::QA_TEST_CASES
            : AiCapability::DEVELOPER_TEST_CASES;

        return $this->guard($report, $capability, function () use ($report, $actor, $capability, $phase) {
            $related = $report->links()->with('toReport:id,report_number')->get()
                ->pluck('toReport.report_number')->filter()->values()->all();

            $cases = $this->testCases->generate($report, [
                'related_refs'  => $related,
                'regression_of' => $report->regressionOf?->report_number,
            ]);

            if ($cases === []) {
                return null;
            }

            // Stamped with the phase so the checklist knows who it is for. The
            // generator produces the same tests either way; the phase decides
            // presentation, not content.
            $cases = array_map(fn (array $c) => $c + ['phase' => $phase], $cases);

            return $this->record(
                $report,
                $capability,
                payload: ['test_cases' => $cases],
                // Deliberately null. There is nothing probabilistic here — these
                // are templates fired by signals, and a confidence percentage
                // would imply a judgement the generator did not make.
                confidence: null,
                evidence: [
                    'summary' => sprintf(
                        '%d suggested tests. Two always apply; the rest were emitted because this issue carries a signal for them.',
                        count($cases),
                    ),
                    'signals'    => array_values(array_map(fn (array $c) => $c['rationale'], $cases)),
                    'references' => $related,
                ],
                actor: $actor,
            );
        });
    }

    /** How likely is fixing this issue to break something that worked? */
    public function regressionRisk(Report $report, SireUserIdentity $actor): ?AiSuggestion
    {
        return $this->guard($report, AiCapability::REGRESSION_RISK, function () use ($report, $actor) {
            $result = $this->risk->regressionRisk($this->riskInputs->forRegression($report));

            // Nothing found is not "low risk, all clear" — it is "no evidence
            // either way", and recording it as a finding would be reassurance
            // dressed as analysis.
            if ($this->risk->isUnevidenced($result)) {
                return null;
            }

            return $this->recordFor($report, AiCapability::REGRESSION_RISK,
                ['level' => $result['level'], 'score' => $result['score'], 'factors' => $result['factors']],
                $this->levelConfidence($result),
                ['summary' => "Regression risk: {$result['level']}.", 'signals' => $result['reasons'], 'references' => []],
                $actor);
        });
    }

    /**
     * A SECOND OPINION on recurrence, never a replacement.
     *
     * SireRecurrenceService already scores a recurrence GROUP deterministically
     * from its cadence and fix status. This scores the ISSUE from its own history —
     * reopens, repeated fixes, workaround language — and the two are shown side by
     * side. A formula you can explain should not be overwritten by a second
     * opinion, and a second opinion that only ever agreed would be worth nothing.
     */
    public function recurrenceRiskOpinion(Report $report, SireUserIdentity $actor): ?AiSuggestion
    {
        return $this->guard($report, AiCapability::RECURRENCE_RISK, function () use ($report, $actor) {
            $result = $this->risk->recurrenceRisk($this->riskInputs->forRecurrence($report));

            if ($this->risk->isUnevidenced($result)) {
                return null;
            }

            return $this->recordFor($report, AiCapability::RECURRENCE_RISK,
                [
                    'level' => $result['level'], 'score' => $result['score'], 'factors' => $result['factors'],
                    // Stated in the payload so no consumer can mistake this for the
                    // deterministic group score.
                    'advisory_alongside' => 'sire_recurrence_groups.recurrence_risk',
                ],
                $this->levelConfidence($result),
                ['summary' => "Recurrence risk: {$result['level']}.", 'signals' => $result['reasons'], 'references' => []],
                $actor);
        });
    }

    /** Existing KB articles that may already answer this issue. */
    public function knowledge(Report $report, SireUserIdentity $actor): ?AiSuggestion
    {
        return $this->guard($report, AiCapability::KNOWLEDGE_RECOMMENDATION, function () use ($report, $actor) {
            $articles = $this->knowledge->recommend($report);

            if ($articles === []) {
                return null;
            }

            return $this->recordFor($report, AiCapability::KNOWLEDGE_RECOMMENDATION,
                ['articles' => $articles],
                (float) $articles[0]['relevance'],
                [
                    'summary' => sprintf('%d existing article(s) may already cover this.', count($articles)),
                    'signals' => array_column($articles, 'reason'),
                    'references' => array_column(array_column($articles, 'article'), 'title'),
                ],
                $actor);
        });
    }

    /**
     * How risky is shipping this release?
     *
     * ADVISORY ONLY, AND NEVER A GATE. Gates are deterministic and block; this
     * informs. A release must never be stopped by something whose reasoning nobody
     * can reconstruct — see D28.
     */
    public function releaseRisk(Release $release, SireUserIdentity $actor): ?AiSuggestion
    {
        return $this->guardSubject($release, AiCapability::RELEASE_RISK, function () use ($release, $actor) {
            $result = $this->risk->releaseRisk($this->riskInputs->forRelease($release));

            if ($this->risk->isUnevidenced($result)) {
                return null;
            }

            return $this->recordFor($release, AiCapability::RELEASE_RISK,
                [
                    'level' => $result['level'], 'score' => $result['score'], 'factors' => $result['factors'],
                    'is_gate' => false,
                ],
                $this->levelConfidence($result),
                ['summary' => "Release risk: {$result['level']}. Advisory only — the release gates decide what blocks.",
                 'signals' => $result['reasons'], 'references' => []],
                $actor);
        });
    }

    /**
     * Review the release notes rather than write them.
     *
     * Without a language model SIRE cannot rewrite prose, so it does not pretend
     * to. It reports what will read badly — entries with no customer-facing
     * summary, internal jargon that would reach a customer — which is a checkable
     * list rather than a guess, and more useful than generated wording.
     */
    public function reviewReleaseNotes(Release $release, SireUserIdentity $actor): ?AiSuggestion
    {
        return $this->guardSubject($release, AiCapability::RELEASE_NOTE_REFINEMENT, function () use ($release, $actor) {
            $issues = Report::query()
                ->forTenant($release->tenant_id)
                ->where('released_version_id', $release->id)
                ->with('category:id,code,release_class')
                ->get();

            $review = $this->notesReviewer->review($release, $issues);

            if ($review['counts']['shipped'] === 0) {
                return null;
            }

            return $this->recordFor($release, AiCapability::RELEASE_NOTE_REFINEMENT,
                $review,
                null,
                [
                    'summary' => $review['summary'],
                    'signals' => array_map(
                        fn (array $f) => "{$f['issue']}: {$f['detail']} {$f['suggestion']}",
                        array_slice($review['findings'], 0, 10),
                    ),
                    'references' => array_column($review['findings'], 'issue'),
                ],
                $actor);
        });
    }

    /**
     * Management-level answers from this tenant's register.
     *
     * Not persisted as a suggestion: there is no subject to attach it to and
     * nothing for a human to accept or reject. It is a report, returned live.
     */
    public function engineeringInsights(int $tenantId, SireUserIdentity $actor): ?array
    {
        if (! $this->gateway->isEnabled($tenantId, AiCapability::ENGINEERING_INSIGHTS)) {
            return null;
        }

        try {
            return $this->insights->all($tenantId, $actor);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Confidence from the weight of evidence, not from the risk level itself. */
    private function levelConfidence(array $result): float
    {
        // Three or more corroborating factors is as sure as a heuristic gets.
        return round(min(1.0, count($result['factors']) / 3), 4);
    }

    /**
     * Shared guard. Three things every local capability must honour:
     *   the tenant must have turned it on;
     *   a failure must never surface to a user doing something else;
     *   a superseded suggestion is retired, not deleted.
     */
    private function guard(Report $report, string $capability, callable $work): ?AiSuggestion
    {
        try {
            if (! $this->gateway->isEnabled((int) $report->tenant_id, $capability)) {
                return null;
            }

            return $work();
        } catch (Throwable $e) {
            // Local engines cannot be "down", but a malformed issue or a missing
            // index still must not break the page someone is reading.
            report($e);

            return null;
        }
    }

    /** Guard for a non-Report subject. Same three rules. */
    private function guardSubject($subject, string $capability, callable $work): ?AiSuggestion
    {
        try {
            if (! $this->gateway->isEnabled((int) $subject->tenant_id, $capability)) {
                return null;
            }

            return $work();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Record against any SIRE subject, not only a Report. */
    private function recordFor(
        $subject,
        string $capability,
        array $payload,
        ?float $confidence,
        array $evidence,
        SireUserIdentity $actor,
    ): AiSuggestion {
        $subjectType = $subject instanceof Release
            ? AiCapability::SUBJECT_RELEASE
            : AiCapability::SUBJECT_REPORT;

        AiSuggestion::query()
            ->forTenant($subject->tenant_id)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subject->id)
            ->where('capability', $capability)
            ->where('status', AiSuggestion::PENDING)
            ->update(['status' => AiSuggestion::SUPERSEDED]);

        return AiSuggestion::create([
            'tenant_id'     => $subject->tenant_id,   // explicit, never ambient
            'subject_type'  => $subjectType,
            'subject_id'    => $subject->id,
            'capability'    => $capability,
            'status'        => AiSuggestion::PENDING,
            'payload'       => $payload,
            'confidence'    => $confidence,
            'evidence'      => $evidence,
            'provider'      => self::PROVIDER,
            'model'         => self::MODEL,
            'model_version' => self::VERSION,
            'redaction_report' => ['kept' => [], 'dropped' => [], 'truncated' => [], 'note' => 'Computed locally. No data left the tenant.'],
            'requested_by'  => $actor->id,
        ]);
    }

    private function record(
        Report $report,
        string $capability,
        array $payload,
        ?float $confidence,
        array $evidence,
        SireUserIdentity $actor,
    ): AiSuggestion {
        AiSuggestion::query()
            ->forTenant($report->tenant_id)
            ->where('subject_type', AiCapability::SUBJECT_REPORT)
            ->where('subject_id', $report->id)
            ->where('capability', $capability)
            ->where('status', AiSuggestion::PENDING)
            ->update(['status' => AiSuggestion::SUPERSEDED]);

        return AiSuggestion::create([
            'tenant_id'     => $report->tenant_id,   // explicit, never ambient
            'subject_type'  => AiCapability::SUBJECT_REPORT,
            'subject_id'    => $report->id,
            'capability'    => $capability,
            'status'        => AiSuggestion::PENDING,
            'payload'       => $payload,
            'confidence'    => $confidence,
            'evidence'      => $evidence,
            'provider'      => self::PROVIDER,
            'model'         => self::MODEL,
            'model_version' => self::VERSION,
            // Nothing was sent anywhere, and the record says so explicitly rather
            // than leaving it blank and ambiguous.
            'redaction_report' => ['kept' => [], 'dropped' => [], 'truncated' => [], 'note' => 'Computed locally. No data left the tenant.'],
            'requested_by'  => $actor->id,
        ]);
    }
}
