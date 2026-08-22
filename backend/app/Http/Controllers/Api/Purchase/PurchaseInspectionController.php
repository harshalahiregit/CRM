<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Concerns\AssertsTenantOwnership;
use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseInspection;
use App\Models\Purchase\PurchaseInspectionFinding;
use App\Services\Purchase\PurchaseInspectionService;
use App\Support\Purchase\PurchaseInspectionType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Purchase Inspections & Audits — mirror of the TPV register (parity). Tenant-scoped. */
class PurchaseInspectionController extends Controller
{
    use AssertsTenantOwnership;

    public function __construct(private PurchaseInspectionService $service) {}

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->list($request->user()->tenant_id, $request->only(['status', 'type', 'vendor_id'])),
            'types' => PurchaseInspectionType::ALL,
            'statuses' => PurchaseInspection::STATUSES,
            'finding_categories' => PurchaseInspectionFinding::CATEGORIES,
            'severities' => PurchaseInspectionFinding::SEVERITIES,
        ]);
    }

    public function store(Request $request)
    {
        return response()->json(
            $this->service->create($this->validateInspection($request), $request->user()->tenant_id, $request->user()->id),
            201
        );
    }

    public function show(Request $request, PurchaseInspection $inspection)
    {
        $this->assertTenant($request, $inspection);

        return response()->json($this->service->detail($inspection));
    }

    public function update(Request $request, PurchaseInspection $inspection)
    {
        $this->assertTenant($request, $inspection);

        return response()->json($this->service->update($inspection, $this->validateInspection($request, true)));
    }

    public function destroy(Request $request, PurchaseInspection $inspection)
    {
        $this->assertTenant($request, $inspection);
        $this->service->delete($inspection);

        return response()->json(['deleted' => true]);
    }

    /* ── Findings ───────────────────────────────────────────────────────── */

    public function addFinding(Request $request, PurchaseInspection $inspection)
    {
        $this->assertTenant($request, $inspection);

        return response()->json($this->service->addFinding($inspection, $this->validateFinding($request)), 201);
    }

    public function updateFinding(Request $request, PurchaseInspectionFinding $finding)
    {
        $this->assertTenant($request, $finding);

        return response()->json($this->service->updateFinding($finding, $this->validateFinding($request, true)));
    }

    public function destroyFinding(Request $request, PurchaseInspectionFinding $finding)
    {
        $this->assertTenant($request, $finding);
        $this->service->deleteFinding($finding);

        return response()->json(['deleted' => true]);
    }

    public function escalateFinding(Request $request, PurchaseInspectionFinding $finding)
    {
        $this->assertTenant($request, $finding);

        return response()->json($this->service->escalateToNcr($finding, $request->user()));
    }

    /* ── Validation ─────────────────────────────────────────────────────── */

    private function validateInspection(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'type' => [$req, Rule::in(PurchaseInspectionType::ALL)],
            'title' => "$req|string|max:200",
            'purchase_vendor_id' => 'nullable|integer|exists:purchase_vendors,id',
            'scheduled_date' => 'nullable|date',
            'conducted_date' => 'nullable|date',
            'location' => 'nullable|string|max:200',
            'status' => ['nullable', Rule::in(PurchaseInspection::STATUSES)],
            'score' => 'nullable|integer|min:0|max:100',
            'summary' => 'nullable|string',
        ]);
    }

    private function validateFinding(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'description' => "$req|string",
            'category' => ['nullable', Rule::in(PurchaseInspectionFinding::CATEGORIES)],
            'severity' => ['nullable', Rule::in(PurchaseInspectionFinding::SEVERITIES)],
            'status' => ['nullable', Rule::in(PurchaseInspectionFinding::STATUSES)],
            'corrective_action' => 'nullable|string',
            'due_date' => 'nullable|date',
            'responsible_by' => 'nullable|integer|exists:users,id',
        ]);
    }
}
