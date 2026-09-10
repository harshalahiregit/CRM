<?php

namespace Sire\AI;

use Sire\Models\Report;
use Sire\Contracts\SireKnowledgeProvider;
use Sire\Dto\SireKnowledgeArticle;
use Throwable;

/**
 * SIRE — recommend existing knowledge base articles for an issue.
 *
 * USES HELPDESK'S OWN SEARCH, then scores what it returns.
 *
 * The alternative was indexing kb_articles into SIRE's token table. Rejected: the
 * KB belongs to Helpdesk, and SIRE maintaining a shadow index of another module's
 * content means two things that can disagree about what an article says. Calling
 * the module's own search is both less infrastructure and less to go wrong.
 *
 * Retrieval is Helpdesk's; RANKING is SIRE's, using the same scorer that finds
 * duplicates — so an article recommendation comes with the same kind of evidence a
 * duplicate does: shared terms, not a number.
 *
 * Search goes through SireKnowledgeProvider, an interface this package defines,
 * so the signature is SIRE's own and needs no verification against the CRM. The
 * binding does: an unscoped implementation would surface another tenant's
 * article titles into a SIRE panel. See docs/CRM-MAPPING.md section 10.
 */
class SireKnowledgeRecommender
{
    /** Distinctive terms handed to Helpdesk's search. */
    private const QUERY_TERMS = 8;

    /** Articles scored. Helpdesk's search is a LIKE — do not ask it for the world. */
    private const CANDIDATE_LIMIT = 30;

    private const RETURN_LIMIT = 5;

    /** Below this, an article is not relevant enough to be worth a click. */
    private const MIN_RELEVANCE = 0.25;

    public function __construct(
        private readonly SireKnowledgeProvider $kb,
        private readonly SireTextAnalyzer $text,
        private readonly SireSimilarityScorer $scorer,
    ) {
    }

    public function recommend(Report $report): array
    {
        $tokens = $this->text->indexTokens($report->title, $report->description, self::QUERY_TERMS);

        if (count($tokens) < 2) {
            return [];
        }

        try {
            $articles = $this->kb->search(
                (int) $report->tenant_id,          // tenant-scoped by the module that owns it
                implode(' ', $tokens),
                self::CANDIDATE_LIMIT,
            );
        } catch (Throwable $e) {
            // The KB being unavailable must not take an issue page with it.
            report($e);

            return [];
        }

        $query = [
            'title'       => $report->title,
            'description' => $report->description,
        ];

        return collect($articles)
            ->map(function (SireKnowledgeArticle $article) use ($query) {
                // Structural fields are absent on both sides, so this is a pure
                // text comparison — which is honest: an article has no screen.
                $result = $this->scorer->score($query, [
                    'title'       => $article->title,
                    'description' => $article->excerpt,
                ]);

                return [
                    // The URL comes from the host, through the DTO. SIRE building
                    // one would hard-code another product's routing.
                    'article' => $article->toArray(),
                    'relevance' => $result['score'],
                    // "Why this article" — the same evidence shape a duplicate
                    // candidate carries, for the same reason.
                    'reason'    => $this->reasonFor($result['signals']),
                    'signals'   => $result['signals'],
                ];
            })
            ->filter(fn (array $r) => $r['relevance'] >= self::MIN_RELEVANCE)
            ->sortByDesc('relevance')
            ->take(self::RETURN_LIMIT)
            ->values()
            ->all();
    }

    private function reasonFor(array $signals): string
    {
        $shared = $signals['shared_terms'] ?? [];

        if ($shared === []) {
            return 'Matched on overall wording.';
        }

        return 'Shares the terms '.implode(', ', array_slice($shared, 0, 6)).' with this issue.';
    }
}
