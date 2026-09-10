<?php

namespace Sire\Adapters\Defaults;

use Sire\Contracts\SireKnowledgeProvider;
use Sire\Dto\SireKnowledgeArticle;
use Sire\Dto\SireUserIdentity;
use Illuminate\Support\Facades\Log;

/**
 * The shipped knowledge provider: reports honestly that no knowledge base is
 * connected.
 *
 * SIRE builds no knowledge base — that is a whole product, and most hosts
 * already have one. Until SireKnowledgeProvider is implemented, search returns
 * nothing, find returns null, and every knowledge feature degrades exactly as
 * designed: the KB panel says "no related articles", and issue pages, root cause
 * and release notes all keep working.
 *
 * WHAT STILL WORKS WITHOUT A HOST KB
 *
 * Issue↔article LINKS. SIRE stores those itself in sire_kb_links, so a team can
 * still record "this issue is explained by article 412" by id even with no
 * searchable KB behind it. Only search and drafting go quiet.
 *
 * isAvailable() is why this interface has that method: the UI hides the search
 * box rather than showing one that can never return anything.
 *
 * createDraft() logs rather than discarding, so a draft written before the KB is
 * wired is recoverable instead of silently lost.
 */
class SireLocalKnowledgeProvider implements SireKnowledgeProvider
{
    public function find(int $tenantId, int|string $articleId): ?SireKnowledgeArticle
    {
        return null;
    }

    public function search(int $tenantId, string $query, int $limit = 5): array
    {
        return [];
    }

    public function createDraft(int $tenantId, array $attributes, SireUserIdentity $author): SireKnowledgeArticle
    {
        Log::channel((string) config('sire.log_channel'))->info('sire.kb.draft', [
            'tenant'     => $tenantId,
            'author'     => $author->id,
            'attributes' => $attributes,
        ]);

        return new SireKnowledgeArticle(
            id: '',
            title: (string) ($attributes['title'] ?? ''),
            excerpt: $attributes['excerpt'] ?? null,
            status: 'draft',
        );
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
