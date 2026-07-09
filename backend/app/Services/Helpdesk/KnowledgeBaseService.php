<?php

namespace App\Services\Helpdesk;

use App\Exceptions\BusinessException;
use App\Models\Helpdesk\KbArticle;
use App\Models\Helpdesk\KbCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class KnowledgeBaseService
{
    /* ── Categories ─────────────────────────────────────────────── */

    /** All categories with their nested articles (matches the KB UI shape). */
    public function listCategories(int $tenantId): Collection
    {
        return KbCategory::forTenant($tenantId)
            ->withCount('articles')
            ->with(['articles' => fn ($q) => $q->orderBy('title')])
            ->orderBy('name')
            ->get();
    }

    public function createCategory(array $data, int $tenantId): KbCategory
    {
        return KbCategory::create([
            'tenant_id' => $tenantId,
            'name'      => $data['name'],
            'slug'      => $this->uniqueSlug($data['slug'] ?? $data['name'], $tenantId),
        ]);
    }

    public function updateCategory(int $categoryId, array $data, int $tenantId): KbCategory
    {
        $category = $this->findCategory($categoryId, $tenantId);

        if (array_key_exists('name', $data)) {
            $category->name = $data['name'];
        }
        if (! empty($data['slug'])) {
            $category->slug = $this->uniqueSlug($data['slug'], $tenantId, $category->id);
        }
        $category->save();

        return $category;
    }

    public function deleteCategory(int $categoryId, int $tenantId): void
    {
        // Articles cascade at the DB level, but block deletion of a non-empty
        // category so it is never an accident.
        $category = $this->findCategory($categoryId, $tenantId);

        if ($category->articles()->exists()) {
            throw new BusinessException('Cannot delete a category that still has articles.', 422);
        }

        $category->delete();
    }

    /* ── Articles ───────────────────────────────────────────────── */

    public function listArticles(int $tenantId, ?int $categoryId = null): Collection
    {
        return KbArticle::forTenant($tenantId)
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->with('category:id,name,slug')
            ->orderBy('title')
            ->get();
    }

    public function showArticle(int $articleId, int $tenantId): KbArticle
    {
        return $this->findArticle($articleId, $tenantId)->load('category:id,name,slug');
    }

    public function createArticle(array $data, int $tenantId): KbArticle
    {
        // Ensure the parent category belongs to this tenant (no cross-tenant leak).
        $this->findCategory((int) $data['category_id'], $tenantId);

        return KbArticle::create([
            'tenant_id'   => $tenantId,
            'category_id' => $data['category_id'],
            'title'       => $data['title'],
            'content'     => $data['content'],
            'thumbs_up'   => 0,
            'thumbs_down' => 0,
        ]);
    }

    public function updateArticle(int $articleId, array $data, int $tenantId): KbArticle
    {
        $article = $this->findArticle($articleId, $tenantId);

        if (! empty($data['category_id'])) {
            $this->findCategory((int) $data['category_id'], $tenantId);
            $article->category_id = $data['category_id'];
        }
        if (array_key_exists('title', $data)) {
            $article->title = $data['title'];
        }
        if (array_key_exists('content', $data)) {
            $article->content = $data['content'];
        }
        $article->save();

        return $article;
    }

    public function deleteArticle(int $articleId, int $tenantId): void
    {
        $this->findArticle($articleId, $tenantId)->delete();
    }

    /** Register a helpful / not-helpful vote. */
    public function vote(int $articleId, string $direction, int $tenantId): KbArticle
    {
        $article = $this->findArticle($articleId, $tenantId);

        $column = match ($direction) {
            'up'   => 'thumbs_up',
            'down' => 'thumbs_down',
            default => throw new BusinessException('Vote direction must be "up" or "down".', 422),
        };

        $article->increment($column);

        return $article->fresh();
    }

    /* ── Internals ──────────────────────────────────────────────── */

    private function findCategory(int $categoryId, int $tenantId): KbCategory
    {
        $category = KbCategory::forTenant($tenantId)->find($categoryId);

        if (! $category) {
            throw new BusinessException('Knowledge base category not found.', 404);
        }

        return $category;
    }

    private function findArticle(int $articleId, int $tenantId): KbArticle
    {
        $article = KbArticle::forTenant($tenantId)->find($articleId);

        if (! $article) {
            throw new BusinessException('Knowledge base article not found.', 404);
        }

        return $article;
    }

    /** Slugify and guarantee uniqueness within the tenant. */
    private function uniqueSlug(string $source, int $tenantId, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'category';
        $slug = $base;
        $i = 2;

        while (
            KbCategory::forTenant($tenantId)
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
