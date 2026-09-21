<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrApprovalAction;
use App\Models\Hr\HrExitRequest;
use App\Services\Hr\Approval\ApprovalEngine;
use App\Services\Hr\ExitApprovalService;
use App\Support\Hr\Approval\ApprovalProcess;
use Illuminate\Http\Request;

/**
 * Exit Management → Exit Approval (Phase 3). Thin: validate, delegate, return JSON.
 * Reads open to HR users; decisions require HR-queue management. Tenant-scoped, audited.
 */
class ExitApprovalController extends Controller
{
    public function __construct(
        private ExitApprovalService $service,
        private ApprovalEngine $engine,
    ) {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->queue($this->tenant($request), $request->only(['employee_id', 'department', 'exit_type_id', 'status', 'search']), $request->user()));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request), $request->user()));
    }

    public function history(Request $request)
    {
        return response()->json($this->service->history($this->tenant($request), $request->only(['employee_id']), $request->user()));
    }

    public function startReview(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['review_remarks' => 'nullable|string']);

        return response()->json($this->service->startReview($id, $data, $this->tenant($request), $request->user()));
    }

    public function updateRemarks(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['review_remarks' => 'nullable|string']);

        return response()->json($this->service->updateReviewRemarks($id, $data, $this->tenant($request), $request->user()));
    }

    /**
     * Approve — through the configured ladder.
     *
     * Only approve and reject are decisions. startReview() and
     * updateRemarks() stay off the ladder: moving a request into review is
     * picking the work up, not deciding it, and the domain already REQUIRES
     * Under Review before either decision — assertDecidable() refuses
     * otherwise, and that precondition is unchanged.
     *
     * Exit is three domains. This is the first: whether somebody leaves.
     * Clearance and settlement have their own lifecycles and their own queues,
     * and neither is migrated here.
     */
    public function approve(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['remarks' => 'nullable|string']);

        return $this->decide($request, $id, HrApprovalAction::APPROVED, $data);
    }

    public function reject(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['remarks' => 'nullable|string']);

        return $this->decide($request, $id, HrApprovalAction::REJECTED, $data);
    }

    /**
     * One decision, through the engine, for both verbs.
     *
     * The three gates stay separate: can() is the capability, assertInScope()
     * below is the data scope, and the ladder decides whether this request is
     * waiting on this person. Only the last rung calls ExitApprovalService,
     * which still owns the status transition, assertDecidable() and the audit
     * line.
     *
     * Approving an exit has a downstream consequence, and it is a PULL rather
     * than a push: ClearanceRepository::approvedExitsNeedingClearance() looks
     * for APPROVED exits that have no clearance yet. So an exit halfway up a
     * ladder is still Under Review and simply does not appear in the clearance
     * queue — the property holds without any clearance code being touched.
     */
    private function decide(Request $request, int $id, string $action, array $data)
    {
        $tenantId = $this->tenant($request);
        $actor    = $request->user();

        $exit = HrExitRequest::where('tenant_id', $tenantId)->find($id);
        abort_unless($exit, 404, 'Exit request not found');

        // The data scope, unchanged — an out-of-scope leaver is absent, not
        // forbidden, exactly as ExitApprovalService::find() already treats them.
        app(\App\Services\Auth\ScopeResolver::class)
            ->assertCanActOnEmployee($actor, $exit->employee_id);

        $approval = $this->engine->requestFor(
            $exit,
            ApprovalProcess::EXIT_REQUEST,
            $tenantId,
            (int) $exit->employee_id,
        );

        /*
         | Not under review — the service's business, not the ladder's.
         |
         | assertDecidable() has four distinct messages (already approved,
         | already rejected, withdrawn, not yet under review) and they are more
         | useful than anything the engine could say. Passed straight through.
         */
        if ($exit->status !== HrExitRequest::UNDER_REVIEW) {
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
            abort(409, 'This exit request cannot be decided yet: '.$inspection['describe'].'.');
        }

        $this->engine->assertMayDecide($approval, $actor);

        $result = $this->engine->decide($approval, $actor, $action, $data['remarks'] ?? null);

        if (! $result['final']) {
            // Still climbing. The request stays Under Review, so it is not yet
            // an approved exit and no clearance is owed.
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

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to action exit approvals');
    }
}
