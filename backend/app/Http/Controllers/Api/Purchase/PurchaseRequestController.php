<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\StorePurchaseRequestRequest;
use App\Http\Requests\Purchase\UpdatePurchaseRequestRequest;
use App\Models\Purchase\PurchaseRequest;
use App\Services\Purchase\PurchaseRequestService;
use Illuminate\Http\Request;

class PurchaseRequestController extends Controller
{
    public function __construct(private PurchaseRequestService $purchaseRequestService)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->purchaseRequestService->list(
                $request->user()->tenant_id,
                $request->only(['status', 'department', 'priority', 'vendor_id', 'required_by', 'search'])
            )
        );
    }

    public function store(StorePurchaseRequestRequest $request)
    {
        $pr = $this->purchaseRequestService->create($request->validated(), $request->user());

        return response()->json($pr, 201);
    }

    public function show(Request $request, PurchaseRequest $purchaseRequest)
    {
        $this->assertTenant($request, $purchaseRequest);

        // auditLogs powers the detail timeline (serialized as `audit_logs`).
        return response()->json($purchaseRequest->load(['items', 'vendor', 'requester:id,name', 'approver:id,name', 'auditLogs']));
    }

    public function update(Request $request, PurchaseRequest $purchaseRequest, UpdatePurchaseRequestRequest $updateRequest)
    {
        $this->assertTenant($request, $purchaseRequest);

        return response()->json(
            $this->purchaseRequestService->update($purchaseRequest, $updateRequest->validated(), $request->user())
        );
    }

    public function submit(Request $request, PurchaseRequest $purchaseRequest)
    {
        $this->assertTenant($request, $purchaseRequest);

        return response()->json($this->purchaseRequestService->submit($purchaseRequest, $request->user()));
    }

    public function approve(Request $request, PurchaseRequest $purchaseRequest)
    {
        $this->assertTenant($request, $purchaseRequest);

        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json(
            $this->purchaseRequestService->approve($purchaseRequest, $request->user(), $data['remarks'] ?? null)
        );
    }

    public function reject(Request $request, PurchaseRequest $purchaseRequest)
    {
        $this->assertTenant($request, $purchaseRequest);

        $data = $request->validate(['remarks' => 'required|string']);

        return response()->json(
            $this->purchaseRequestService->reject($purchaseRequest, $request->user(), $data['remarks'])
        );
    }

    public function destroy(Request $request, PurchaseRequest $purchaseRequest)
    {
        $this->assertTenant($request, $purchaseRequest);

        $this->purchaseRequestService->destroy($purchaseRequest);

        return response()->json(['message' => 'Deleted']);
    }

    public function stats(Request $request)
    {
        return response()->json($this->purchaseRequestService->stats($request->user()->tenant_id));
    }

    private function assertTenant(Request $request, PurchaseRequest $purchaseRequest): void
    {
        abort_unless(
            (int) $purchaseRequest->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Purchase request not found'
        );
    }
}
