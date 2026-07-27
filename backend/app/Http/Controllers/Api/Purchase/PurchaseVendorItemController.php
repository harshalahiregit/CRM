<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\StorePurchaseVendorItemRequest;
use App\Http\Requests\Purchase\UpdatePurchaseVendorItemRequest;
use App\Models\Purchase\PurchaseVendorItem;
use App\Services\Purchase\PurchaseVendorItemService;
use Illuminate\Http\Request;

/**
 * Purchase Vendor Items — CRUD over the Purchase-owned mapping between a
 * PurchaseVendor and an Inventory product. Item master data is only ever READ
 * (eager-loaded from inventory_products); this controller never writes to
 * Inventory. Every bound mapping is tenant-guarded (404).
 */
class PurchaseVendorItemController extends Controller
{
    public function __construct(private PurchaseVendorItemService $service)
    {
    }

    public function stats(Request $request)
    {
        return response()->json($this->service->stats($request->user()->tenant_id));
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->service->list(
                $request->user()->tenant_id,
                $request->only(['purchase_vendor_id', 'inventory_product_id', 'group_id', 'status', 'search', 'per_page'])
            )
        );
    }

    public function store(StorePurchaseVendorItemRequest $request)
    {
        return response()->json($this->service->create($request->validated(), $request->user()), 201);
    }

    public function show(Request $request, PurchaseVendorItem $vendorItem)
    {
        $this->assertTenant($request, $vendorItem);

        return response()->json($this->service->find($vendorItem->id, $request->user()->tenant_id));
    }

    public function update(UpdatePurchaseVendorItemRequest $request, PurchaseVendorItem $vendorItem)
    {
        $this->assertTenant($request, $vendorItem);

        return response()->json($this->service->update($vendorItem, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, PurchaseVendorItem $vendorItem)
    {
        $this->assertTenant($request, $vendorItem);

        $this->service->delete($vendorItem, $request->user());

        return response()->json(['message' => 'Vendor item mapping deleted']);
    }

    private function assertTenant(Request $request, PurchaseVendorItem $vendorItem): void
    {
        abort_unless((int) $vendorItem->tenant_id === (int) $request->user()->tenant_id, 404, 'Vendor item mapping not found');
    }
}
