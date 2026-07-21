<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\StoreCatalogItemRequest;
use App\Http\Requests\Purchase\UpdateCatalogItemRequest;
use App\Models\Purchase\PurchaseCatalogItem;
use App\Services\Purchase\PurchaseCatalogService;
use App\Support\Purchase\PurchaseCatalogStatus;
use Illuminate\Http\Request;

class PurchaseCatalogController extends Controller
{
    public function __construct(private PurchaseCatalogService $catalogService)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->catalogService->list($request->user()->tenant_id, $request->only(['status', 'category', 'search']))
        );
    }

    public function stats(Request $request)
    {
        return response()->json($this->catalogService->stats($request->user()->tenant_id));
    }

    /** Active items only — feeds the pick-lists in PR/RFQ/PO line entry. */
    public function search(Request $request)
    {
        return response()->json(
            $this->catalogService->activeSearch($request->user()->tenant_id, $request->query('q'))
        );
    }

    public function store(StoreCatalogItemRequest $request)
    {
        return response()->json($this->catalogService->create($request->validated(), $request->user()), 201);
    }

    public function show(Request $request, PurchaseCatalogItem $catalogItem)
    {
        $this->assertTenant($request, $catalogItem);

        return response()->json($catalogItem->load(['preferredVendor:id,vendor_code,company_name', 'creator:id,name', 'auditLogs']));
    }

    public function update(UpdateCatalogItemRequest $request, PurchaseCatalogItem $catalogItem)
    {
        $this->assertTenant($request, $catalogItem);

        return response()->json($this->catalogService->update($catalogItem, $request->validated(), $request->user()));
    }

    /** Activate / discontinue / return-to-draft. */
    public function setStatus(Request $request, PurchaseCatalogItem $catalogItem)
    {
        $this->assertTenant($request, $catalogItem);
        $data = $request->validate(['status' => 'required|string|in:'.implode(',', PurchaseCatalogStatus::ALL)]);

        return response()->json($this->catalogService->setStatus($catalogItem, $data['status'], $request->user()));
    }

    public function destroy(Request $request, PurchaseCatalogItem $catalogItem)
    {
        $this->assertTenant($request, $catalogItem);
        $this->catalogService->delete($catalogItem, $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    private function assertTenant(Request $request, PurchaseCatalogItem $catalogItem): void
    {
        abort_unless(
            (int) $catalogItem->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Catalog item not found'
        );
    }
}
