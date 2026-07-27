<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\StorePurchaseOrderReturnRequest;
use App\Http\Requests\Purchase\UpdatePurchaseOrderReturnRequest;
use App\Models\Purchase\PurchaseOrderReturn;
use App\Services\Purchase\PurchaseOrderReturnService;
use Illuminate\Http\Request;

/**
 * Purchase Order Returns — goods returned to a Purchase Vendor. Purchase-owned
 * (purchase_vendor_id); independent of purchase_debit_notes and of TPV/shared
 * Vendor. Every bound return is tenant-guarded (404).
 */
class PurchaseOrderReturnController extends Controller
{
    public function __construct(private PurchaseOrderReturnService $service)
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
                $request->only(['purchase_vendor_id', 'status', 'from_date', 'to_date', 'search', 'per_page'])
            )
        );
    }

    public function store(StorePurchaseOrderReturnRequest $request)
    {
        return response()->json($this->service->create($request->validated(), $request->user()), 201);
    }

    public function show(Request $request, PurchaseOrderReturn $orderReturn)
    {
        $this->assertTenant($request, $orderReturn);

        return response()->json($this->service->find($orderReturn->id, $request->user()->tenant_id));
    }

    public function update(UpdatePurchaseOrderReturnRequest $request, PurchaseOrderReturn $orderReturn)
    {
        $this->assertTenant($request, $orderReturn);

        return response()->json($this->service->update($orderReturn, $request->validated(), $request->user()));
    }

    public function issue(Request $request, PurchaseOrderReturn $orderReturn)
    {
        $this->assertTenant($request, $orderReturn);

        return response()->json($this->service->issue($orderReturn, $request->user()));
    }

    public function complete(Request $request, PurchaseOrderReturn $orderReturn)
    {
        $this->assertTenant($request, $orderReturn);

        return response()->json($this->service->complete($orderReturn, $request->user()));
    }

    public function cancel(Request $request, PurchaseOrderReturn $orderReturn)
    {
        $this->assertTenant($request, $orderReturn);
        $data = $request->validate(['remarks' => 'nullable|string|max:2000']);

        return response()->json($this->service->cancel($orderReturn, $request->user(), $data['remarks'] ?? null));
    }

    public function destroy(Request $request, PurchaseOrderReturn $orderReturn)
    {
        $this->assertTenant($request, $orderReturn);
        $this->service->delete($orderReturn, $request->user());

        return response()->json(['message' => 'Order return deleted']);
    }

    private function assertTenant(Request $request, PurchaseOrderReturn $orderReturn): void
    {
        abort_unless((int) $orderReturn->tenant_id === (int) $request->user()->tenant_id, 404, 'Order return not found');
    }
}
