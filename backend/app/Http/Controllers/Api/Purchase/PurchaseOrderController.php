<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\StorePurchaseOrderRequest;
use App\Http\Requests\Purchase\UpdatePurchaseOrderRequest;
use App\Models\Purchase\PurchaseOrder;
use App\Models\Purchase\PurchaseRequest;
use App\Services\Purchase\PurchaseOrderService;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function __construct(private PurchaseOrderService $purchaseOrderService)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->purchaseOrderService->list(
                $request->user()->tenant_id,
                $request->only(['status', 'department', 'purchase_vendor_id', 'expected_by', 'search'])
            )
        );
    }

    public function store(StorePurchaseOrderRequest $request)
    {
        $po = $this->purchaseOrderService->create($request->validated(), $request->user());

        return response()->json($po, 201);
    }

    /** Convert an approved Purchase Request into a draft PO. */
    public function fromRequest(Request $request, PurchaseRequest $purchaseRequest)
    {
        abort_unless(
            (int) $purchaseRequest->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Purchase request not found'
        );

        $po = $this->purchaseOrderService->createFromRequest($purchaseRequest, $request->user());

        return response()->json($po, 201);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->assertTenant($request, $purchaseOrder);

        return response()->json($purchaseOrder->load([
            'items', 'vendor', 'creator:id,name', 'issuer:id,name',
            'purchaseRequest:id,pr_number',
            'contract:id,contract_number,title,spend_ceiling,consumed_amount,status,end_date',
            'goodsReceipts.items', 'goodsReceipts.receiver:id,name', 'auditLogs',
        ]));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder, UpdatePurchaseOrderRequest $updateRequest)
    {
        $this->assertTenant($request, $purchaseOrder);

        return response()->json(
            $this->purchaseOrderService->update($purchaseOrder, $updateRequest->validated(), $request->user())
        );
    }

    public function issue(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->assertTenant($request, $purchaseOrder);

        return response()->json($this->purchaseOrderService->issue($purchaseOrder, $request->user()));
    }

    public function close(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->assertTenant($request, $purchaseOrder);

        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json(
            $this->purchaseOrderService->close($purchaseOrder, $request->user(), $data['remarks'] ?? null)
        );
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->assertTenant($request, $purchaseOrder);

        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json(
            $this->purchaseOrderService->cancel($purchaseOrder, $request->user(), $data['remarks'] ?? null)
        );
    }

    public function destroy(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->assertTenant($request, $purchaseOrder);

        $this->purchaseOrderService->destroy($purchaseOrder);

        return response()->json(['message' => 'Deleted']);
    }

    public function stats(Request $request)
    {
        return response()->json($this->purchaseOrderService->stats($request->user()->tenant_id));
    }

    private function assertTenant(Request $request, PurchaseOrder $purchaseOrder): void
    {
        abort_unless(
            (int) $purchaseOrder->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Purchase order not found'
        );
    }
}
