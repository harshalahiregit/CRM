<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrApprovalAction;
use App\Models\Hr\HrEmployeeLoan;
use App\Services\Hr\Approval\ApprovalEngine;
use App\Services\Hr\LoanEligibilityService;
use App\Services\Hr\LoanService;
use App\Support\Hr\Approval\ApprovalProcess;
use Illuminate\Http\Request;

/**
 * Employee Loan & Salary Advance. Thin: validate, delegate, return JSON.
 *
 * Approve / reject / disburse / close all require HR-queue management — each moves
 * money or commits the company to a deduction from someone's pay.
 */
class LoanController extends Controller
{
    public function __construct(
        private LoanService $service,
        private LoanEligibilityService $eligibility,
        private ApprovalEngine $engine,
    ) {
    }

    public function meta(Request $request)
    {
        return response()->json([
            'statuses' => HrEmployeeLoan::STATUSES,
            'eligibility_limits' => $this->eligibility->limits($this->tenant($request)),
        ]);
    }

    /**
     * Affordability for a proposed EMI, before anything is saved.
     *
     * Read-only, so no permission gate — the UI calls it as figures are typed to
     * show the warning before a submit is rejected. Read-only is not the same as
     * harmless, though: the answer carries the employee's net_salary and their
     * existing EMI commitments, for an employee id named in the request body.
     * So the data scope applies even where the permission gate does not.
     */
    public function checkEligibility(Request $request)
    {
        $data = $request->validate([
            'employee_id'     => 'required|integer',
            'emi'             => 'required|numeric|min:0',
            'exclude_loan_id' => 'nullable|integer',
        ]);

        $this->assertEmployeeInScope($request, (int) $data['employee_id']);

        return response()->json($this->eligibility->evaluate(
            (int) $data['employee_id'], $this->tenant($request),
            (float) $data['emi'], (int) ($data['exclude_loan_id'] ?? 0),
        ));
    }

    /* ── Loan types ───────────────────────────────────────────────────── */

    public function types(Request $request)
    {
        return response()->json([
            'data' => $this->service->types($this->tenant($request), $request->only(['is_advance', 'is_active'])),
        ]);
    }

    public function saveType(Request $request, ?int $id = null)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'name'              => ($id ? 'sometimes|' : '').'required|string|max:150',
            'code'              => 'nullable|string|max:40',
            'is_advance'        => 'nullable|boolean',
            'max_amount'        => 'nullable|numeric|min:0',
            'max_tenure_months' => 'nullable|integer|min:1|max:600',
            'interest_rate'     => 'nullable|numeric|min:0|max:100',
            'requires_approval' => 'nullable|boolean',
            'description'       => 'nullable|string|max:1000',
            'is_active'         => 'nullable|boolean',
        ]);

        return response()->json(
            $this->service->saveType($id, $data, $this->tenant($request), $request->user()),
            $id ? 200 : 201
        );
    }

    public function destroyType(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $this->service->deleteType($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    /* ── Loans ────────────────────────────────────────────────────────── */

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->list($this->tenant($request),
                $request->only(['status', 'employee_id', 'loan_type_id', 'is_advance']),
                $request->user()),
        ]);
    }

    public function stats(Request $request)
    {
        return response()->json($this->service->stats($this->tenant($request), $request->user()));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request), $request->user()));
    }

    /**
     * HR-only: this lends company money to an arbitrary employee_id.
     *
     * Gated like approve() and reject() below. It was not, and combined with
     * submit() that was an escalation rather than just an oversight — see the
     * note there.
     */
    public function save(Request $request, ?int $id = null)
    {
        $this->assertCanManage($request);

        $data = $request->validate([
            'employee_id'   => 'required|integer',
            'loan_type_id'  => 'required|integer',
            'principal'     => 'required|numeric|min:1',
            'tenure_months' => 'nullable|integer|min:1|max:600',
            'interest_rate' => 'nullable|numeric|min:0|max:100',
            'start_period'  => 'nullable|string|regex:/^\d{4}-\d{2}$/',
            'purpose'       => 'nullable|string|max:500',
            'remarks'       => 'nullable|string|max:1000',
        ]);

        return response()->json(
            $this->service->save($id, $data, $this->tenant($request), $request->user()),
            $id ? 200 : 201
        );
    }

    /** Amortisation preview — no side effects, so no permission gate. */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'principal'     => 'required|numeric|min:1',
            'tenure_months' => 'required|integer|min:1|max:600',
            'interest_rate' => 'nullable|numeric|min:0|max:100',
            'start_period'  => 'nullable|string|regex:/^\d{4}-\d{2}$/',
        ]);

        return response()->json($this->service->previewSchedule(
            (float) $data['principal'],
            (float) ($data['interest_rate'] ?? 0),
            (int) $data['tenure_months'],
            $data['start_period'] ?? null,
        ));
    }

    /**
     * HR-only, and this one closed a self-approval route, not just a gap.
     *
     * A loan type with requires_approval = false is moved straight to Approved by
     * submit(). Ungated, that meant two calls — save() then submit() — put a loan
     * in Approved without the gated approve() endpoint ever being touched.
     * Reproduced before this change: an Employee-role account booked ₹50,000
     * against another employee and had it Approved.
     */
    public function submit(Request $request, int $id)
    {
        $this->assertCanManage($request);

        return response()->json($this->service->submit($id, $this->tenant($request), $request->user()));
    }

    /**
     * Approve — through the configured ladder.
     *
     * Only approve and reject go through the engine. submit(), disburse(),
     * close() and cancel() are not approvals: submit moves a draft into the
     * queue, and the other three are what happens to a loan that has ALREADY
     * been approved. Routing them through a ladder would ask for a second
     * approval of a decision already taken.
     */
    public function approve(Request $request, int $id)
    {
        $this->assertCanManage($request);

        return $this->decide($request, $id, HrApprovalAction::APPROVED, null);
    }

    public function reject(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $data = $request->validate(['remarks' => 'required|string|max:1000']);

        return $this->decide($request, $id, HrApprovalAction::REJECTED, $data['remarks']);
    }

    /**
     * One decision, through the engine, for both verbs.
     *
     * The three gates stay separate: assertCanManage() above is the capability,
     * the engine re-asserts data scope against the loan's employee, and the
     * ladder decides whether this request is waiting on this person. Only the
     * last rung calls LoanService, which still owns the status transition, the
     * audit line and every existing guard on them.
     */
    private function decide(Request $request, int $id, string $action, ?string $remarks)
    {
        $tenantId = $this->tenant($request);
        $actor    = $request->user();

        // Scoped read, so an out-of-scope loan is absent here exactly as it is
        // inside LoanService::find() and the ladder never sees it.
        $loan = $this->service->findForDecision($id, $tenantId, $actor);
        abort_unless($loan, 404, 'Loan not found');

        $approval = $this->engine->requestFor(
            $loan,
            ApprovalProcess::LOAN,
            $tenantId,
            (int) $loan->employee_id,
            (float) $loan->principal,
        );

        /*
         | Anything other than Submitted is not the ladder's business.
         |
         | That includes the auto-approved path: a loan type with
         | requires_approval = false moves straight from Draft to Approved on
         | submit. It is existing type-level configuration meaning "this needs
         | no approval", so the engine closes its view rather than demanding
         | one, and the service raises its own message unchanged.
         */
        if ($loan->status !== HrEmployeeLoan::SUBMITTED) {
            $this->engine->supersede($approval);

            return response()->json(
                $action === HrApprovalAction::APPROVED
                    ? $this->service->approve($id, $tenantId, $actor)
                    : $this->service->reject($id, (string) $remarks, $tenantId, $actor)
            );
        }

        $inspection = $this->engine->inspect($approval);
        if (! $inspection['resolvable']) {
            $this->engine->block($approval, $inspection['describe']);
            abort(409, 'This loan cannot be approved yet: '.$inspection['describe'].'.');
        }

        $this->engine->assertMayDecide($approval, $actor);

        $result = $this->engine->decide($approval, $actor, $action, $remarks);

        if (! $result['final']) {
            // Still climbing. The loan stays Submitted and no money moves.
            return response()->json([
                'approval' => $this->approvalPayload($result['request']),
                'data'     => $this->service->show($id, $tenantId, $actor),
            ]);
        }

        $payload = $action === HrApprovalAction::APPROVED
            ? $this->service->approve($id, $tenantId, $actor)
            : $this->service->reject($id, (string) $remarks, $tenantId, $actor);

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

    public function disburse(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'disbursed_on' => 'nullable|date',
            'start_period' => 'nullable|string|regex:/^\d{4}-\d{2}$/',
        ]);

        return response()->json($this->service->disburse($id, $data, $this->tenant($request), $request->user()));
    }

    public function close(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $data = $request->validate(['remarks' => 'required|string|max:1000']);

        return response()->json($this->service->close($id, $data['remarks'], $this->tenant($request), $request->user()));
    }

    /** HR-only, like close() above: cancelling somebody's approved loan is a decision. */
    public function cancel(Request $request, int $id)
    {
        $this->assertCanManage($request);

        return response()->json($this->service->cancel($id, $this->tenant($request), $request->user()));
    }

    public function waiveInstallment(Request $request, int $id, int $installmentId)
    {
        $this->assertCanManage($request);
        $data = $request->validate(['remarks' => 'required|string|max:500']);

        return response()->json($this->service->waiveInstallment(
            $id, $installmentId, $data['remarks'], $this->tenant($request), $request->user()
        ));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage loans');
    }

    /**
     * Whose employee is this?
     *
     * Separate from assertCanManage() above on purpose: that asks whether they
     * may touch loans at all, this asks whose. The loan actions reached by id
     * are scoped inside LoanService::find(); this is for the endpoints that take
     * an employee id directly instead.
     */
    private function assertEmployeeInScope(Request $request, int $employeeId): void
    {
        app(\App\Services\Auth\ScopeResolver::class)
            ->assertCanActOnEmployee($request->user(), $employeeId);
    }
}
