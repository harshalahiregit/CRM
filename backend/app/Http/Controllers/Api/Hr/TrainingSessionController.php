<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\TrainingSessionService;
use Illuminate\Http\Request;

/**
 * Learning & Development → Training Sessions & Calendar (Phase 3). Thin: validate,
 * delegate, return JSON. Reads open to HR users; writes require HR-queue
 * management. Tenant-scoped, audited.
 */
class TrainingSessionController extends Controller
{
    public function __construct(private TrainingSessionService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['status', 'training_program_id', 'provider_id', 'department_id', 'mode', 'from', 'to', 'search'])));
    }

    public function calendar(Request $request)
    {
        return response()->json($this->service->calendar($this->tenant($request), $request->only(['year', 'month'])));
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
        $data = $request->validate(['status' => 'required|in:Scheduled,Ongoing,Completed,Cancelled']);

        return response()->json($this->service->setStatus($id, $data['status'], $this->tenant($request), $request->user()));
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'training_program_id' => "$req|integer",
            'trainer_name'        => "$req|string|max:150",
            'start_at'            => "$req|date",
            'end_at'              => "$req|date",
            'capacity'            => 'nullable|integer|min:1|max:100000',
            'provider_id'         => 'nullable|integer',
            'department_id'       => 'nullable|integer',
            'designation_id'      => 'nullable|integer',
            'title'               => 'nullable|string|max:180',
            'mode'                => 'nullable|in:Online,Offline,Hybrid',
            'venue'               => 'nullable|string|max:200',
            'meeting_url'         => 'nullable|string|max:300',
            'notes'               => 'nullable|string',
        ]);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage training sessions');
    }
}
