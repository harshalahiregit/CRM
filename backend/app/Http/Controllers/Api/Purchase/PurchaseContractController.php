<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\StorePurchaseContractRequest;
use App\Http\Requests\Purchase\UpdatePurchaseContractRequest;
use App\Models\Purchase\PurchaseContract;
use App\Services\Purchase\PurchaseContractService;
use Illuminate\Http\Request;

class PurchaseContractController extends Controller
{
    public function __construct(private PurchaseContractService $contractService)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->contractService->list($request->user()->tenant_id, $request->only(['status', 'type', 'vendor_id', 'search']))
        );
    }

    public function stats(Request $request)
    {
        return response()->json($this->contractService->stats($request->user()->tenant_id));
    }

    /** Active rate contracts for a vendor — for a PO's contract picker. */
    public function referenceable(Request $request)
    {
        $data = $request->validate(['vendor_id' => 'required|integer']);

        return response()->json($this->contractService->referenceableForVendor($request->user()->tenant_id, (int) $data['vendor_id']));
    }

    public function store(StorePurchaseContractRequest $request)
    {
        return response()->json($this->contractService->create($request->validated(), $request->user()), 201);
    }

    public function show(Request $request, PurchaseContract $contract)
    {
        $this->assertTenant($request, $contract);

        return response()->json($contract->load(['items', 'vendor', 'creator:id,name', 'auditLogs']));
    }

    public function update(UpdatePurchaseContractRequest $request, PurchaseContract $contract)
    {
        $this->assertTenant($request, $contract);

        return response()->json($this->contractService->update($contract, $request->validated(), $request->user()));
    }

    public function submit(Request $request, PurchaseContract $contract)
    {
        $this->assertTenant($request, $contract);

        return response()->json($this->contractService->submit($contract, $request->user()));
    }

    public function returnToDraft(Request $request, PurchaseContract $contract)
    {
        $this->assertTenant($request, $contract);

        return response()->json($this->contractService->returnToDraft($contract, $request->user()));
    }

    public function uploadDocument(Request $request, PurchaseContract $contract)
    {
        $this->assertTenant($request, $contract);
        $request->validate(['document' => 'required|file|mimes:pdf,doc,docx|max:10240']);

        return response()->json($this->contractService->uploadDocument($contract, $request->file('document'), $request->user()));
    }

    public function download(Request $request, PurchaseContract $contract)
    {
        $this->assertTenant($request, $contract);
        $f = $this->contractService->resolveDownload($contract);

        return response()->download($f['path'], $f['filename'], ['Content-Type' => $f['mime']]);
    }

    public function destroy(Request $request, PurchaseContract $contract)
    {
        $this->assertTenant($request, $contract);
        $this->contractService->delete($contract);

        return response()->json(['message' => 'Deleted']);
    }

    /* ── Admin authority (route-gated) ── */

    public function activate(Request $request, PurchaseContract $contract)
    {
        $this->assertTenant($request, $contract);

        return response()->json($this->contractService->activate($contract, $request->user()));
    }

    public function terminate(Request $request, PurchaseContract $contract)
    {
        $this->assertTenant($request, $contract);
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        return response()->json($this->contractService->terminate($contract, $request->user(), $data['reason'] ?? null));
    }

    private function assertTenant(Request $request, PurchaseContract $contract): void
    {
        abort_unless(
            (int) $contract->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Contract not found'
        );
    }
}
