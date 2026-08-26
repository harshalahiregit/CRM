<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvActivity;
use App\Models\Tpv\TpvWorkPackage;
use App\Services\Tpv\TpvWorkPackageService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** TPV Work Packages & Activities (Sangoe TPV §13). Tenant-scoped; CRUD in the service. */
class TpvWorkPackageController extends Controller
{
    public function __construct(private TpvWorkPackageService $service) {}

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->list($request->user()->tenant_id, $request->only(['vendor_id', 'status'])),
        ]);
    }

    public function store(Request $request)
    {
        return response()->json(
            $this->service->create($this->validatePackage($request), $request->user()->tenant_id, $request->user()->id),
            201
        );
    }

    public function show(Request $request, TpvWorkPackage $workPackage)
    {
        $this->assertTenant($request, $workPackage);

        return response()->json($this->service->detail($workPackage));
    }

    public function update(Request $request, TpvWorkPackage $workPackage)
    {
        $this->assertTenant($request, $workPackage);

        return response()->json($this->service->update($workPackage, $this->validatePackage($request, true)));
    }

    public function destroy(Request $request, TpvWorkPackage $workPackage)
    {
        $this->assertTenant($request, $workPackage);
        $this->service->delete($workPackage);

        return response()->json(['deleted' => true]);
    }

    /* ── Activities ─────────────────────────────────────────────────────── */

    public function addActivity(Request $request, TpvWorkPackage $workPackage)
    {
        $this->assertTenant($request, $workPackage);

        return response()->json($this->service->addActivity($workPackage, $this->validateActivity($request)), 201);
    }

    public function updateActivity(Request $request, TpvActivity $activity)
    {
        $this->assertTenant($request, $activity);

        return response()->json($this->service->updateActivity($activity, $this->validateActivity($request, true)));
    }

    public function destroyActivity(Request $request, TpvActivity $activity)
    {
        $this->assertTenant($request, $activity);
        $this->service->deleteActivity($activity);

        return response()->json(['deleted' => true]);
    }

    /* ── Validation ─────────────────────────────────────────────────────── */

    private function validatePackage(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'vendor_id' => "$req|integer|exists:vendors,id",
            'name' => "$req|string|max:200",
            'project_id' => 'nullable|integer',
            'contract_id' => 'nullable|integer|exists:tpv_contracts,id',
            'description' => 'nullable|string',
            'scope' => 'nullable|string',
            'location' => 'nullable|string|max:200',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => ['nullable', Rule::in(TpvWorkPackage::STATUSES)],
            'notes' => 'nullable|string',
        ]);
    }

    private function validateActivity(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'name' => "$req|string|max:200",
            'description' => 'nullable|string',
            'hazard' => 'nullable|string|max:160',
            'required_competency' => 'nullable|string|max:150',
            'requires_permit' => 'nullable|boolean',
            'permit_type' => ['nullable', Rule::in(\App\Models\Tpv\WorkPermit::TYPES)],
            'status' => ['nullable', Rule::in(TpvActivity::STATUSES)],
            'sort_order' => 'nullable|integer|min:0',
        ]);
    }
}
