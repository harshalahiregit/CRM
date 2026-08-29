<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerCompetency;
use App\Services\Purchase\PurchaseCompetencyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Purchase Competency + Skill Matrix (mirror of TPV §15). Tenant-scoped: an admin
 * legitimately sees every vendor's workers; the vendor filter only narrows.
 *
 * Trainings are NOT here — Purchase keeps training normalised in its own
 * workforce tab (purchase_worker_trainings); this controller is competency-only.
 */
class PurchaseCompetencyController extends Controller
{
    public function __construct(private PurchaseCompetencyService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->roster($request->user()->tenant_id, $request->only(['vendor_id', 'status'])),
            'categories' => PurchaseWorkerCompetency::CATEGORIES,
            'required_competencies' => $this->service->requiredCompetencies($request->user()->tenant_id),
        ]);
    }

    public function worker(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json($this->service->workerDetail($worker));
    }

    /* ── Competencies ───────────────────────────────────────────────────── */

    public function addCompetency(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json($this->service->addCompetency($worker, $this->validateCompetency($request)), 201);
    }

    public function updateCompetency(Request $request, PurchaseWorkerCompetency $competency)
    {
        $this->assertTenant($request, $competency);

        return response()->json($this->service->updateCompetency($competency, $this->validateCompetency($request, true)));
    }

    public function destroyCompetency(Request $request, PurchaseWorkerCompetency $competency)
    {
        $this->assertTenant($request, $competency);
        $this->service->deleteCompetency($competency);

        return response()->json(['deleted' => true]);
    }

    /* ── Skill matrix (per vendor — Purchase has no work packages) ───────── */

    public function skillMatrix(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json($this->service->skillMatrix($request->user()->tenant_id, $purchaseVendor->id));
    }

    /* ── Validation ─────────────────────────────────────────────────────── */

    private function validateCompetency(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'name' => "$req|string|max:150",
            'category' => ['nullable', Rule::in(PurchaseWorkerCompetency::CATEGORIES)],
            'authority' => 'nullable|string|max:150',
            'reference_no' => 'nullable|string|max:100',
            'skill_level' => 'nullable|string|max:50',
            'experience_years' => 'nullable|numeric|min:0|max:80',
            'issued_date' => 'nullable|date',
            'valid_until' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);
    }

    /** 404, not 403 — the same existence-hiding the rest of Purchase uses. */
    private function assertTenant(Request $request, $model): void
    {
        abort_unless(
            (int) $model->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Not found'
        );
    }
}
