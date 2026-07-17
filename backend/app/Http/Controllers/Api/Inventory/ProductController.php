<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Inventory\StoreProductRequest;
use App\Http\Requests\Inventory\UpdateProductRequest;
use App\Services\Inventory\ProductService;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(private ProductService $products)
    {
    }

    public function index(Request $request)
    {
        $this->denyExternal($request);
        $filters = $request->only(['search', 'category_id', 'brand', 'status']);

        return $this->success($this->products->list($request->user()->tenant_id, $filters), 'Products retrieved');
    }

    public function store(StoreProductRequest $request)
    {
        $this->denyExternal($request);
        $product = $this->products->create($request->validated(), $request->user()->tenant_id, $request->user()->id);

        return $this->success($product, 'Product created', 201);
    }

    public function show(Request $request, int $product)
    {
        $this->denyExternal($request);

        return $this->success($this->products->show($product, $request->user()->tenant_id), 'Product retrieved');
    }

    public function update(UpdateProductRequest $request, int $product)
    {
        $this->denyExternal($request);

        return $this->success($this->products->update($product, $request->validated(), $request->user()->tenant_id), 'Product updated');
    }

    public function destroy(Request $request, int $product)
    {
        $this->requireAdmin($request, 'delete a product');
        $this->products->delete($product, $request->user()->tenant_id);

        return $this->success(null, 'Product deleted');
    }

    /** Scanner endpoint — resolve a barcode or SKU to a product. */
    public function lookup(Request $request)
    {
        $this->denyExternal($request);
        $data = $request->validate(['code' => 'required|string|max:60']);

        return $this->success($this->products->findByCode($data['code'], $request->user()->tenant_id), 'Product found');
    }
}
