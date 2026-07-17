<?php

namespace App\Services\Helpdesk;

use App\Exceptions\BusinessException;
use App\Models\Helpdesk\KbArticle;
use App\Models\Helpdesk\KbArticleVote;
use App\Models\Helpdesk\KbCategory;
use App\Models\Helpdesk\KbSubcategory;
use App\Repositories\Helpdesk\KbArticleRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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
            'tags'           => $this->cleanTags($data['tags'] ?? null),
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
        if (array_key_exists('tags', $data)) {
            $article->tags = $this->cleanTags($data['tags']);
        }
        $article->save();

        return $article;
    }

    /** Normalise article tags: trim, drop blanks, de-duplicate (case-insensitive), cap at 12. */
    private function cleanTags(?array $tags): ?array
    {
        if ($tags === null) {
            return null;
        }

        return collect($tags)
            ->map(fn ($t) => trim((string) $t))
            ->filter()
            ->unique(fn ($t) => mb_strtolower($t))
            ->take(12)
            ->values()
            ->all();
    }

    public function deleteArticle(int $articleId, int $tenantId): void
    {
        $this->findArticle($articleId, $tenantId)->delete();
    }

    /** Register a helpful / not-helpful vote (internal, by id). One vote per user. */
    public function vote(int $articleId, string $direction, int $tenantId): KbArticle
    {
        return $this->registerVote($this->findArticle($articleId, $tenantId), $direction);
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

        return $this->registerVote($article, $direction);
    }

    /* ── Internals ──────────────────────────────────────────────── */

    /**
     * Record a vote at most once per voter (BUG-06).
     *
     * A repeat vote in the same direction is rejected; a vote in the opposite
     * direction flips, moving the count from one counter to the other. The
     * kb_articles counters stay the fast read path — this only guards writes.
     */
    private function registerVote(KbArticle $article, string $direction): KbArticle
    {
        $column = $this->voteColumn($direction);   // validates direction before any write
        $fingerprint = $this->voterFingerprint();

        return DB::transaction(function () use ($article, $direction, $column, $fingerprint) {
            $existing = KbArticleVote::where('article_id', $article->id)
                ->where('fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->vote === $direction) {
                    throw new BusinessException('You have already voted on this article.', 409);
                }

                // Flip: take the previous vote back, then apply the new one. The
                // guard keeps the unsigned counter from underflowing on rows that
                // predate this table (votes counted, but never recorded).
                $previous = $this->voteColumn($existing->vote);
                if ((int) $article->{$previous} > 0) {
                    $article->decrement($previous);
                }
                $article->increment($column);
                $existing->update(['vote' => $direction]);

                return $article->fresh();
            }

            try {
                KbArticleVote::create([
                    'tenant_id'   => $article->tenant_id,
                    'article_id'  => $article->id,
                    'fingerprint' => $fingerprint,
                    'vote'        => $direction,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Two simultaneous first votes from the same voter — the unique
                // index is the real arbiter, so the loser is simply a duplicate.
                throw new BusinessException('You have already voted on this article.', 409);
            }

            $article->increment($column);

            return $article->fresh();
        });
    }

    /**
     * Opaque per-voter identity. Signed-in staff are keyed by user id; anonymous
     * public readers by IP + user-agent. Hashed, so no raw IP is ever stored.
     */
    private function voterFingerprint(): string
    {
        if (auth()->check()) {
            return hash('sha256', 'user:'.auth()->id());
        }

        $request = request();

        return hash('sha256', 'anon:'.$request->ip().'|'.(string) $request->userAgent());
    }

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
     * Allowlist HTML sanitizer for WYSIWYG content (BUG-26).
     *
     * The previous version was a regex pass that only knew about <script>/<style>,
     * quoted on* handlers and javascript: URLs. It missed SVG event handlers,
     * data: URIs, <iframe>, <form action="javascript:">, unquoted handlers and
     * entity-obfuscated schemes — and KB content renders as raw HTML, so those
     * were stored XSS. Regex cannot parse HTML; this parses with DOMDocument and
     * keeps only what is explicitly allowed.
     *
     * No purifier package is installed (checked composer.json/lock), and adding a
     * dependency would assume network access, so this is deliberately hand-built
     * but allowlist-based: anything not named below does not survive.
     */
    private const ALLOWED_TAGS = [
        'p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'a',
        'h1', 'h2', 'h3', 'h4', 'blockquote', 'code', 'pre', 'img',
        'table', 'thead', 'tbody', 'tr', 'td', 'th', 'span', 'div',
    ];

    /** Everything else — style, target, srcset, formaction, and every on* handler — is dropped. */
    private const ALLOWED_ATTRS = ['href', 'src', 'alt', 'title', 'colspan', 'rowspan'];

    /** Tags whose *contents* are unsafe too, so the whole subtree goes. */
    private const STRIPPED_SUBTREES = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
        'svg', 'math', 'form', 'input', 'button', 'select', 'option', 'textarea',
        'link', 'meta', 'base', 'template', 'noscript',
    ];

    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    private function sanitizeHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        // The XML encoding hint keeps UTF-8 intact; the wrapper gives us a stable
        // fragment root. LIBXML_NONET refuses any external entity fetch.
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"?><div id="kb-root">'.$html.'</div>',
            LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            // Unparseable markup is not worth guessing at — drop to plain text.
            return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
        }

        $root = (new \DOMXPath($dom))->query('//div[@id="kb-root"]')->item(0);

        if (! $root instanceof \DOMElement) {
            return '';
        }

        $this->sanitizeNode($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }

    /** Recursively enforce the tag allowlist. Text nodes are kept (escaped on output). */
    private function sanitizeNode(\DOMNode $node): void
    {
        // Snapshot: the live NodeList shifts underneath us as children are removed.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMText) {
                continue;
            }

            if (! $child instanceof \DOMElement) {
                // Comments / CDATA / processing instructions have no place in KB copy
                // and are a classic conditional-comment vector.
                $node->removeChild($child);
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::STRIPPED_SUBTREES, true)) {
                $node->removeChild($child);
                continue;
            }

            if (in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->scrubAttributes($child);
                $this->sanitizeNode($child);
                continue;
            }

            // Unknown-but-not-dangerous wrapper (e.g. <font>, <section>): drop the
            // tag, keep its children — they are sanitized in turn, so nothing unsafe
            // can be smuggled through by wrapping it.
            $this->sanitizeNode($child);
            while ($child->firstChild) {
                $node->insertBefore($child->firstChild, $child);
            }
            $node->removeChild($child);
        }
    }

    private function scrubAttributes(\DOMElement $el): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->nodeName);

            if (! in_array($name, self::ALLOWED_ATTRS, true)) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }

            if (($name === 'href' || $name === 'src') && ! $this->isSafeUrl($attr->nodeValue)) {
                $el->removeAttribute($attr->nodeName);
            }
        }
    }

    /** Only http/https/mailto (or scheme-less relative links) survive. */
    private function isSafeUrl(?string $url): bool
    {
        // Decode entities and strip control chars/whitespace first, so tricks like
        // "java&#09;script:", "java\0script:" and " javascript:" cannot hide a scheme.
        $value = html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x00-\x20\x7F]+/', '', $value) ?? '';

        if ($value === '') {
            return false;
        }

        // Fragment or root-relative — no scheme, so nothing to abuse.
        if (str_starts_with($value, '#') || str_starts_with($value, '/')) {
            return true;
        }

        if (! preg_match('#^([a-z][a-z0-9+.\-]*):#i', $value, $m)) {
            return true;   // no scheme at all → relative path
        }

        return in_array(strtolower($m[1]), self::ALLOWED_SCHEMES, true);
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

    /**
     * Mint a share slug (BUG-31).
     *
     * The unbounded loop was never a realistic hang: the slug carries 6 random
     * alphanumerics (36^6 ≈ 2.2 billion per title), so a collision is already
     * vanishingly rare and two in a row effectively impossible. Rather than
     * over-engineer it, the retry count is simply capped so the method cannot spin
     * forever if the RNG is ever stubbed or seeded identically in tests.
     */
    private function uniquePublicSlug(string $title): string
    {
        $base = Str::slug(Str::limit($title, 40, '')) ?: 'article';

        for ($i = 0; $i < 10; $i++) {
            $slug = $base.'-'.Str::lower(Str::random(6));
            if (! KbArticle::where('public_slug', $slug)->exists()) {
                return $slug;
            }
        }

        // Ten collisions means the randomness is not random; fall back to a value
        // that is unique by construction rather than by chance.
        return $base.'-'.Str::lower(Str::random(6)).'-'.uniqid();
    }
}
