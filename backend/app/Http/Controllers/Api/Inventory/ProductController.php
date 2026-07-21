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
        // §1 filter bar: Tags · Alert · Warehouse · Item, on top of the basics.
        $filters = $request->only([
            'search', 'category_id', 'brand', 'status',
            'tag', 'alert', 'warehouse_id', 'product_id',
        ]);

        return $this->success(
            $this->products->list($request->user()->tenant_id, $filters, [
                'user_id'  => $request->user()->id,
                'is_admin' => $this->isAdmin($request),
            ]),
            'Products retrieved'
        );
    }

    /** Every tag in use — feeds the Items page's Tags dropdown. */
    public function tags(Request $request)
    {
        $this->denyExternal($request);

        return $this->success($this->products->tags($request->user()->tenant_id), 'Tags retrieved');
    }

    /** Apply one action to a checkbox selection (§1 "Bulk actions"). */
    public function bulk(Request $request)
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'action' => 'required|string|in:delete,status,category,add_tags,remove_tags',
            'ids'    => 'required|array|min:1|max:500',
            'ids.*'  => 'integer|min:1',
            'value'  => 'nullable',
        ]);

        // Deleting items is master-data destruction, same bar as deleting one.
        if ($data['action'] === 'delete') {
            $this->requireAdmin($request, 'delete items');
        }

        $result = app(\App\Services\Inventory\ImportService::class)
            ->bulk($request->user()->tenant_id, $data['action'], $data['ids'], $data['value'] ?? null);

        return $this->success($result, 'Bulk action applied');
    }

    /** Item image upload (§1 "Attach images"). Stored privately, served back through the API. */
    public function uploadImage(Request $request, int $product)
    {
        $this->denyExternal($request);
        $request->validate(['image' => ['required', 'image', 'max:5120']]);   // 5 MB

        $tenantId = $request->user()->tenant_id;
        $row = $this->products->find($product, $tenantId);

        $path = $request->file('image')->store("inventory/products/{$tenantId}", 'local');
        $row->update(['image_path' => $path]);

        return $this->success($row->fresh(), 'Image uploaded');
    }

    /** Stream an item's image back (auth + tenant scoped — nothing is web-public). */
    public function image(Request $request, int $product)
    {
        $this->denyExternal($request);
        $row = $this->products->find($product, $request->user()->tenant_id);

        abort_unless($row->image_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($row->image_path), 404, 'No image.');

        return \Illuminate\Support\Facades\Storage::disk('local')->response($row->image_path);
    }

    public function deleteImage(Request $request, int $product)
    {
        $this->denyExternal($request);
        $row = $this->products->find($product, $request->user()->tenant_id);

        if ($row->image_path) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($row->image_path);
            $row->update(['image_path' => null]);
        }

        return $this->success($row->fresh(), 'Image removed');
    }

    /** §1 "Import items" / "Import opening stock" — .xlsx or .csv. */
    public function import(Request $request, string $kind)
    {
        $this->requireAdmin($request, 'import inventory data');

        abort_unless(in_array($kind, ['items', 'opening-stock'], true), 404, 'Unknown import.');
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        $svc = app(\App\Services\Inventory\ImportService::class);
        $file = $request->file('file');
        $tenantId = $request->user()->tenant_id;
        $userId = $request->user()->id;

        $result = $kind === 'items'
            ? $svc->importItems($file, $tenantId, $userId)
            : $svc->importOpeningStock($file, $tenantId, $userId);

        return $this->success($result, 'Import finished');
    }

    /** The column headers each importer understands, for the on-screen template. */
    public function importTemplate(Request $request, string $kind)
    {
        $this->denyExternal($request);
        abort_unless(in_array($kind, ['items', 'opening-stock'], true), 404, 'Unknown import.');

        return $this->success(
            $kind === 'items'
                ? \App\Services\Inventory\ImportService::ITEM_COLUMNS
                : \App\Services\Inventory\ImportService::OPENING_COLUMNS,
            'Template retrieved'
        );
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
        $this->guardProductManage($request, $product, 'edit this item');

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
