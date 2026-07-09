<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Helpdesk\KnowledgeBaseService;
use Illuminate\Http\Request;

class KbArticleController extends Controller
{
    use ApiResponse;

    public function __construct(private KnowledgeBaseService $kb)
    {
    }

    /* ── List (optionally by category) ─────────────────────────── */
    public function index(Request $request)
    {
        $categoryId = $request->integer('category_id') ?: null;
        $articles = $this->kb->listArticles($request->user()->tenant_id, $categoryId);

        return $this->success($articles, 'Articles retrieved');
    }

    /* ── Show ──────────────────────────────────────────────────── */
    public function show(Request $request, int $article)
    {
        $result = $this->kb->showArticle($article, $request->user()->tenant_id);

        return $this->success($result, 'Article retrieved');
    }

    /* ── Create ────────────────────────────────────────────────── */
    public function store(Request $request)
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'min:1'],
            'title'       => ['required', 'string', 'max:255'],
            'content'     => ['required', 'string'],
        ]);

        $article = $this->kb->createArticle($data, $request->user()->tenant_id);

        return $this->success($article, 'Article created', 201);
    }

    /* ── Update ────────────────────────────────────────────────── */
    public function update(Request $request, int $article)
    {
        $data = $request->validate([
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'title'       => ['sometimes', 'required', 'string', 'max:255'],
            'content'     => ['sometimes', 'required', 'string'],
        ]);

        $result = $this->kb->updateArticle($article, $data, $request->user()->tenant_id);

        return $this->success($result, 'Article updated');
    }

    /* ── Delete ────────────────────────────────────────────────── */
    public function destroy(Request $request, int $article)
    {
        $this->kb->deleteArticle($article, $request->user()->tenant_id);

        return $this->success(null, 'Article deleted');
    }

    /* ── Helpful / not-helpful vote ────────────────────────────── */
    public function vote(Request $request, int $article)
    {
        $data = $request->validate([
            'direction' => ['required', 'in:up,down'],
        ]);

        $result = $this->kb->vote($article, $data['direction'], $request->user()->tenant_id);

        return $this->success($result, 'Vote recorded');
    }
}
