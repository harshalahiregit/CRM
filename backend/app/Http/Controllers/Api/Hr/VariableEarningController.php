<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrApprovalAction;
use App\Models\Hr\HrEmployeeVariableEarning;
use App\Models\Hr\HrSalaryComponent;
use App\Services\Hr\Approval\ApprovalEngine;
use App\Services\Hr\VariableEarningService;
use App\Support\Hr\Approval\ApprovalProcess;
use Illuminate\Http\Request;

/**
 * #31 — commissions and incentives.
 *
 * Everything here is money, so every route is HR-gated. Raising and approving are
 * deliberately the same permission as the rest of payroll rather than a new one:
 * inventing a permission nobody has been granted would leave the screen unusable.
 */
class VariableEarningController extends Controller
{
    public function __construct(
        private VariableEarningService $service,
        private ApprovalEngine $engine,
    ) {
    }

    public function index(Request $request)
    {
        $this->can($request);

        return response()->json(['data' => $this->service->list(
            $this->tenant($request),
            $request->only(['employee_id', 'period', 'status', 'component_id']),
            $request->user()
        )]);
    }

    /** Earning components a commission may be paid against. */
    public function components(Request $request)
    {
        $this->can($request);

        return response()->json(['data' => HrSalaryComponent::where('tenant_id', $this->tenant($request))
            ->where('type', 'Earning')->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'taxable', 'pf_applicable', 'esic_applicable'])]);
    }

    public function store(Request $request)
    {
        $this->can($request);

        return response()->json(
            $this->service->save($request->all(), $this->tenant($request), $request->user()), 201
        );
    }

    public function update(Request $request, int $id)
    {
        $this->can($request);

        return response()->json(
            $this->service->save($request->all() + ['id' => $id], $this->tenant($request), $request->user())
        );
    }

    /**
     * Approve — through the configured ladder.
     *
     * Only approve and reject are decisions. store(), update() and destroy()
     * stay off the ladder: raising or amending a commission is the REQUEST,
     * not the approval of one, and VariableEarningService::save() already
     * resets an edited earning to Pending so a changed figure has to be
     * approved again on its own merits.
     */
    public function approve(Request $request, int $id)
    {
        $this->can($request);

        return $this->decide($request, $id, HrApprovalAction::APPROVED, null);
    }

    public function reject(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['remarks' => 'required|string|max:1000']);

        return $this->decide($request, $id, HrApprovalAction::REJECTED, $data['remarks']);
    }

    /**
     * One decision, through the engine, for both verbs.
     *
     * The three gates stay separate: can() is the capability,
     * findForDecision() and the engine both apply the data scope, and the
     * ladder decides whether this request is waiting on this person. Only the
     * last rung calls VariableEarningService, which still owns the status
     * transition, the paid-record guards and the audit line.
     */
    private function decide(Request $request, int $id, string $action, ?string $remarks)
    {
        $tenantId = $this->tenant($request);
        $actor    = $request->user();

        $earning = $this->service->findForDecision($id, $tenantId, $actor);
        abort_unless($earning, 404, 'Variable earning not found');

        /*
         | PAID is the service's business, not the ladder's.
         |
         | Passed straight through so the existing message — "this earning has
         | already been paid" — is the one the user sees, rather than a generic
         | workflow refusal. Payroll has already consumed it.
         */
        if ($earning->status === HrEmployeeVariableEarning::PAID) {
            return response()->json(
                $action === HrApprovalAction::APPROVED
                    ? $this->service->approve($id, $tenantId, $actor)
                    : $this->service->reject($id, $tenantId, (string) $remarks, $actor)
            );
        }

        $approval = $this->engine->requestFor(
            $earning,
            ApprovalProcess::VARIABLE_EARNING,
            $tenantId,
            (int) $earning->employee_id,
            (float) $earning->amount,
        );

        /*
         | Already decided, and not by payroll.
         |
         | VariableEarningService::approve() does NOT refuse an already-approved
         | earning — it only guards PAID — so unlike loans there is no domain
         | guard behind this to catch a second press. Refusing here is what
         | stops the final action running twice and writing a second audit line.
         */
        if ($earning->status !== HrEmployeeVariableEarning::PENDING) {
            $this->engine->supersede($approval);

            abort(422, 'This earning has already been '.$earning->status.'.');
        }

        $inspection = $this->engine->inspect($approval);
        if (! $inspection['resolvable']) {
            $this->engine->block($approval, $inspection['describe']);
            abort(409, 'This earning cannot be approved yet: '.$inspection['describe'].'.');
        }

        $this->engine->assertMayDecide($approval, $actor);

        $result = $this->engine->decide($approval, $actor, $action, $remarks);

        if (! $result['final']) {
            // Still climbing. The earning stays Pending, so linesFor() — which
            // reads Approved only — cannot pull it into a payroll run.
            return response()->json([
                'approval' => $this->approvalPayload($result['request']),
                'data'     => $earning->fresh(),
            ]);
        }

        $payload = $action === HrApprovalAction::APPROVED
            ? $this->service->approve($id, $tenantId, $actor)
            : $this->service->reject($id, $tenantId, (string) $remarks, $actor);

        return response()->json([
            'data'     => $payload,
            'approval' => $this->approvalPayload($result['request']),
        ]);
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

    public function destroy(Request $request, int $id)
    {
        $this->can($request);
        $this->service->destroy($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Variable earning deleted']);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage variable earnings');
    }
}
