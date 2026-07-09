<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Helpdesk\KnowledgeBaseService;
use Illuminate\Http\Request;

class KbCategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private KnowledgeBaseService $kb)
    {
    }

    /* ── List (categories + nested articles) ───────────────────── */
    public function index(Request $request)
    {
        $categories = $this->kb->listCategories($request->user()->tenant_id);

        return $this->success($categories, 'Categories retrieved');
    }

    /* ── Create ────────────────────────────────────────────────── */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
        ]);

        $category = $this->kb->createCategory($data, $request->user()->tenant_id);

        return $this->success($category, 'Category created', 201);
    }

    /* ── Update ────────────────────────────────────────────────── */
    public function update(Request $request, int $category)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->kb->updateCategory($category, $data, $request->user()->tenant_id);

        return $this->success($result, 'Category updated');
    }

    /* ── Delete ────────────────────────────────────────────────── */
    public function destroy(Request $request, int $category)
    {
        $this->kb->deleteCategory($category, $request->user()->tenant_id);

        return $this->success(null, 'Category deleted');
    }
}
