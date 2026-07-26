<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\ExitClearanceService;
use Illuminate\Http\Request;

/**
 * Exit Management → Clearance (Phase 4). Thin: validate, delegate, return JSON.
 * Reads open to HR users; departmental actions require HR-queue management.
 * Tenant-scoped, audited.
 */
class ExitClearanceController extends Controller
{
    public function __construct(private ExitClearanceService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->queue($this->tenant($request), $request->only(['employee_id', 'department', 'status', 'exit_type_id', 'search']), $request->user()));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request), $request->user()));
    }

    public function history(Request $request)
    {
        return response()->json($this->service->history($this->tenant($request), $request->only(['employee_id'])));
    }

    /** Employee Profile → Exit tab: read-only clearance progress for an employee. */
    public function forEmployee(Request $request, int $employee)
    {
        return response()->json($this->service->forEmployee($employee, $this->tenant($request)));
    }

    public function start(Request $request, int $id, int $item)
    {
        $this->can($request);
        $data = $request->validate(['assigned_to' => 'nullable|string|max:150', 'remarks' => 'nullable|string']);

        return response()->json($this->service->startItem($id, $item, $data, $this->tenant($request), $request->user()));
    }

    public function clear(Request $request, int $id, int $item)
    {
        $this->can($request);
        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json($this->service->clearItem($id, $item, $data, $this->tenant($request), $request->user()));
    }

    public function reject(Request $request, int $id, int $item)
    {
        $this->can($request);
        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json($this->service->rejectItem($id, $item, $data, $this->tenant($request), $request->user()));
    }

    public function remarks(Request $request, int $id, int $item)
    {
        $this->can($request);
        $data = $request->validate(['remarks' => 'nullable|string', 'assigned_to' => 'nullable|string|max:150']);

        return response()->json($this->service->updateItemRemarks($id, $item, $data, $this->tenant($request), $request->user()));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to action exit clearances');
    }
}
