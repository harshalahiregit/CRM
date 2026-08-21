<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerCompetency;
use App\Models\Tpv\TpvWorkerTraining;
use App\Models\Tpv\TpvWorkPackage;
use App\Services\Tpv\TpvCompetencyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** TPV Competency & Training + Skill Matrix (Sangoe TPV §15). Tenant-scoped. */
class TpvCompetencyController extends Controller
{
    public function __construct(private TpvCompetencyService $service) {}

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->roster($request->user()->tenant_id, $request->only(['vendor_id', 'status'])),
            'categories' => TpvWorkerCompetency::CATEGORIES,
            'training_types' => TpvWorkerTraining::TYPES,
        ]);
    }

    public function worker(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json($this->service->workerDetail($worker));
    }

    /* ── Competencies ───────────────────────────────────────────────────── */

    public function addCompetency(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json($this->service->addCompetency($worker, $this->validateCompetency($request)), 201);
    }

    public function updateCompetency(Request $request, TpvWorkerCompetency $competency)
    {
        $this->assertTenant($request, $competency);

        return response()->json($this->service->updateCompetency($competency, $this->validateCompetency($request, true)));
    }

    public function destroyCompetency(Request $request, TpvWorkerCompetency $competency)
    {
        $this->assertTenant($request, $competency);
        $this->service->deleteCompetency($competency);

        return response()->json(['deleted' => true]);
    }

    /* ── Trainings ──────────────────────────────────────────────────────── */

    public function addTraining(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json($this->service->addTraining($worker, $this->validateTraining($request)), 201);
    }

    public function updateTraining(Request $request, TpvWorkerTraining $training)
    {
        $this->assertTenant($request, $training);

        return response()->json($this->service->updateTraining($training, $this->validateTraining($request, true)));
    }

    public function destroyTraining(Request $request, TpvWorkerTraining $training)
    {
        $this->assertTenant($request, $training);
        $this->service->deleteTraining($training);

        return response()->json(['deleted' => true]);
    }

    /* ── Skill matrix ───────────────────────────────────────────────────── */

    public function skillMatrix(Request $request, TpvWorkPackage $workPackage)
    {
        $this->assertTenant($request, $workPackage);

        return response()->json($this->service->skillMatrix($request->user()->tenant_id, $workPackage->id));
    }

    /* ── Validation ─────────────────────────────────────────────────────── */

    private function validateCompetency(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'name' => "$req|string|max:150",
            'category' => ['nullable', Rule::in(TpvWorkerCompetency::CATEGORIES)],
            'authority' => 'nullable|string|max:150',
            'reference_no' => 'nullable|string|max:100',
            'skill_level' => 'nullable|string|max:50',
            'issued_date' => 'nullable|date',
            'valid_until' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);
    }

    private function validateTraining(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'training_type' => [$req, Rule::in(TpvWorkerTraining::TYPES)],
            'provider' => 'nullable|string|max:150',
            'completed_date' => 'nullable|date',
            'valid_until' => 'nullable|date',
            'passed' => 'nullable|boolean',
            'score' => 'nullable|integer|min:0|max:100',
            'notes' => 'nullable|string',
        ]);
    }
}
