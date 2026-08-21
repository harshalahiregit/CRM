<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvContract;
use App\Models\Tpv\TpvWorkOrder;
use App\Services\Tpv\TpvContractService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** TPV Contracts & Work Orders (Sangoe TPV §8). Tenant-scoped; CRUD in the service. */
class TpvContractController extends Controller
{
    public function __construct(private TpvContractService $service) {}

    /* ── Contracts ──────────────────────────────────────────────────────── */

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->listContracts(
                $request->user()->tenant_id,
                $request->only(['vendor_id', 'status'])
            ),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateContract($request);

        return response()->json(
            $this->service->createContract($data, $request->user()->tenant_id, $request->user()->id),
            201
        );
    }

    public function show(Request $request, TpvContract $contract)
    {
        $this->assertTenant($request, $contract);

        return response()->json($this->service->contractDetail($contract));
    }

    public function update(Request $request, TpvContract $contract)
    {
        $this->assertTenant($request, $contract);
        $data = $this->validateContract($request, true);

        return response()->json($this->service->updateContract($contract, $data));
    }

    public function destroy(Request $request, TpvContract $contract)
    {
        $this->assertTenant($request, $contract);
        $this->service->deleteContract($contract);

        return response()->json(['deleted' => true]);
    }

    /* ── Work Orders ────────────────────────────────────────────────────── */

    public function workOrders(Request $request)
    {
        return response()->json([
            'data' => $this->service->listWorkOrders(
                $request->user()->tenant_id,
                $request->only(['vendor_id', 'contract_id', 'status'])
            ),
        ]);
    }

    public function storeWorkOrder(Request $request)
    {
        $data = $this->validateWorkOrder($request);

        return response()->json(
            $this->service->createWorkOrder($data, $request->user()->tenant_id, $request->user()->id),
            201
        );
    }

    public function updateWorkOrder(Request $request, TpvWorkOrder $workOrder)
    {
        $this->assertTenant($request, $workOrder);
        $data = $this->validateWorkOrder($request, true);

        return response()->json($this->service->updateWorkOrder($workOrder, $data));
    }

    public function destroyWorkOrder(Request $request, TpvWorkOrder $workOrder)
    {
        $this->assertTenant($request, $workOrder);
        $this->service->deleteWorkOrder($workOrder);

        return response()->json(['deleted' => true]);
    }

    /* ── Validation ─────────────────────────────────────────────────────── */

    private function validateContract(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'vendor_id' => "$req|integer|exists:vendors,id",
            'title' => "$req|string|max:200",
            'project_id' => 'nullable|integer',
            'contract_type' => 'nullable|string|max:100',
            'scope' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'contract_value' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:8',
            'payment_terms' => 'nullable|string',
            'sla' => 'nullable|string',
            'kpi' => 'nullable|string',
            'penalties' => 'nullable|string',
            'insurance_requirements' => 'nullable|string',
            'hse_clauses' => 'nullable|string',
            'compliance_clauses' => 'nullable|string',
            'renewal_terms' => 'nullable|string',
            'status' => ['nullable', Rule::in(TpvContract::STATUSES)],
            'notes' => 'nullable|string',
        ]);
    }

    private function validateWorkOrder(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'vendor_id' => "$req|integer|exists:vendors,id",
            'title' => "$req|string|max:200",
            'contract_id' => 'nullable|integer|exists:tpv_contracts,id',
            'project_id' => 'nullable|integer',
            'work_package' => 'nullable|string|max:150',
            'scope' => 'nullable|string',
            'location' => 'nullable|string|max:200',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'quantity' => 'nullable|string|max:100',
            'manpower_requirement' => 'nullable|integer|min:0',
            'equipment_requirement' => 'nullable|string',
            'commercial_terms' => 'nullable|string',
            'status' => ['nullable', Rule::in(TpvWorkOrder::STATUSES)],
            'notes' => 'nullable|string',
        ]);
    }
}
