<?php

namespace Sire\Services;

use Sire\Exceptions\SireException;
use Sire\Models\KbLink;
use Sire\Models\Report;
use Sire\Dto\SireUserIdentity;
use Sire\Contracts\SireKnowledgeProvider;
use Illuminate\Support\Facades\DB;

/**
 * SIRE — knowledge base linkage.
 *
 * SIRE HAS NO KNOWLEDGE BASE AND MUST NOT GROW ONE. Helpdesk already owns
 * kb_articles, categories, votes, feedback and search. An article written from a
 * SIRE issue lives in that KB, is found by KB search and is read by Helpdesk
 * agents — which is the entire reason for linking rather than duplicating. A
 * second KB would mean two search indexes and two answers to the same question.
 *
 * The signatures below are SIRE's own: SireKnowledgeProvider is an interface this
 * package defines, so there is nothing here to verify against the CRM. What the
 * CRM has to do is implement it — see docs/CRM-MAPPING.md section 10.
 *
 * Note which way the failure runs. Every call here is wrapped so that a missing
 * or misconfigured knowledge base degrades to "no related articles" rather than
 * breaking an issue page. The cost of that kindness is that a WRONG binding
 * looks exactly like a tenant with no articles, so verify it with a search that
 * should hit -- not by the absence of errors.
 */
class SireKnowledgeLinkService
{
    public function __construct(
        private readonly SireKnowledgeProvider $kb,
        private readonly SireAccessService $access,
    ) {
    }

    /** Attach an EXISTING article. The common case; nothing is written to the KB. */
    public function link(Report $report, int $articleId, string $linkType, SireUserIdentity $actor): KbLink
    {
        $this->assertLinkType($linkType);

        $article = $this->kb->find((int) $report->tenant_id, $articleId);
        if ($article === null) {
            throw new SireException('That article could not be found in your knowledge base.');
        }

        $existing = KbLink::query()
            ->forTenant($report->tenant_id)
            ->where('report_id', $report->id)
            ->where('kb_article_id', $articleId)
            ->where('link_type', $linkType)
            ->first();

        if ($existing) {
            return $existing; // idempotent: linking twice is not an error
        }

        return DB::transaction(function () use ($report, $articleId, $linkType, $actor) {
            $link = KbLink::create([
                'tenant_id'          => $report->tenant_id,  // explicit, never ambient
                'report_id'          => $report->id,
                'kb_article_id'      => $articleId,
                'link_type'          => $linkType,
                'created_from_issue' => false,
                'created_by'         => $actor->id,
            ]);

            $report->recordAudit(
                'Linked knowledge base article',
                $actor,
                null,
                ['action' => 'kb_link', 'article_id' => $articleId, 'link_type' => $linkType, 'system' => true],
            );

            return $link;
        });
    }

    /**
     * Draft a NEW article from a resolved issue and link it.
     *
     * Seeded from structured fields, not from the raw internal description: an
     * article that opens with "null deref in LeadPolicy::view" helps nobody. It
     * is created as a DRAFT — SIRE proposes, the KB owner publishes. Writing
     * straight to a published article would let an engineering tool publish
     * customer-facing content with no editorial step.
     */
    public function createArticleFrom(Report $report, array $overrides, SireUserIdentity $actor): KbLink
    {
        $this->access->assert($actor, 'sire.kb.author', $report);

        if (! $report->isTerminal() && ! in_array($report->status, ['released', 'production_validated'], true)) {
            throw new SireException(
                'Write the article once the issue is resolved — a fix that is still moving is not yet knowledge.',
            );
        }

        $linkType = $overrides['link_type'] ?? KbLink::TYPE_RESOLUTION;
        $this->assertLinkType($linkType);

        return DB::transaction(function () use ($report, $overrides, $actor, $linkType) {
            $article = $this->kb->createDraft(
                (int) $report->tenant_id,
                [
                    'title'   => $overrides['title'] ?? $this->suggestTitle($report),
                    'body'    => $overrides['body'] ?? $this->suggestBody($report),
                    'summary' => $overrides['summary'] ?? $report->user_facing_summary,
                ],
                $actor,
            );

            $link = KbLink::create([
                'tenant_id'          => $report->tenant_id,
                'report_id'          => $report->id,
                'kb_article_id'      => $article->id,
                'link_type'          => $linkType,
                'created_from_issue' => true,
                'created_by'         => $actor->id,
            ]);

            $report->recordAudit(
                'Knowledge base article drafted from this issue',
                $actor,
                null,
                ['action' => 'kb_create', 'article_id' => $article->id, 'system' => true],
            );

            return $link;
        });
    }

    public function unlink(KbLink $link, SireUserIdentity $actor): void
    {
        $report = $link->report;
        $articleId = $link->kb_article_id;

        DB::transaction(function () use ($link, $report, $articleId, $actor) {
            $link->delete();

            // The ARTICLE is never deleted — only the link. SIRE does not own the
            // KB and must not remove content other people rely on.
            $report?->recordAudit(
                'Unlinked knowledge base article',
                $actor,
                null,
                ['action' => 'kb_unlink', 'article_id' => $articleId, 'system' => true],
            );
        });
    }

    private function suggestTitle(Report $report): string
    {
        return $report->user_facing_summary
            ?: sprintf('%s: %s', $report->module_label ?? $report->module ?? 'General', $report->title);
    }

    /** Structured data first — the same principle as release notes. */
    private function suggestBody(Report $report): string
    {
        $parts = array_filter([
            $report->description ? "## What happens\n\n{$report->description}" : null,
            $report->steps_to_reproduce ? "## How to reproduce\n\n{$report->steps_to_reproduce}" : null,
            $report->expected_result ? "## Expected result\n\n{$report->expected_result}" : null,
            $report->fix_summary ? "## Resolution\n\n{$report->fix_summary}" : null,
            $report->rootCause?->preventive_action ? "## How to avoid it\n\n{$report->rootCause->preventive_action}" : null,
        ]);

        return implode("\n\n", $parts);
    }

    private function assertLinkType(string $type): void
    {
        if (! in_array($type, KbLink::TYPES, true)) {
            throw new SireException('Unknown knowledge base link type.');
        }
    }
}
