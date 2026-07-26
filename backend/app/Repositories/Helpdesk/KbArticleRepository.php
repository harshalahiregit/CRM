<?php

namespace App\Repositories\Helpdesk;

use App\Models\Helpdesk\KbArticle;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class KbArticleRepository extends BaseRepository
{
    protected string $modelClass = KbArticle::class;

    /** Admin listing (all articles), optionally filtered by sub-category. */
    public function forTenant(int $tenantId): \Illuminate\Database\Eloquent\Builder
    {
        return KbArticle::forTenant($tenantId);
    }

    public function listing(int $tenantId, ?int $subcategoryId = null): Collection
    {
        return KbArticle::forTenant($tenantId)
            ->when($subcategoryId, fn ($q) => $q->where('subcategory_id', $subcategoryId))
            ->with(['category:id,name,slug', 'subcategory:id,name,slug'])
            ->orderBy('title')
            ->get();
    }

    /**
     * Full-text-ish search across published articles (public KB).
     *
     * `tags` is included (BUG-20): it is a JSON array column, so an article tagged
     * "GST" was previously unfindable by searching GST — the term only ever hit
     * title/excerpt/content. LIKE against the stored JSON text is the pragmatic
     * match here; it keeps one portable query across SQLite and MySQL rather than
     * needing a JSON function per driver.
     */
    public function searchPublished(int $tenantId, ?string $term = null): Collection
    {
        return KbArticle::forTenant($tenantId)->published()
            ->when($term, function ($q) use ($term) {
                $s = '%'.$term.'%';
                $q->where(fn ($sub) => $sub->where('title', 'like', $s)
                    ->orWhere('excerpt', 'like', $s)
                    ->orWhere('content', 'like', $s)
                    ->orWhere('tags', 'like', $s));
            })
            ->with(['category:id,name,slug', 'subcategory:id,name,slug'])
            ->orderBy('title')
            ->get();
    }

    /** A single published article by its public share slug (no tenant scope). */
    public function findPublishedBySlug(string $slug): ?KbArticle
    {
        return KbArticle::published()->where('public_slug', $slug)
            ->with(['category:id,name', 'subcategory:id,name'])
            ->first();
    }
}
