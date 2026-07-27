<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\TrainingProgramService;
use Illuminate\Http\Request;

/**
 * Learning & Development → Training Programs (Phase 2). Thin: validate, delegate,
 * return JSON. Reads open to HR users; writes require HR-queue management.
 * Tenant-scoped, audited.
 */
class TrainingProgramController extends Controller
{
    public function __construct(private TrainingProgramService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['status', 'search', 'category_id', 'training_type_id', 'provider_id', 'mode'])));
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

    public function updateStatus(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['is_active' => 'required|boolean']);

        return response()->json($this->service->setStatus($id, (bool) $data['is_active'], $this->tenant($request), $request->user()));
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'program_code'             => "$req|string|max:60",
            'program_name'             => "$req|string|max:160",
            'category_id'              => "$req|integer",
            'training_type_id'         => "$req|integer",
            'provider_id'              => "$req|integer",
            'department_id'            => 'nullable|integer',
            'designation_id'           => 'nullable|integer',
            'description'              => 'nullable|string',
            'objectives'               => 'nullable|string',
            'duration'                 => "$req|integer|min:1|max:100000",
            'duration_unit'            => 'nullable|in:Hours,Days,Weeks',
            'mode'                     => 'nullable|in:Online,Offline,Hybrid',
            'capacity'                 => "$req|integer|min:1|max:100000",
            'certification_applicable' => 'boolean',
            'passing_percentage'       => 'nullable|integer|min:0|max:100',
            'validity_days'            => 'nullable|integer|min:0|max:100000',
            'is_active'                => 'boolean',
        ]);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage training programs');
    }
}
