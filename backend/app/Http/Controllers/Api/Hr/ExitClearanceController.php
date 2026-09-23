<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\ExitClearanceService;
use Illuminate\Http\Request;

/**
 * Exit Management → Clearance (Phase 4). Thin: validate, delegate, return JSON.
 * Tenant-scoped, audited.
 *
 * DEPARTMENTAL ACTIONS are authorised per department, not by one blanket check.
 * This controller used to call canManageHrQueue() before all four mutations,
 * identically for every department, so anybody on the HR queue could clear IT,
 * Finance or the reporting manager's item.
 *
 * That check is gone from here and lives in ExitClearanceService::item(), which
 * is the one door start, clear, reject and remarks all pass through — and the
 * only place that knows WHICH department is being actioned, which the old
 * check never did. A department with nobody configured still falls back to the
 * HR queue, so nothing that worked before stops working.
 *
 * Reads are unchanged in this pass, deliberately: they are scoped by
 * ClearanceRepository and their permission gating is a separate question from
 * who may act on a department.
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
        return response()->json($this->service->history($this->tenant($request), $request->only(['employee_id']), $request->user()));
    }

    /** Employee Profile → Exit tab: read-only clearance progress for an employee. */
    public function forEmployee(Request $request, int $employee)
    {
        return response()->json($this->service->forEmployee($employee, $this->tenant($request), $request->user()));
    }

    public function start(Request $request, int $id, int $item)
    {
        $data = $request->validate(['assigned_to' => 'nullable|string|max:150', 'remarks' => 'nullable|string']);

        return response()->json($this->service->startItem($id, $item, $data, $this->tenant($request), $request->user()));
    }

    public function clear(Request $request, int $id, int $item)
    {
        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json($this->service->clearItem($id, $item, $data, $this->tenant($request), $request->user()));
    }

    public function reject(Request $request, int $id, int $item)
    {
        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json($this->service->rejectItem($id, $item, $data, $this->tenant($request), $request->user()));
    }

    public function remarks(Request $request, int $id, int $item)
    {
        $data = $request->validate(['remarks' => 'nullable|string', 'assigned_to' => 'nullable|string|max:150']);

        return response()->json($this->service->updateItemRemarks($id, $item, $data, $this->tenant($request), $request->user()));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }
}
