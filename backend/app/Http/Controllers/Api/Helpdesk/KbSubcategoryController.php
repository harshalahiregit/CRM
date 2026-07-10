<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Helpdesk\StoreKbSubcategoryRequest;
use App\Services\Helpdesk\KnowledgeBaseService;
use Illuminate\Http\Request;

class KbSubcategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private KnowledgeBaseService $kb)
    {
    }

    public function index(Request $request)
    {
        $categoryId = $request->integer('category_id') ?: null;
        return $this->success($this->kb->listSubcategories($request->user()->tenant_id, $categoryId), 'Sub-categories retrieved');
    }

    public function store(StoreKbSubcategoryRequest $request)
    {
        $sub = $this->kb->createSubcategory($request->validated(), $request->user()->tenant_id);
        return $this->success($sub, 'Sub-category created', 201);
    }

    public function update(Request $request, int $subcategory)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'nullable|string|max:255',
        ]);
        $sub = $this->kb->updateSubcategory($subcategory, $data, $request->user()->tenant_id);
        return $this->success($sub, 'Sub-category updated');
    }

    public function destroy(Request $request, int $subcategory)
    {
        $this->kb->deleteSubcategory($subcategory, $request->user()->tenant_id);
        return $this->success(null, 'Sub-category deleted');
    }
}
