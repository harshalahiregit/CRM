<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\LeaveApprovalService;
use Illuminate\Http\Request;

/**
 * Leave → Approval workflow (Phase 4). Thin: validate, delegate, return JSON.
 * Reads open to HR users; approve/reject require HR-queue management.
 * Tenant-scoped and audited via the service.
 */
class LeaveApprovalController extends Controller
{
    public function __construct(private LeaveApprovalService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->queue($this->tenant($request), $request->only(['employee_id', 'leave_type_id', 'status', 'department', 'from', 'to'])));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request), $request->user()));
    }

    public function approve(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json($this->service->approve($id, $data['remarks'] ?? null, $this->tenant($request), $request->user()));
    }

    public function reject(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json($this->service->reject($id, $data['remarks'] ?? null, $this->tenant($request), $request->user()));
    }

    public function history(Request $request, int $employeeId)
    {
        return response()->json($this->service->history($employeeId, $this->tenant($request)));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to action leave approvals');
    }
}
