<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrApprovalAction;
use App\Models\Hr\HrReimbursement;
use App\Services\Hr\Approval\ApprovalEngine;
use App\Services\Hr\ReimbursementService;
use App\Services\Hr\RequestThreadService;
use App\Services\Shared\AttachmentService;
use App\Support\Hr\Approval\ApprovalProcess;
use App\Support\Hr\ReimbursementStatus;
use Illuminate\Http\Request;

/**
 * The admin side of expense claims.
 *
 * The action set is the same three at every point a claim is open — accept,
 * decline, hold — however it got there. A claim held twice and answered twice
 * offers exactly what a fresh one does; what differs is the thread above it,
 * which is why `show` returns the whole history rather than just the latest hold.
 *
 * EVERY route here is gated by the `hr.manage` middleware, applied to the group
 * rather than checked inside each method. The first draft gated the lookup helper
 * instead, which left index() — the method that lists every claim in the tenant —
 * open to any authenticated user, because it does not go through that helper.
 *
 * Gated on `permission:hr_attendance,view_global` — the permission grid an admin
 * ticks in Staff Management, not a role string in PHP. It was canManageHrQueue
 * until the grid started being enforced; a finer split (a reimbursement module of
 * its own, or `edit` for the approve routes) is a later refinement of the same
 * mechanism rather than a different one.
 */
class ReimbursementController extends Controller
{
    public function __construct(
        private ReimbursementService $claims,
        private RequestThreadService $thread,
        private AttachmentService $attachments,
        private ApprovalEngine $engine,
    ) {
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'status'      => 'nullable|' . ReimbursementStatus::rule(),
            'employee_id' => 'nullable|integer',
            'from'        => 'nullable|date',
            'to'          => 'nullable|date',
        ]);

        $claims = HrReimbursement::where('tenant_id', $request->user()->tenant_id)
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['employee_id'] ?? null, fn ($q, $e) => $q->where('employee_id', $e))
            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('expense_date', '>=', $d))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('expense_date', '<=', $d))
            ->with('employee:id,name,employee_code,department')
            ->withCount('attachments')
            // Anything waiting on a person first; decided claims are history.
            ->orderByRaw("CASE WHEN status IN ('pending','on_hold') THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get();

        return response()->json(['status' => 'success', 'data' => $claims]);
    }

    public function show(Request $request, int $id)
    {
        $claim = $this->find($request, $id);

        return response()->json([
            'status' => 'success',
            'data'   => [
                // decided_by is a user id; the NAME is what a person needs, and the
                // relation cannot simply be loaded because Eloquent would serialise
                // it over the decided_by column itself.
                'claim'  => tap($claim->load(['employee:id,name,employee_code,department', 'attachments']), function ($c) {
                    $c->setAttribute('decided_by_name', optional($c->decidedBy()->first())->name);
                }),
                // asEmployee: false — an admin sees internal notes as well.
                'thread' => $this->thread->forSubject($claim, asEmployee: false),
                'can'    => [
                    'approve' => ! $claim->isDecided(),
                    'decline' => ! $claim->isDecided(),
                    'hold'    => ! $claim->isDecided(),
                ],
            ],
        ]);
    }

    /**
     * Approve — through the configured ladder.
     *
     * Approving carries an optional amount: an approver may grant less than was
     * claimed, with a reason. So on a multi-step ladder only the LAST rung's
     * figure is applied — an intermediate approver records that they are
     * content and nothing else. Until the ladder finishes, amount_approved
     * stays null and payableAmount() returns null, so nothing is payable.
     */
    public function approve(Request $request, int $id)
    {
        $data = $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            // Required only when the amount actually changes, which the service
            // decides — it knows the current figure, and the client should not
            // have to work out whether its own input is a change.
            'reason' => 'nullable|string|max:1000',
        ]);

        return $this->decide($request, $id, HrApprovalAction::APPROVED, $data['reason'] ?? null, $data);
    }

    public function decline(Request $request, int $id)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);

        return $this->decide($request, $id, HrApprovalAction::REJECTED, $data['reason'], $data);
    }

    /**
     * One decision, through the engine, for both verbs.
     *
     * Three gates, still separate: the hr.manage middleware on the route group
     * is the capability, the engine asserts the data scope against the claim's
     * employee, and the ladder decides whether this claim is waiting on this
     * person. The service keeps its own rules on top — most importantly
     * assertNotOwnClaim(), so being named as an approver never lets somebody
     * decide their own expenses.
     *
     * hold() and note() are NOT routed here. A hold pauses a claim to ask the
     * employee something; it decides nothing, and the employee's reply returns
     * the claim to the rung it came from.
     */
    private function decide(Request $request, int $id, string $action, ?string $reason, array $data)
    {
        $actor    = $request->user();
        $tenantId = (int) $actor->tenant_id;
        $claim    = $this->find($request, $id);

        $approval = $this->approvalFor($claim, $tenantId);

        /*
         | Already decided, including by the attendance app.
         |
         | HrmAdminController decides claims through this same service with its
         | own reporting-line check, and that path is deliberately unchanged.
         | Close the engine's view so it does not contradict the record, then
         | let the service raise its own "already been decided" message.
         */
        if ($claim->isDecided()) {
            $this->engine->supersede($approval);

            return $this->runDomainAction($claim, $actor, $action, $reason, $data);
        }

        $inspection = $this->engine->inspect($approval);
        if (! $inspection['resolvable']) {
            $this->engine->block($approval, $inspection['describe']);
            abort(409, 'This claim cannot be decided yet: '.$inspection['describe'].'.');
        }

        $this->engine->assertMayDecide($approval, $actor);

        // The service's own rule, asked BEFORE the rung is consumed: deciding
        // your own claim is refused whatever the ladder says, and a refusal
        // must not leave an approval action recorded behind it.
        $this->assertNotOwnClaim($claim, $actor);

        $result = $this->engine->decide($approval, $actor, $action, $reason);

        if (! $result['final']) {
            // Still climbing. amount_approved is untouched, so the claim is not
            // payable and the employee is not told it was approved.
            return response()->json([
                'status'   => 1,
                'message'  => 'Recorded. The claim is now with the next approver.',
                'approval' => $this->approvalPayload($result['request']),
                'data'     => $claim->fresh(),
            ]);
        }

        return $this->runDomainAction($claim->fresh(), $actor, $action, $reason, $data, $result['request']);
    }

    /** The existing service call, unchanged, with the ladder's position attached. */
    private function runDomainAction($claim, $actor, string $action, ?string $reason, array $data, $approval = null)
    {
        $decided = $action === HrApprovalAction::APPROVED
            ? $this->claims->approve(
                $claim, $actor,
                isset($data['amount']) ? (float) $data['amount'] : null,
                $reason
            )
            : $this->claims->decline($claim, $actor, (string) $reason);

        $response = $this->decided(
            $decided,
            $action === HrApprovalAction::APPROVED ? 'Claim approved.' : 'Claim declined.'
        );

        if (! $approval) {
            return $response;
        }

        $payload = $response->getData(true);
        $payload['approval'] = $this->approvalPayload($approval);

        return response()->json($payload);
    }

    /** This claim's open approval round, opened lazily on first decision. */
    private function approvalFor($claim, int $tenantId)
    {
        return $this->engine->requestFor(
            $claim,
            ApprovalProcess::REIMBURSEMENT,
            $tenantId,
            (int) $claim->employee_id,
            (float) $claim->amount_claimed,
        );
    }

    /**
     * Mirrors ReimbursementService::assertNotOwnClaim().
     *
     * Duplicated here rather than relied upon downstream because the service is
     * only reached on the FINAL rung; without this, an employee named as an
     * intermediate approver could consume a step on their own claim before the
     * service ever saw it.
     */
    private function assertNotOwnClaim($claim, $actor): void
    {
        $employee = $claim->relationLoaded('employee') ? $claim->employee : $claim->employee()->first();

        if ($employee && $employee->user_id !== null && (int) $employee->user_id === (int) $actor->id) {
            abort(403, 'You cannot decide your own expense claim.');
        }
    }

    private function approvalPayload($approval): array
    {
        return [
            'state'        => $approval->state,
            'current_step' => $approval->current_step,
            'total_steps'  => count($approval->steps_snapshot ?: []),
            'steps'        => $approval->steps_snapshot ?: [],
        ];
    }

    public function hold(Request $request, int $id)
    {
        $data = $request->validate([
            // Free text, always. A fixed list of hold reasons would be wrong
            // within a month — the interesting holds are the unanticipated ones.
            'reason'          => 'required|string|max:1000',
            // Optional. Its presence is what turns a question into a counter-offer
            // and what makes Accept appear for the employee.
            'proposed_amount' => 'nullable|numeric|min:0.01',
        ]);

        $claim = $this->find($request, $id);

        /*
         | A counter-offer binds, so only the last rung may make one.
         |
         | The employee accepts a proposal through their own screen, and
         | ReimbursementService::acceptProposal() APPROVES the claim at the
         | agreed figure. With a multi-step ladder that is a bypass: a manager
         | on rung 1 could propose an amount, the employee could accept it, and
         | the claim would be approved without finance ever seeing it.
         |
         | Holding to ASK something stays available at every rung — that is what
         | a hold is for. It is only the binding offer that waits until the
         | person making it is the one who can actually settle the claim.
         */
        if (isset($data['proposed_amount']) && ! $claim->isDecided()) {
            $approval = $this->approvalFor($claim, (int) $request->user()->tenant_id);

            if ($approval->isOpen() && ! $approval->isFinalStep()) {
                abort(409, 'Only the final approver can propose an amount, because accepting one approves the claim. Hold with a question instead.');
            }
        }

        $claim = $this->claims->hold(
            $claim,
            $request->user(),
            $data['reason'],
            isset($data['proposed_amount']) ? (float) $data['proposed_amount'] : null
        );

        return $this->decided($claim, 'Claim put on hold. The employee has been asked to respond.');
    }

    /** An admin talking to other admins. The employee never sees these. */
    public function note(Request $request, int $id)
    {
        $data = $request->validate(['body' => 'required|string|max:2000']);

        $claim = $this->find($request, $id);
        $this->claims->note($claim, $request->user(), $data['body']);

        return response()->json([
            'status'  => 'success',
            'message' => 'Note added. The employee cannot see it.',
            'data'    => ['thread' => $this->thread->forSubject($claim, asEmployee: false)],
        ]);
    }

    /**
     * The bytes of one receipt. Reviewing a claim means opening what was sent.
     *
     * Scoped through find(), so it is tenant-scoped and gated by hr.manage like
     * everything else on this controller. The attachment is looked up THROUGH
     * the claim rather than by id alone — an id from another tenant's claim
     * must not resolve just because the caller can manage the HR queue here.
     */
    public function attachment(Request $request, int $id, int $attachmentId)
    {
        $claim = $this->find($request, $id);

        $file = $claim->attachments()->findOrFail($attachmentId);

        $f = $this->attachments->download($file);

        return response()->download($f['path'], $f['filename'], ['Content-Type' => $f['mime']]);
    }

    private function find(Request $request, int $id): HrReimbursement
    {
        return HrReimbursement::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }

    private function decided(HrReimbursement $claim, string $message)
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => [
                'claim'  => $claim,
                'thread' => $this->thread->forSubject($claim, asEmployee: false),
            ],
        ]);
    }
}
