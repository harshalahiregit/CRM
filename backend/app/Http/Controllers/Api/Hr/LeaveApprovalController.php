<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrApprovalAction;
use App\Models\Hr\HrLeaveApplication;
use App\Services\Hr\Approval\ApprovalEngine;
use App\Services\Hr\LeaveApprovalService;
use App\Support\Hr\Approval\ApprovalProcess;
use Illuminate\Http\Request;

/**
 * Leave → Approval workflow (Phase 4). Thin: validate, delegate, return JSON.
 * Reads open to HR users; approve/reject require HR-queue management.
 * Tenant-scoped and audited via the service.
 */
class LeaveApprovalController extends Controller
{
    public function __construct(
        private LeaveApprovalService $service,
        private ApprovalEngine $engine,
    ) {
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

    /**
     * Approve — through the configured ladder.
     *
     * The three gates in order: can() is the capability, assertInScope() is the
     * data scope, and the engine asks whether this request is actually waiting
     * on this person. All three must pass, and none of them stands in for
     * another — being named on a step is not a grant of access to the employee.
     *
     * Only the LAST rung calls the service. An intermediate approval records
     * the decision and advances the ladder while the application stays
     * Submitted, because deducting the balance halfway up would pay out a leave
     * nobody has finished approving.
     */
    public function approve(Request $request, int $id)
    {
        $this->can($request);
        $this->assertInScope($request, $id);
        $data = $request->validate(['remarks' => 'nullable|string']);

        return $this->decide($request, $id, HrApprovalAction::APPROVED, $data['remarks'] ?? null);
    }

    public function reject(Request $request, int $id)
    {
        $this->can($request);
        $this->assertInScope($request, $id);
        $data = $request->validate(['remarks' => 'nullable|string']);

        return $this->decide($request, $id, HrApprovalAction::REJECTED, $data['remarks'] ?? null);
    }

    /**
     * One decision, through the engine, for both verbs.
     *
     * Rejection is terminal at any rung — which is the behaviour every existing
     * HR flow already has, so the ladder does not change what a "no" means.
     */
    private function decide(Request $request, int $id, string $action, ?string $remarks)
    {
        $tenantId = $this->tenant($request);
        $actor    = $request->user();

        $application = HrLeaveApplication::where('tenant_id', $tenantId)->find($id);
        abort_unless($application, 404, 'Leave application not found');

        $approval = $this->engine->requestFor(
            $application,
            ApprovalProcess::LEAVE,
            $tenantId,
            (int) $application->employee_id,
        );

        // Already decided elsewhere — the attendance app decides through the
        // service directly and is deliberately unchanged. Close the engine's
        // view so it does not contradict the record, then let the service raise
        // its own "already approved" message exactly as it does today.
        if (! in_array($application->status, [HrLeaveApplication::SUBMITTED, HrLeaveApplication::DRAFT], true)) {
            $this->engine->supersede($approval);

            return response()->json(
                $action === HrApprovalAction::APPROVED
                    ? $this->service->approve($id, $remarks, $tenantId, $actor)
                    : $this->service->reject($id, $remarks, $tenantId, $actor)
            );
        }

        // An unresolvable rung is flagged rather than silently approved.
        $inspection = $this->engine->inspect($approval);
        if (! $inspection['resolvable']) {
            $this->engine->block($approval, $inspection['describe']);
            abort(409, 'This request cannot be approved yet: '.$inspection['describe'].'.');
        }

        $this->engine->assertMayDecide($approval, $actor);

        $result = $this->engine->decide($approval, $actor, $action, $remarks);

        if (! $result['final']) {
            // Still climbing. The application is untouched.
            return response()->json([
                'approval' => $this->approvalPayload($result['request']),
                'data'     => $this->service->show($id, $tenantId, $actor),
            ]);
        }

        // Last rung: the service does what it has always done — balance ledger,
        // status transition, audit line and the employee's notification.
        $payload = $action === HrApprovalAction::APPROVED
            ? $this->service->approve($id, $remarks, $tenantId, $actor)
            : $this->service->reject($id, $remarks, $tenantId, $actor);

        return response()->json($payload + ['approval' => $this->approvalPayload($result['request'])]);
    }

    /** What the UI needs to draw the ladder's current position. */
    private function approvalPayload($approval): array
    {
        return [
            'state'        => $approval->state,
            'current_step' => $approval->current_step,
            'total_steps'  => count($approval->steps_snapshot ?: []),
            'steps'        => $approval->steps_snapshot ?: [],
        ];
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
