<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Helpdesk\StoreKbArticleRequest;
use App\Http\Requests\Helpdesk\UpdateKbArticleRequest;
use App\Services\Helpdesk\KnowledgeBaseService;
use Illuminate\Http\Request;

class KbArticleController extends Controller
{
    use ApiResponse;

    public function __construct(private KnowledgeBaseService $kb)
    {
    }

    public function index(Request $request)
    {
        $subcategoryId = $request->integer('subcategory_id') ?: null;
        return $this->success($this->kb->listArticles($request->user()->tenant_id, $subcategoryId), 'Articles retrieved');
    }

    public function show(Request $request, int $article)
    {
        return $this->success($this->kb->showArticle($article, $request->user()->tenant_id), 'Article retrieved');
    }

    public function store(StoreKbArticleRequest $request)
    {
        $article = $this->kb->createArticle($request->validated(), $request->user()->tenant_id);
        return $this->success($article, 'Article created', 201);
    }

    public function update(UpdateKbArticleRequest $request, int $article)
    {
        $result = $this->kb->updateArticle($article, $request->validated(), $request->user()->tenant_id);
        return $this->success($result, 'Article updated');
    }

    public function destroy(Request $request, int $article)
    {
        $this->kb->deleteArticle($article, $request->user()->tenant_id);
        return $this->success(null, 'Article deleted');
    }

    /* ── Publish → mint a public shareable link ────────────────── */
    public function publish(Request $request, int $article)
    {
        $result = $this->kb->publish($article, $request->user()->tenant_id);

        // Public article page is a FRONTEND route (/kb/a/:slug), not this API
        // endpoint — point the shareable link there.
        $frontend = rtrim(config('app.frontend_url') ?: config('app.url'), '/');

        return $this->success([
            'article'     => $result,
            'public_slug' => $result->public_slug,
            'public_url'  => "{$frontend}/kb/a/{$result->public_slug}",
        ], 'Article published');
    }

    public function unpublish(Request $request, int $article)
    {
        return $this->success($this->kb->unpublish($article, $request->user()->tenant_id), 'Article unpublished');
    }

    /* ── Helpful / not-helpful vote (admin/internal) ───────────── */
    public function vote(Request $request, int $article)
    {
        $data = $request->validate(['direction' => ['required', 'in:up,down']]);
        return $this->success($this->kb->vote($article, $data['direction'], $request->user()->tenant_id), 'Vote recorded');
    }

    /* ── Star rating + comment (REQ-11) ────────────────────────── */

    /**
     * Public (no-auth) star rating by share slug. Registered outside the Sanctum
     * group and rate-limited at the route, mirroring the public thumbs vote. One
     * rating per reader (de-duped by fingerprint in the service).
     */
    public function publicFeedback(Request $request, string $slug)
    {
        $data = $request->validate([
            'rating'  => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->success(
            $this->kb->submitFeedback($slug, (int) $data['rating'], $data['comment'] ?? null),
            'Thanks for your feedback'
        );
    }

    /** Admin: list the star-rating feedback left on an article. */
    public function feedback(Request $request, int $article)
    {
        return $this->success($this->kb->listFeedback($article, $request->user()->tenant_id), 'Feedback retrieved');
    }
}
