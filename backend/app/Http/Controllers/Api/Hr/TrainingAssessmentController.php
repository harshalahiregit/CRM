<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\TrainingAssessmentService;
use Illuminate\Http\Request;

/**
 * L&D → Training Assessment (Phase 5). Thin: validate, delegate, return JSON.
 * Writes require HR-queue management. Tenant-scoped, audited.
 */
class TrainingAssessmentController extends Controller
{
    public function __construct(private TrainingAssessmentService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['employee_training_id', 'employee_id', 'result'])));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request)));
    }

    public function store(Request $request)
    {
        $this->can($request);

        return response()->json($this->service->create($this->validated($request), $this->tenant($request), $request->user()), 201);
    }

    public function update(Request $request, int $id)
    {
        $this->can($request);

        return response()->json($this->service->update($id, $this->validated($request, true), $this->tenant($request), $request->user()));
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'employee_training_id' => "$req|integer",
            'assessment_name'      => 'nullable|string|max:150',
            'total_marks'          => "$req|numeric|min:0.01",
            'obtained_marks'       => "$req|numeric|min:0",
            'passing_marks'        => 'nullable|numeric|min:0',
            'remarks'              => 'nullable|string',
        ]);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage training assessments');
    }
}
