<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\EmployeeTrainingService;
use Illuminate\Http\Request;

/**
 * Learning & Development → Employee Training Assignment (Phase 4). Thin: validate,
 * delegate, return JSON. Reads open to HR users; writes require HR-queue
 * management. Tenant-scoped, audited.
 */
class EmployeeTrainingController extends Controller
{
    public function __construct(private EmployeeTrainingService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['employee_id', 'department', 'training_program_id', 'training_session_id', 'status', 'search'])));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request), $request->user()));
    }

    public function forEmployee(Request $request, int $employee)
    {
        return response()->json($this->service->forEmployee($employee, $this->tenant($request)));
    }

    public function history(Request $request)
    {
        return response()->json($this->service->history($this->tenant($request), $request->only(['employee_id'])));
    }

    public function store(Request $request)
    {
        $this->can($request);
        $data = $request->validate([
            'employee_id'         => 'required|integer',
            'training_session_id' => 'required|integer',
            'due_date'            => 'nullable|date',
            'remarks'             => 'nullable|string',
            // #23 — recorded only when this turns out to be a repeat assignment.
            // The attempt number itself is derived, never accepted from the client.
            'retraining_reason'   => 'nullable|string|max:500',
        ]);

        return response()->json($this->service->assign($data, $this->tenant($request), $request->user()), 201);
    }

    /** #23 — every attempt this employee has made at one programme. */
    public function retrainingHistory(Request $request, int $employee, int $program)
    {
        return response()->json($this->service->retrainingHistory($employee, $program, $this->tenant($request)));
    }

    /** #23 — every programme this employee has repeated, with counts. */
    public function retrainingSummary(Request $request, int $employee)
    {
        return response()->json(['data' => $this->service->retrainingSummary($employee, $this->tenant($request))]);
    }

    public function start(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['completion_percentage' => 'nullable|integer|min:0|max:100']);

        return response()->json($this->service->start($id, $data, $this->tenant($request), $request->user()));
    }

    public function complete(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json($this->service->complete($id, $data, $this->tenant($request), $request->user()));
    }

    public function cancel(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json($this->service->cancel($id, $data, $this->tenant($request), $request->user()));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage training assignments');
    }
}
