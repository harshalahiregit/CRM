<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreContractRequest;
use App\Http\Requests\Sales\UpdateContractRequest;
use App\Models\Sales\ContractType;
use App\Models\Sales\SalesContract;
use App\Services\Sales\ContractService;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    public function __construct(private ContractService $contractService)
    {
    }

    public function index(Request $request)
    {
        $contracts = $this->contractService->list($request->user()->tenant_id, $request->only([
            'status', 'client_id', 'type_id', 'search',
        ]));
        return response()->json($contracts);
    }

    public function expiring(Request $request)
    {
        $days = (int) $request->input('days', 30);
        return response()->json($this->contractService->expiringSoon($request->user()->tenant_id, $days));
    }

    public function store(StoreContractRequest $request)
    {
        $contract = $this->contractService->create($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($contract, 201);
    }

    public function show(SalesContract $contract, Request $request)
    {
        return response()->json($this->contractService->show($contract, $request->user()->tenant_id));
    }

    public function update(UpdateContractRequest $request, SalesContract $contract)
    {
        return response()->json($this->contractService->update($contract, $request->validated(), $request->user()->tenant_id));
    }

    public function destroy(SalesContract $contract, Request $request)
    {
        $this->contractService->delete($contract, $request->user()->tenant_id);
        return response()->json(['message' => 'Contract deleted']);
    }

    public function updateStatus(Request $request, SalesContract $contract)
    {
        $data = $request->validate(['status' => 'required|in:draft,active,expired,terminated,renewed']);
        return response()->json($this->contractService->updateStatus($contract, $data['status'], $request->user()->tenant_id, $request->user()->id));
    }

    public function renew(Request $request, SalesContract $contract)
    {
        $data = $request->validate([
            'value'      => 'nullable|numeric|min:0',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
        ]);
        return response()->json($this->contractService->renew($contract, $data, $request->user()->tenant_id, $request->user()->id), 201);
    }

    public function sign(Request $request, SalesContract $contract)
    {
        $data = $request->validate([
            'signature_data' => 'nullable|string',
            'signed_by_name' => 'nullable|string|max:255',
        ]);
        return response()->json($this->contractService->sign($contract, $data, $request->user()->tenant_id, $request->user()->id));
    }

    /* ── Contract Types ─────────────────────────────────────────── */
    public function types(Request $request)
    {
        return response()->json($this->contractService->types($request->user()->tenant_id));
    }

    public function storeType(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255']);
        return response()->json($this->contractService->createType($data['name'], $request->user()->tenant_id), 201);
    }

    public function destroyType(Request $request, ContractType $contractType)
    {
        $this->contractService->deleteType($contractType, $request->user()->tenant_id);
        return response()->json(['message' => 'Deleted']);
    }
}
