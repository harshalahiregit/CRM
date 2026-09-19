<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrLeaveApplication;
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
        // The actor narrows the queue when their role is scoped; a global role —
        // which is every role today — sees exactly what it saw before.
        return response()->json($this->service->queue(
            $this->tenant($request),
            $request->only(['employee_id', 'leave_type_id', 'status', 'department', 'from', 'to']),
            $request->user(),
        ));
    }

    public function show(Request $request, int $id)
    {
        $this->assertInScope($request, $id);

        return response()->json($this->service->show($id, $this->tenant($request), $request->user()));
    }

    public function approve(Request $request, int $id)
    {
        $this->can($request);
        $this->assertInScope($request, $id);
        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json($this->service->approve($id, $data['remarks'] ?? null, $this->tenant($request), $request->user()));
    }

    public function reject(Request $request, int $id)
    {
        $this->can($request);
        $this->assertInScope($request, $id);
        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json($this->service->reject($id, $data['remarks'] ?? null, $this->tenant($request), $request->user()));
    }

    public function history(Request $request, int $employeeId)
    {
        // The employee id arrives directly here, so it is checked directly.
        app(\App\Services\Auth\ScopeResolver::class)
            ->assertCanActOnEmployee($request->user(), $employeeId);

        return response()->json($this->service->history($employeeId, $this->tenant($request)));
    }

    /**
     * The scope boundary for one application, resolved through its employee.
     *
     * Deliberately HERE and not inside LeaveApprovalService: the attendance app
     * calls approve() and reject() on that same service, and it already scopes
     * its own decisions by reporting line
     * (HrmAdminController::denyDecisionFor). Putting the check inside the
     * service would change what the phone does; putting it on the CRM's own
     * controller does not.
     *
     * Permission has already run by this point — `can()` above asks whether they
     * may decide leave at all. This asks only whose.
     *
     * Tenant-scoped find, so an id from another workspace is 404 either way.
     */
    private function assertInScope(Request $request, int $id): void
    {
        $employeeId = HrLeaveApplication::where('tenant_id', $this->tenant($request))
            ->whereKey($id)
            ->value('employee_id');

        app(\App\Services\Auth\ScopeResolver::class)
            ->assertCanActOnEmployee($request->user(), $employeeId);
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
