<?php

namespace Sire\Http\Controllers;

use Sire\Http\Controllers\Concerns\AssertsSireTenantOwnership;
use Sire\Http\Controllers\Concerns\ResolvesSireUser;
use Sire\Http\Controllers\Concerns\SireApiResponse;
use Sire\Models\AiSuggestion;
use Sire\Models\Release;
use Sire\Models\Report;
use Sire\AI\SireAiContextBuilder;
use Sire\AI\SireAiGateway;
use Sire\AI\SireAiSuggestionService;
use Sire\AI\SireLocalInsights;
use Sire\Support\Ai\AiCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SIRE AI — the API surface.
 *
 * FOUNDATION ONLY. `suggest` is wired end to end and returns `unavailable`,
 * because the only registered provider declines everything. That is not a stub:
 * it is the entire request path — settings, provider resolution, context building,
 * redaction — exercised and proven to fail safely.
 */
class SireAiController extends SireController
{
    use SireApiResponse;
    use ResolvesSireUser;
    use AssertsSireTenantOwnership;

    public function __construct(
        private readonly SireAiGateway $gateway,
        private readonly SireAiSuggestionService $suggestions,
        private readonly SireAiContextBuilder $contexts,
        private readonly SireLocalInsights $local,
    ) {
    }

    /** GET /sire/ai/status — what is on, what could be, and what the provider is. */
    public function status(Request $request): JsonResponse
    {
        return $this->success($this->gateway->status((int) $this->sireUser()->tenantId));
    }

    /**
     * GET /sire/ai/capabilities — the declared catalogue, and which of it works.
     *
     * `implemented` used to be a hardcoded empty array with a note saying "Phase 3
     * is foundation only". That was true when it was written and false by the time
     * SireLocalInsights landed, so the endpoint told every client that nothing
     * worked while thirteen engines sat behind it. It reads the registry now, and
     * cannot go stale again.
     */
    public function capabilities(): JsonResponse
    {
        $implemented = AiCapability::IMPLEMENTED_LOCALLY;

        return $this->success([
            'capabilities' => AiCapability::CATALOGUE,
            // Stated in the payload so a client cannot mistake a declared
            // capability for a working one.
            'implemented'  => $implemented,
            'note'         => sprintf(
                '%d of %d capabilities are implemented, and every one computes locally — '
                .'no external provider is required or integrated.',
                count($implemented),
                count(AiCapability::ALL),
            ),
        ]);
    }

    /**
     * POST /sire/ai/reports/{report}/suggest
     *
     * Returns `unavailable` today. Kept in the surface because a wiring path that
     * is never called is a wiring path that does not work.
     */
    public function suggestForReport(Request $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        $capability = $request->validate([
            'capability' => ['required', Rule::in(AiCapability::ALL)],
        ])['capability'];

        $result = $this->gateway->suggest(
            (int) $this->sireUser()->tenantId,
            $capability,
            $report,
            $this->contexts->build($report),
            $this->sireUser(),
        );

        return $this->success($result->toArray());
    }

    /**
     * POST /sire/ai/reports/{report}/insights
     *
     * Classification and duplicate candidates, computed locally. Nothing leaves
     * the tenant.
     *
     * DELIBERATELY NOT ON THE CREATE PATH. Issue creation does not call this, does
     * not wait for it and cannot be broken by it — the strongest form of "AI being
     * unavailable does not break issue creation" is AI not being on that path at
     * all. The client asks for insights after the issue exists.
     */
    public function insights(Request $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        $actor = $this->sireUser();

        // Both return null when the capability is off or nothing useful could be
        // said. Null means "no suggestions", never an error.
        $phase = $request->query('phase') === 'qa' ? 'qa' : 'developer';
        $tenantId = (int) $actor->tenantId;

        return $this->success([
            'classification' => $this->local->classify($report, $actor),
            // Severity and priority also have capabilities of their own, so a
            // workspace can take SIRE's view on the module while keeping the
            // clock-driving fields a human judgement.
            'severity'       => $this->local->recommendSeverity($report, $actor),
            'priority'       => $this->local->recommendPriority($report, $actor),
            'duplicates'     => $this->local->duplicates($report, $actor),
            // Labelled everywhere it is shown as "AI Suggested Root Cause".
            // Never written into the confirmed analysis.
            'root_cause'     => $this->local->rootCause($report, $actor),
            // Proposals only. Accepting posts them to the ordinary test-case
            // endpoint, where a person is the author and no result can be set.
            'test_cases'     => $this->local->generateTestCases($report, $actor, $phase),

            // How likely is fixing this to break something that worked?
            'regression_risk' => $this->local->regressionRisk($report, $actor),
            // A SECOND OPINION on recurrence — the deterministic group score in
            // sire_recurrence_groups stands alongside it and is never overwritten.
            'recurrence_risk' => $this->local->recurrenceRiskOpinion($report, $actor),
            // Existing articles that may already answer this.
            'knowledge'       => $this->local->knowledge($report, $actor),

            'enabled' => [
                'classification'       => $this->gateway->isEnabled($tenantId, AiCapability::CLASSIFICATION),
                'regression_risk'      => $this->gateway->isEnabled($tenantId, AiCapability::REGRESSION_RISK),
                'recurrence_risk'      => $this->gateway->isEnabled($tenantId, AiCapability::RECURRENCE_RISK),
                'knowledge_recommendation' => $this->gateway->isEnabled($tenantId, AiCapability::KNOWLEDGE_RECOMMENDATION),
                'duplicate_detection'  => $this->gateway->isEnabled($tenantId, AiCapability::DUPLICATE_DETECTION),
                'root_cause_suggestion' => $this->gateway->isEnabled($tenantId, AiCapability::ROOT_CAUSE_SUGGESTION),
                'developer_test_cases' => $this->gateway->isEnabled($tenantId, AiCapability::DEVELOPER_TEST_CASES),
                'qa_test_cases'        => $this->gateway->isEnabled($tenantId, AiCapability::QA_TEST_CASES),
            ],
        ]);
    }

    /**
     * POST /sire/ai/releases/{release}/insights
     *
     * Release risk and a review of the release notes. Both advisory: the gates
     * decide what blocks, and publication still needs the approval from D19.
     */
    public function releaseInsights(Request $request, Release $release): JsonResponse
    {
        $this->assertTenantOwnership($release);

        $actor = $this->sireUser();
        $tenantId = (int) $actor->tenantId;

        return $this->success([
            'release_risk'  => $this->local->releaseRisk($release, $actor),
            'notes_review'  => $this->local->reviewReleaseNotes($release, $actor),
            'enabled'       => [
                'release_risk'            => $this->gateway->isEnabled($tenantId, AiCapability::RELEASE_RISK),
                'release_note_refinement' => $this->gateway->isEnabled($tenantId, AiCapability::RELEASE_NOTE_REFINEMENT),
            ],
        ]);
    }

    /**
     * GET /sire/ai/engineering-insights
     *
     * Management-level answers from THIS TENANT's register. Returned live rather
     * than stored: there is no subject to attach it to and nothing for a human to
     * accept or reject — it is a report, not a suggestion.
     */
    public function engineeringInsights(Request $request): JsonResponse
    {
        $actor = $this->sireUser();
        $tenantId = (int) $actor->tenantId;

        $insights = $this->local->engineeringInsights($tenantId, $actor);

        return $this->success([
            'insights' => $insights,
            'enabled'  => $this->gateway->isEnabled($tenantId, AiCapability::ENGINEERING_INSIGHTS),
        ]);
    }

    /** GET /sire/ai/reports/{report}/suggestions — advisory decorations only. */
    public function forReport(Request $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        return $this->success($this->suggestions->for(
            (int) $this->sireUser()->tenantId,
            AiCapability::SUBJECT_REPORT,
            (int) $report->id,
            $request->boolean('pending_only', true),
        ));
    }

    /**
     * POST /sire/ai/suggestions/{suggestion}/decide
     *
     * Records accept / reject / modify. It does NOT apply anything: the human then
     * performs the ordinary action through the ordinary endpoint, with themselves
     * as the actor. One extra call buys an AI layer with no write path to core
     * data at all.
     */
    public function decide(Request $request, AiSuggestion $suggestion): JsonResponse
    {
        $this->assertTenantOwnership($suggestion);

        $data = $request->validate([
            'decision'    => ['required', Rule::in(AiSuggestion::HUMAN_DECISIONS)],
            'note'        => ['nullable', 'string', 'max:2000'],
            'final_value' => ['nullable', 'array'],
        ]);

        $actor = $this->sireUser();

        $updated = match ($data['decision']) {
            AiSuggestion::ACCEPTED => $this->suggestions->accept($suggestion, $actor, $data['note'] ?? null),
            AiSuggestion::REJECTED => $this->suggestions->reject($suggestion, $actor, $data['note'] ?? null),
            AiSuggestion::MODIFIED => $this->suggestions->modify($suggestion, $actor, $data['final_value'] ?? [], $data['note'] ?? null),
            // "Not sure" — seen, and left for someone who knows. A different
            // signal from disagreement, and recorded as one.
            AiSuggestion::DEFERRED => $this->suggestions->defer($suggestion, $actor, $data['note'] ?? null),
        };

        return $this->success([
            'suggestion' => $updated,
            // Handed back so the client can pre-fill the ordinary form. Applying
            // it is a separate, human-authored request.
            // Handed back so the client can pre-fill the ordinary form. Applying
            // it is a separate, human-authored request — SIRE never writes an
            // issue field from a suggestion.
            'apply'      => $data['decision'] === AiSuggestion::ACCEPTED ? $updated->payload : null,
        ]);
    }
}
