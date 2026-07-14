<?php

namespace App\Services\Helpdesk;

use App\Exceptions\BusinessException;
use App\Models\Helpdesk\KbArticle;
use App\Models\Helpdesk\KbCategory;
use App\Models\Helpdesk\KbSubcategory;
use App\Repositories\Helpdesk\KbArticleRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class KnowledgeBaseService
{
    public function __construct(private KbArticleRepository $articles)
    {
    }

    /* ── Categories ─────────────────────────────────────────────── */

    /** Admin tree: categories → sub-categories (with article counts). */
    public function listCategories(int $tenantId): Collection
    {
        return KbCategory::forTenant($tenantId)
            ->withCount(['subcategories', 'articles'])
            ->with(['subcategories' => fn ($q) => $q->withCount('articles')->orderBy('name')])
            ->orderBy('name')
            ->get();
    }

    public function createCategory(array $data, int $tenantId): KbCategory
    {
        return KbCategory::create([
            'tenant_id' => $tenantId,
            'name'      => $data['name'],
            'slug'      => $this->uniqueCategorySlug($data['slug'] ?? $data['name'], $tenantId),
        ]);
    }

    public function updateCategory(int $categoryId, array $data, int $tenantId): KbCategory
    {
        $category = $this->findCategory($categoryId, $tenantId);

        if (array_key_exists('name', $data)) {
            $category->name = $data['name'];
        }
        if (! empty($data['slug'])) {
            $category->slug = $this->uniqueCategorySlug($data['slug'], $tenantId, $category->id);
        }
        $category->save();

        return $category;
    }

    public function deleteCategory(int $categoryId, int $tenantId): void
    {
        $category = $this->findCategory($categoryId, $tenantId);

        if ($category->subcategories()->exists() || $category->articles()->exists()) {
            throw new BusinessException('Cannot delete a category that still has sub-categories or articles.', 422);
        }

        $category->delete();
    }

    /* ── Sub-categories ─────────────────────────────────────────── */

    public function listSubcategories(int $tenantId, ?int $categoryId = null): Collection
    {
        return KbSubcategory::forTenant($tenantId)
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->withCount('articles')
            ->with('category:id,name')
            ->orderBy('name')
            ->get();
    }

    public function createSubcategory(array $data, int $tenantId): KbSubcategory
    {
        $this->findCategory((int) $data['category_id'], $tenantId);

        return KbSubcategory::create([
            'tenant_id'   => $tenantId,
            'category_id' => $data['category_id'],
            'name'        => $data['name'],
            'slug'        => $this->uniqueSubcategorySlug($data['slug'] ?? $data['name'], (int) $data['category_id'], $tenantId),
        ]);
    }

    public function updateSubcategory(int $subcategoryId, array $data, int $tenantId): KbSubcategory
    {
        $sub = $this->findSubcategory($subcategoryId, $tenantId);

        if (array_key_exists('name', $data)) {
            $sub->name = $data['name'];
        }
        if (! empty($data['slug'])) {
            $sub->slug = $this->uniqueSubcategorySlug($data['slug'], $sub->category_id, $tenantId, $sub->id);
        }
        $sub->save();

        return $sub;
    }

    public function deleteSubcategory(int $subcategoryId, int $tenantId): void
    {
        $sub = $this->findSubcategory($subcategoryId, $tenantId);

        if ($sub->articles()->exists()) {
            throw new BusinessException('Cannot delete a sub-category that still has articles.', 422);
        }

        $sub->delete();
    }

    /* ── Articles ───────────────────────────────────────────────── */

    public function listArticles(int $tenantId, ?int $subcategoryId = null): Collection
    {
        return $this->articles->listing($tenantId, $subcategoryId);
    }

    public function showArticle(int $articleId, int $tenantId): KbArticle
    {
        return $this->findArticle($articleId, $tenantId)->load(['category:id,name,slug', 'subcategory:id,name,slug']);
    }

    public function createArticle(array $data, int $tenantId): KbArticle
    {
        // Articles live under a sub-category; derive the category from it.
        $sub = $this->findSubcategory((int) $data['subcategory_id'], $tenantId);

        return KbArticle::create([
            'tenant_id'      => $tenantId,
            'category_id'    => $sub->category_id,
            'subcategory_id' => $sub->id,
            'department_id'  => $data['department_id'] ?? null,
            'title'          => $data['title'],
            'excerpt'        => $data['excerpt'] ?? Str::limit(strip_tags($data['content']), 200),
            'content'        => $this->sanitizeHtml($data['content']),   // WYSIWYG HTML
            'thumbs_up'      => 0,
            'thumbs_down'    => 0,
        ]);
    }

    public function updateArticle(int $articleId, array $data, int $tenantId): KbArticle
    {
        $article = $this->findArticle($articleId, $tenantId);

        if (! empty($data['subcategory_id'])) {
            $sub = $this->findSubcategory((int) $data['subcategory_id'], $tenantId);
            $article->subcategory_id = $sub->id;
            $article->category_id = $sub->category_id;
        }
        if (array_key_exists('title', $data)) {
            $article->title = $data['title'];
        }
        if (array_key_exists('excerpt', $data)) {
            $article->excerpt = $data['excerpt'];
        }
        if (array_key_exists('content', $data)) {
            $article->content = $this->sanitizeHtml($data['content']);
        }
        if (array_key_exists('department_id', $data)) {
            $article->department_id = $data['department_id'];
        }
        $article->save();

        return $article;
    }

    public function deleteArticle(int $articleId, int $tenantId): void
    {
        $this->findArticle($articleId, $tenantId)->delete();
    }

    /** Register a helpful / not-helpful vote (internal, by id). */
    public function vote(int $articleId, string $direction, int $tenantId): KbArticle
    {
        $article = $this->findArticle($articleId, $tenantId);
        $article->increment($this->voteColumn($direction));

        return $article->fresh();
    }

    /* ── Publishing ─────────────────────────────────────────────── */

    /** Publish an article and mint a stable public share slug. */
    public function publish(int $articleId, int $tenantId): KbArticle
    {
        $article = $this->findArticle($articleId, $tenantId);

        if (! $article->public_slug) {
            $article->public_slug = $this->uniquePublicSlug($article->title);
        }
        $article->is_published = true;
        $article->published_at = now();
        $article->save();

        return $article->fresh();
    }

    public function unpublish(int $articleId, int $tenantId): KbArticle
    {
        $article = $this->findArticle($articleId, $tenantId);
        $article->update(['is_published' => false]);

        return $article->fresh();
    }

    /* ── Public (no-auth) reads ─────────────────────────────────── */

    /**
     * Category → sub-category → published-article tree for the public KB page.
     * Optionally scoped to a department (Phase 5) so KB content can be browsed the
     * same way tickets are organised.
     */
    public function publicTree(int $tenantId, ?int $departmentId = null): Collection
    {
        return KbCategory::forTenant($tenantId)
            ->with(['subcategories' => fn ($q) => $q->orderBy('name')
                ->with(['articles' => fn ($a) => $a->published()
                    ->when($departmentId, fn ($x) => $x->where('department_id', $departmentId))
                    ->orderBy('title')
                    ->get(['id', 'subcategory_id', 'department_id', 'title', 'excerpt', 'public_slug'])])])
            ->orderBy('name')
            ->get();
    }

    public function publicSearch(int $tenantId, ?string $term, ?int $departmentId = null): Collection
    {
        $results = $this->articles->searchPublished($tenantId, $term);

        return $departmentId
            ? $results->where('department_id', $departmentId)->values()
            : $results;
    }

    public function publicArticleBySlug(string $slug): KbArticle
    {
        $article = $this->articles->findPublishedBySlug($slug);

        if (! $article) {
            throw new BusinessException('Article not found.', 404);
        }

        // Attach the tenant's Help Center key so the public article can link back
        // to the browse/search home (PublicKb at /kb/{key}) — otherwise the page
        // is a dead-end island. Plus a few sibling articles for the related rail.
        $article->setAttribute(
            'kb_key',
            \App\Models\Helpdesk\HelpdeskWidgetSetting::where('tenant_id', $article->tenant_id)->value('public_key')
        );

        $article->setAttribute(
            'related',
            KbArticle::where('tenant_id', $article->tenant_id)
                ->where('is_published', true)
                ->where('category_id', $article->category_id)
                ->where('id', '!=', $article->id)
                ->latest('published_at')
                ->limit(4)
                ->get(['id', 'title', 'public_slug'])
        );

        return $article;
    }

    /** Public thumbs vote by share slug (no auth). */
    public function publicVote(string $slug, string $direction): KbArticle
    {
        $article = $this->articles->findPublishedBySlug($slug);

        if (! $article) {
            throw new BusinessException('Article not found.', 404);
        }

        $article->increment($this->voteColumn($direction));

        return $article->fresh();
    }

    /* ── Internals ──────────────────────────────────────────────── */

    private function voteColumn(string $direction): string
    {
        return match ($direction) {
            'up'   => 'thumbs_up',
            'down' => 'thumbs_down',
            default => throw new BusinessException('Vote direction must be "up" or "down".', 422),
        };
    }

    private function findCategory(int $categoryId, int $tenantId): KbCategory
    {
        $category = KbCategory::forTenant($tenantId)->find($categoryId);
        if (! $category) {
            throw new BusinessException('Knowledge base category not found.', 404);
        }

        return $category;
    }

    private function findSubcategory(int $subcategoryId, int $tenantId): KbSubcategory
    {
        $sub = KbSubcategory::forTenant($tenantId)->find($subcategoryId);
        if (! $sub) {
            throw new BusinessException('Knowledge base sub-category not found.', 404);
        }

        return $sub;
    }

    private function findArticle(int $articleId, int $tenantId): KbArticle
    {
        $article = KbArticle::forTenant($tenantId)->find($articleId);
        if (! $article) {
            throw new BusinessException('Knowledge base article not found.', 404);
        }

        return $article;
    }

    /**
     * Lightweight HTML sanitizer for WYSIWYG content — strips <script>/<style>,
     * on* event handlers, and javascript: URLs. For stricter guarantees, swap in
     * HTMLPurifier (composer) behind this method.
     */
    private function sanitizeHtml(string $html): string
    {
        $html = preg_replace('#<\s*(script|style)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? '';
        $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? $html;
        // Neutralize javascript: URLs whether the value is quoted or bare.
        $html = preg_replace('#(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2#i', '$1=$2#$2', $html) ?? $html;
        $html = preg_replace('#(href|src)\s*=\s*javascript:[^\s>]*#i', '$1="#"', $html) ?? $html;

        return $html;
    }

    private function uniqueCategorySlug(string $source, int $tenantId, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'category';
        $slug = $base;
        $i = 2;
        while (KbCategory::forTenant($tenantId)->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    private function uniqueSubcategorySlug(string $source, int $categoryId, int $tenantId, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'section';
        $slug = $base;
        $i = 2;
        while (KbSubcategory::forTenant($tenantId)->where('category_id', $categoryId)->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    private function uniquePublicSlug(string $title): string
    {
        do {
            $slug = Str::slug(Str::limit($title, 40, '')) . '-' . Str::lower(Str::random(6));
        } while (KbArticle::where('public_slug', $slug)->exists());

        return $slug;
    }
}
