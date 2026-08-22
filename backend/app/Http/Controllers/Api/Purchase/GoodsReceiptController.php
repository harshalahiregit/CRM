<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\StoreGoodsReceiptRequest;
use App\Models\Purchase\GoodsReceipt;
use App\Models\Purchase\PurchaseOrder;
use App\Services\Purchase\GoodsReceiptService;
use Illuminate\Http\Request;

class GoodsReceiptController extends Controller
{
    public function __construct(private GoodsReceiptService $goodsReceiptService)
    {
    }

    /** GRNs for a given purchase order. */
    /**
     * The Goods Received register — every receipt for the tenant.
     *
     * Distinct from index(), which lists the receipts of one purchase order.
     * Receipts are RAISED from inside a PO, but they are LOOKED UP by GRN
     * number, delivery note or vendor, which is what this serves.
     */
    public function register(Request $request)
    {
        return response()->json(
            $this->goodsReceiptService->list(
                $request->user()->tenant_id,
                $request->only([
                    'status', 'purchase_vendor_id', 'purchase_order_id',
                    'from', 'to', 'has_rejections', 'search',
                ])
            )
        );
    }

    public function stats(Request $request)
    {
        return response()->json($this->goodsReceiptService->stats($request->user()->tenant_id));
    }

    public function index(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->assertOrderTenant($request, $purchaseOrder);

        return response()->json($this->goodsReceiptService->listForOrder($purchaseOrder));
    }

    public function store(StoreGoodsReceiptRequest $request, PurchaseOrder $purchaseOrder)
    {
        $this->assertOrderTenant($request, $purchaseOrder);

        $grn = $this->goodsReceiptService->create($purchaseOrder, $request->validated(), $request->user());

        return response()->json($grn, 201);
    }

    public function show(Request $request, GoodsReceipt $goodsReceipt)
    {
        $this->assertReceiptTenant($request, $goodsReceipt);

        return response()->json($goodsReceipt->load(['items', 'purchaseOrder:id,po_number', 'receiver:id,name', 'auditLogs']));
    }

    public function confirm(Request $request, GoodsReceipt $goodsReceipt)
    {
        $this->assertReceiptTenant($request, $goodsReceipt);

        return response()->json($this->goodsReceiptService->confirm($goodsReceipt, $request->user()));
    }

    public function cancel(Request $request, GoodsReceipt $goodsReceipt)
    {
        $this->assertReceiptTenant($request, $goodsReceipt);

        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json(
            $this->goodsReceiptService->cancel($goodsReceipt, $request->user(), $data['remarks'] ?? null)
        );
    }

    public function destroy(Request $request, GoodsReceipt $goodsReceipt)
    {
        $this->assertReceiptTenant($request, $goodsReceipt);

        $this->goodsReceiptService->destroy($goodsReceipt);

        return response()->json(['message' => 'Deleted']);
    }

    private function assertOrderTenant(Request $request, PurchaseOrder $purchaseOrder): void
    {
        abort_unless(
            (int) $purchaseOrder->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Purchase order not found'
        );
    }

    private function assertReceiptTenant(Request $request, GoodsReceipt $goodsReceipt): void
    {
        abort_unless(
            (int) $goodsReceipt->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Goods receipt not found'
        );
    }
}
