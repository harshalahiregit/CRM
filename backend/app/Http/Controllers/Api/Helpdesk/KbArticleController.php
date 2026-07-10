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

        return $this->success([
            'article'     => $result,
            'public_slug' => $result->public_slug,
            'public_url'  => url("/api/helpdesk/public/kb/articles/{$result->public_slug}"),
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
}
