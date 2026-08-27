<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\PermitJsaStep;
use App\Models\Tpv\WorkPermit;
use App\Services\Tpv\PermitService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Permit-to-Work + JSA (Doc_4 Phase 5). Tenant-scoped; governance in the service. */
class PermitController extends Controller
{
    public function __construct(private PermitService $service)
    {
    }

    public function index(Request $request)
    {
        $rows = WorkPermit::where('tenant_id', $request->user()->tenant_id)
            ->with(['vendor:id,company_name,vendor_code', 'jsaSteps'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->latest('id')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'       => 'required|string|max:200',
            'type'        => ['required', Rule::in(WorkPermit::acceptedTypes())],
            'vendor_id'   => 'nullable|integer',
            'location'    => 'nullable|string|max:200',
            'description' => 'nullable|string',
            'hazards'     => 'nullable|string',
            'precautions' => 'nullable|string',
            'valid_from'  => 'nullable|date',
            'valid_to'    => 'nullable|date|after_or_equal:valid_from',
        ]);

        return response()->json($this->service->create($request->user()->tenant_id, $data, $request->user()), 201);
    }

    public function show(Request $request, WorkPermit $permit)
    {
        $this->assertTenant($request, $permit);

        return response()->json($permit->load(['vendor:id,company_name,vendor_code', 'requester:id,name', 'jsaSteps']));
    }

    public function addStep(Request $request, WorkPermit $permit)
    {
        $this->assertTenant($request, $permit);
        $data = $request->validate([
            'activity'      => 'required|string|max:255',
            'hazard'        => 'nullable|string',
            'control'       => 'nullable|string',
            'residual_risk' => ['sometimes', Rule::in(PermitJsaStep::RISKS)],
        ]);

        return response()->json($this->service->addJsaStep($permit, $data, $request->user()), 201);
    }

    public function approve(Request $request, WorkPermit $permit)
    {
        $this->assertTenant($request, $permit);
        $data = $request->validate(['remarks' => 'nullable|string|max:500']);

        return response()->json($this->service->approve($permit, $request->user(), $data['remarks'] ?? null));
    }

    public function reject(Request $request, WorkPermit $permit)
    {
        $this->assertTenant($request, $permit);
        $data = $request->validate(['remarks' => 'required|string|max:500']);

        return response()->json($this->service->reject($permit, $request->user(), $data['remarks']));
    }

    public function activate(Request $request, WorkPermit $permit)
    {
        $this->assertTenant($request, $permit);

        return response()->json($this->service->activate($permit, $request->user()));
    }

    public function close(Request $request, WorkPermit $permit)
    {
        $this->assertTenant($request, $permit);

        return response()->json($this->service->close($permit, $request->user()));
    }

    private function assertTenant(Request $request, WorkPermit $permit): void
    {
        abort_unless((int) $permit->tenant_id === (int) $request->user()->tenant_id, 404);
    }
}
