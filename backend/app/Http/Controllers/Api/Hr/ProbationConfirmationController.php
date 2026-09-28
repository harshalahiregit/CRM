<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrApprovalAction;
use App\Models\Hr\HrProbationConfirmation;
use App\Services\Hr\Approval\ApprovalEngine;
use App\Services\Hr\ProbationConfirmationService;
use App\Support\Hr\Approval\ApprovalProcess;
use Illuminate\Http\Request;

/**
 * Probation Management → Confirmation Workflow (Phase 5). Thin: validate,
 * delegate, return JSON. Reads open to HR users; writes require HR-queue
 * management. Tenant-scoped, audited.
 */
class ProbationConfirmationController extends Controller
{
    public function __construct(
        private ProbationConfirmationService $service,
        private ApprovalEngine $engine,
    ) {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['employee_id', 'department', 'recommendation', 'status', 'from', 'to', 'search']), $request->user()));
    }

    public function history(Request $request)
    {
        return response()->json($this->service->history($this->tenant($request), $request->only(['employee_id']), $request->user()));
    }

    public function forEmployee(Request $request, int $employee)
    {
        return response()->json($this->service->forEmployee($employee, $this->tenant($request), $request->user()));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request), $request->user()));
    }

    public function store(Request $request)
    {
        $this->can($request);
        $data = $request->validate([
            'probation_id'     => 'required|integer',
            'decision'         => 'nullable|in:Confirm,Extend,Terminate,Continue',
            'effective_date'   => 'nullable|date',
            'manager_comments' => 'nullable|string',
            'hr_comments'      => 'nullable|string',
            'remarks'          => 'nullable|string',
        ]);

        return response()->json($this->service->create($data, $this->tenant($request), $request->user()), 201);
    }

    public function update(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate([
            'decision'         => 'nullable|in:Confirm,Extend,Terminate,Continue',
            'effective_date'   => 'nullable|date',
            'manager_comments' => 'nullable|string',
            'hr_comments'      => 'nullable|string',
            'remarks'          => 'nullable|string',
        ]);

        return response()->json($this->service->update($id, $data, $this->tenant($request), $request->user()));
    }

    /**
     * Approve — through the configured ladder.
     *
     * This is the decision. confirm() below is NOT: it executes a decision
     * already taken, the way disburse() does for a loan, and it already
     * refuses anything that is not Approved.
     *
     * That refusal is what makes a multi-rung ladder safe here. An
     * intermediate approval leaves the confirmation Pending, so confirm()
     * still says the confirmation must be approved first — and closing a
     * probation is irreversible, because a confirmed employee cannot return
     * to one.
     */
    public function approve(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['hr_comments' => 'nullable|string']);

        return $this->decide($request, $id, HrApprovalAction::APPROVED, $data);
    }

    public function reject(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['hr_comments' => 'nullable|string']);

        return $this->decide($request, $id, HrApprovalAction::REJECTED, $data);
    }

    /**
     * One decision, through the engine, for both verbs.
     *
     * The three gates stay separate: can() is the capability, the engine
     * asserts the data scope against the confirmation's employee, and the
     * ladder decides whether this confirmation is waiting on this person. Only
     * the last rung calls the service, which keeps every precondition it had —
     * the probation must still be active or extended, and a completed review
     * is still required when the probation type demands one.
     */
    private function decide(Request $request, int $id, string $action, array $data)
    {
        $tenantId = $this->tenant($request);
        $actor    = $request->user();

        $conf = HrProbationConfirmation::where('tenant_id', $tenantId)->find($id);
        abort_unless($conf, 404, 'Confirmation not found');

        app(\App\Services\Auth\ScopeResolver::class)
            ->assertCanActOnEmployee($actor, $conf->employee_id);

        $approval = $this->engine->requestFor(
            $conf,
            ApprovalProcess::PROBATION_CONFIRMATION,
            $tenantId,
            (int) $conf->employee_id,
        );

        /*
         | Not pending — the service's business, not the ladder's.
         |
         | Rejection deliberately still reaches an APPROVED confirmation: the
         | domain allows a confirmation to be rejected right up until the
         | employee is actually confirmed, and the ladder does not take that
         | away. The service's own messages are the useful ones here.
         */
        if ($conf->status !== HrProbationConfirmation::PENDING) {
            $this->engine->supersede($approval);

            return response()->json(
                $action === HrApprovalAction::APPROVED
                    ? $this->service->approve($id, $data, $tenantId, $actor)
                    : $this->service->reject($id, $data, $tenantId, $actor)
            );
        }

        $inspection = $this->engine->inspect($approval);
        if (! $inspection['resolvable']) {
            $this->engine->block($approval, $inspection['describe']);
            abort(409, 'This confirmation cannot be decided yet: '.$inspection['describe'].'.');
        }

        $this->engine->assertMayDecide($approval, $actor);

        $result = $this->engine->decide($approval, $actor, $action, $data['hr_comments'] ?? null);

        if (! $result['final']) {
            // Still climbing. The confirmation stays Pending, so confirm()
            // refuses and the probation cannot be closed.
            return response()->json([
                'approval' => $this->approvalPayload($result['request']),
                'data'     => $this->service->show($id, $tenantId, $actor),
            ]);
        }

        $payload = $action === HrApprovalAction::APPROVED
            ? $this->service->approve($id, $data, $tenantId, $actor)
            : $this->service->reject($id, $data, $tenantId, $actor);

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

    public function confirm(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate([
            'effective_date' => 'nullable|date',
            'remarks'        => 'nullable|string',
        ]);

        return response()->json($this->service->confirm($id, $data, $this->tenant($request), $request->user()));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage probation confirmations');
    }
}
