<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Inventory\ProductService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(private ProductService $products)
    {
    }

    private function rules(Request $request): array
    {
        $tenantId = $request->user()->tenant_id;

        return [
            'name'        => 'required|string|max:120',
            'parent_id'   => ['nullable', 'integer', Rule::exists('inventory_categories', 'id')->where('tenant_id', $tenantId)],
            'description' => 'nullable|string|max:255',
            'order'       => 'nullable|integer|min:0',
        ];
    }

    public function index(Request $request)
    {
        $this->denyExternal($request);

        return $this->success($this->products->categories($request->user()->tenant_id), 'Categories retrieved');
    }

    public function store(Request $request)
    {
        $this->denyExternal($request);
        $data = $request->validate($this->rules($request));

        return $this->success($this->products->createCategory($data, $request->user()->tenant_id), 'Category created', 201);
    }

    public function update(Request $request, int $category)
    {
        $this->denyExternal($request);
        $data = $request->validate($this->rules($request));

        return $this->success($this->products->updateCategory($category, $data, $request->user()->tenant_id), 'Category updated');
    }

    public function destroy(Request $request, int $category)
    {
        $this->requireAdmin($request, 'delete a category');
        $this->products->deleteCategory($category, $request->user()->tenant_id);

        return $this->success(null, 'Category deleted');
    }
}
