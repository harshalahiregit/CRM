<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvInspection;
use App\Models\Tpv\TpvInspectionFinding;
use App\Services\Tpv\TpvInspectionService;
use App\Support\Tpv\InspectionType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** TPV Inspections & Audits (Sangoe TPV §22). Tenant-scoped. */
class TpvInspectionController extends Controller
{
    public function __construct(private TpvInspectionService $service) {}

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->list($request->user()->tenant_id, $request->only(['status', 'type', 'vendor_id'])),
            'types' => InspectionType::ALL,
            'statuses' => TpvInspection::STATUSES,
            'finding_categories' => TpvInspectionFinding::CATEGORIES,
            'severities' => TpvInspectionFinding::SEVERITIES,
        ]);
    }

    public function store(Request $request)
    {
        return response()->json(
            $this->service->create($this->validateInspection($request), $request->user()->tenant_id, $request->user()->id),
            201
        );
    }

    public function show(Request $request, TpvInspection $inspection)
    {
        $this->assertTenant($request, $inspection);

        return response()->json($this->service->detail($inspection));
    }

    public function update(Request $request, TpvInspection $inspection)
    {
        $this->assertTenant($request, $inspection);

        return response()->json($this->service->update($inspection, $this->validateInspection($request, true)));
    }

    public function destroy(Request $request, TpvInspection $inspection)
    {
        $this->assertTenant($request, $inspection);
        $this->service->delete($inspection);

        return response()->json(['deleted' => true]);
    }

    /* ── Findings ───────────────────────────────────────────────────────── */

    public function addFinding(Request $request, TpvInspection $inspection)
    {
        $this->assertTenant($request, $inspection);

        return response()->json($this->service->addFinding($inspection, $this->validateFinding($request)), 201);
    }

    public function updateFinding(Request $request, TpvInspectionFinding $finding)
    {
        $this->assertTenant($request, $finding);

        return response()->json($this->service->updateFinding($finding, $this->validateFinding($request, true)));
    }

    public function destroyFinding(Request $request, TpvInspectionFinding $finding)
    {
        $this->assertTenant($request, $finding);
        $this->service->deleteFinding($finding);

        return response()->json(['deleted' => true]);
    }

    public function escalateFinding(Request $request, TpvInspectionFinding $finding)
    {
        $this->assertTenant($request, $finding);

        return response()->json($this->service->escalateToNcr($finding, $request->user()));
    }

    /* ── Validation ─────────────────────────────────────────────────────── */

    private function validateInspection(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'type' => [$req, Rule::in(InspectionType::ALL)],
            'title' => "$req|string|max:200",
            'vendor_id' => 'nullable|integer|exists:vendors,id',
            'project_id' => 'nullable|integer',
            'work_package_id' => 'nullable|integer|exists:tpv_work_packages,id',
            'scheduled_date' => 'nullable|date',
            'conducted_date' => 'nullable|date',
            'location' => 'nullable|string|max:200',
            'status' => ['nullable', Rule::in(TpvInspection::STATUSES)],
            'score' => 'nullable|integer|min:0|max:100',
            'summary' => 'nullable|string',
        ]);
    }

    private function validateFinding(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'description' => "$req|string",
            'category' => ['nullable', Rule::in(TpvInspectionFinding::CATEGORIES)],
            'severity' => ['nullable', Rule::in(TpvInspectionFinding::SEVERITIES)],
            'status' => ['nullable', Rule::in(TpvInspectionFinding::STATUSES)],
            'corrective_action' => 'nullable|string',
            'due_date' => 'nullable|date',
            'responsible_by' => 'nullable|integer|exists:users,id',
        ]);
    }
}
