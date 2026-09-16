<?php

namespace Sire\AI;

use Sire\Models\IssueToken;
use Sire\Models\Report;
use Sire\Services\SireAccessService;
use Sire\Dto\SireUserIdentity;
use Sire\Support\SireStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * SIRE — find issues that may already describe this one.
 *
 * TWO STAGES, and the split is the whole design.
 *
 *   RETRIEVE  cheap, indexed SQL narrows tens of thousands of issues to a few
 *             dozen candidates using the inverted index and structural signals.
 *   SCORE     the expensive comparison runs in PHP over that shortlist only.
 *
 * Scoring every issue would mean reading the whole register on a database shared
 * by two deployments. Retrieving by structure alone would miss anything filed from
 * a different screen. Neither stage works without the other.
 *
 * TENANT SECURITY, stated once and enforced everywhere below: every query begins
 * ->forTenant(). Scoping is opt-in in this codebase, so there is exactly one entry
 * point that builds a candidate query and nothing bypasses it.
 */
class SireDuplicateDetector
{
    /** Candidates retrieved before scoring. Wide enough to catch, small enough to score. */
    private const RETRIEVE_LIMIT = 60;

    /** Suggestions offered to a human. Five is a shortlist; twenty is a search result. */
    private const RETURN_LIMIT = 5;

    /** Minimum shared indexed terms to be worth scoring at all. */
    private const MIN_TOKEN_HITS = 2;

    public function __construct(
        private readonly SireTextAnalyzer $text,
        private readonly SireSimilarityScorer $scorer,
        private readonly SireIssueIndexer $indexer,
        private readonly SireAccessService $access,
    ) {
    }

    /**
     * @return array{candidates: array, query_tokens: array, searched: int}
     */
    public function detect(Report $report, SireUserIdentity $viewer): array
    {
        $tenantId = (int) $report->tenant_id;

        $queryTokens = $this->text->indexTokens($report->title, $report->description);

        if (count($queryTokens) < self::MIN_TOKEN_HITS) {
            // Two words of text cannot identify a duplicate. Returning nothing is
            // the honest answer; returning the most recent issues would be noise
            // dressed as analysis.
            return ['candidates' => [], 'query_tokens' => $queryTokens, 'searched' => 0];
        }

        $candidates = $this->retrieve($report, $queryTokens, $tenantId);
        $rare = $this->indexer->rareTokens($tenantId, $queryTokens);

        $query = $this->shapeOf($report);
        $now = CarbonImmutable::now();

        $scored = $candidates
            ->map(function (Report $candidate) use ($query, $rare, $now, $viewer) {
                $result = $this->scorer->score(
                    $query,
                    $this->shapeOf($candidate),
                    ['rare_tokens' => $rare, 'now' => $now],
                );

                return $result + ['candidate' => $this->present($candidate, $viewer)];
            })
            ->filter(fn (array $r) => $r['score'] >= $this->scorer::POSSIBLE)
            ->sortByDesc('score')
            ->take(self::RETURN_LIMIT)
            ->values();

        return [
            'candidates'   => $scored->all(),
            'query_tokens' => $queryTokens,
            'searched'     => $candidates->count(),
        ];
    }

    /**
     * Stage one. An indexed lookup on (tenant_id, token), ranked by how many of
     * the query's terms an issue shares and how heavily they are weighted.
     */
    private function retrieve(Report $report, array $tokens, int $tenantId): Collection
    {
        $ids = IssueToken::query()
            ->forTenant($tenantId)                       // opt-in scope: never omit
            ->whereIn('token', $tokens)
            ->where('report_id', '!=', $report->id)      // an issue is not its own duplicate
            ->groupBy('report_id')
            ->havingRaw('COUNT(*) >= ?', [self::MIN_TOKEN_HITS])
            ->orderByRaw('SUM(weight) DESC')
            ->limit(self::RETRIEVE_LIMIT)
            ->pluck('report_id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return Report::query()
            ->forTenant($tenantId)                       // scoped again, deliberately
            ->whereIn('id', $ids)
            // A rejected or withdrawn issue is not something to be a duplicate of.
            ->whereNotIn('status', [SireStatus::REJECTED, SireStatus::CANNOT_REPRODUCE])
            ->with(['category:id,code,name', 'severity:id,code,name', 'rootCause'])
            ->get();
    }

    private function shapeOf(Report $report): array
    {
        return [
            'title'        => $report->title,
            'description'  => $report->description,
            'module'       => $report->module,
            'section'      => $report->section,
            'screen'       => $report->screen,
            'category'     => $report->category?->code,
            'is_duplicate' => $report->duplicate_of_id !== null || $report->status === SireStatus::DUPLICATE,
            'closed_at'    => $report->closed_at?->toIso8601String(),
        ];
    }

    /**
     * What a viewer is shown about a candidate.
     *
     * A MINIMAL PROJECTION, always: number, title, status, module, and — the part
     * that makes this worth reading — how the issue was previously resolved. No
     * description, no comments, no attachments, no customer identifiers.
     *
     * `viewer_can_open` reflects the ordinary visibility rules. A candidate the
     * viewer cannot open is still surfaced, because knowing "this may already be
     * filed" is exactly what stops a duplicate being created — but its title is
     * withheld and they must go through the normal route to see it. Hiding it
     * entirely would make the feature useless for the people who file most
     * duplicates.
     */
    private function present(Report $candidate, SireUserIdentity $viewer): array
    {
        $canOpen = $this->viewerCanOpen($candidate, $viewer);

        return [
            'id'              => $candidate->id,
            'report_number'   => $candidate->report_number,
            'title'           => $canOpen ? $candidate->title : null,
            'restricted'      => ! $canOpen,
            'status'          => $candidate->status,
            'module'          => $candidate->module,
            'category'        => $candidate->category?->name,
            'severity'        => $candidate->severity?->name,
            'created_at'      => $candidate->created_at?->toIso8601String(),
            'closed_at'       => $candidate->closed_at?->toIso8601String(),
            // The single most useful thing about a duplicate: how it ended.
            'resolution'      => $candidate->resolution,
            'fix_summary'     => $canOpen ? $candidate->fix_summary : null,
            'released_in'     => $canOpen ? $candidate->releasedVersion?->version : null,
            'priority'        => $candidate->priority,

            /*
             * The confirmed analysis, when there is one. Root cause SUGGESTION
             * reads this: a suggestion is only ever grounded in an analysis a human
             * already signed off, never in one still being drafted.
             *
             * Gated on visibility like fix_summary — an investigation someone
             * cannot open is not summarised for them.
             */
            'root_cause'      => ($canOpen && $candidate->rootCause) ? [
                'category'             => $candidate->rootCause->category,
                'description'          => $candidate->rootCause->description,
                'detection_gap'        => $candidate->rootCause->detection_gap,
                'contributing_factors' => $candidate->rootCause->contributing_factors ?? [],
                'confirmed_at'         => $candidate->rootCause->confirmed_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * May this viewer open the candidate SIRE is about to name?
     *
     * A duplicate suggestion shows another issue's TITLE, so the check has to
     * happen before the suggestion is built, not when the link is clicked.
     *
     * This used to test $viewer->role against 'admin' and 'staff' — a guess at
     * one host's role names that silently hid every candidate in an application
     * calling them anything else. It now asks the access service, which is the
     * one place that knows what visibility means.
     */
    private function viewerCanOpen(Report $candidate, SireUserIdentity $viewer): bool
    {
        if ($this->access->can($viewer, 'sire.report.view_global', $candidate)) {
            return true;
        }

        return (int) $candidate->reporter_id === (int) $viewer->id
            || (int) $candidate->assignee_id === (int) $viewer->id
            || (int) $candidate->qa_assignee_id === (int) $viewer->id;
    }
}
