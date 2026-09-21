<?php

namespace App\Http\Controllers\Api\Hr;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Hr\HrApprovalAction;
use App\Models\Hr\HrPayrollAdjustment;
use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrPayrollRun;
use App\Services\Hr\Approval\ApprovalEngine;
use App\Services\Hr\Payroll\PayrollRunWorkflow;
use App\Services\Hr\PayrollService;
use App\Support\Hr\Approval\ApprovalProcess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The stepped payroll run: Pre-check → Inputs → Calculate → Approve → Disburse.
 *
 * Thin by design — validate, resolve the run for THIS tenant, delegate. Every
 * stage guard lives in PayrollRunWorkflow so the rules hold whether a stage is
 * reached from this controller, a console command or a test.
 *
 * Tenant resolution is done by loading through the tenant column rather than by
 * id alone. A payroll run id is a small integer and guessable, and the records
 * behind it are salaries.
 */
class PayrollWorkflowController extends Controller
{
    public function __construct(
        private PayrollRunWorkflow $workflow,
        private PayrollService $payroll,
        private ApprovalEngine $engine,
    ) {
    }

    /* ── Stage 1: Pre-check ───────────────────────────────────────────── */

    public function precheck(Request $request, int $id)
    {
        $run = $this->run($request, $id);

        return response()->json(['data' => $this->workflow->precheck($run, $this->tenant($request))]);
    }

    public function selectEmployees(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $run = $this->run($request, $id);

        $data = $request->validate([
            'employee_ids'   => 'required|array|min:1',
            'employee_ids.*' => 'integer',
        ]);

        return response()->json([
            'data' => $this->workflow->selectEmployees(
                $run, $data['employee_ids'], $this->tenant($request), $request->user()
            ),
        ]);
    }

    /* ── Stage 2: Inputs ──────────────────────────────────────────────── */

    public function confirmInputs(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $run = $this->run($request, $id);

        $this->workflow->confirmInputs($run, $request->user());

        return response()->json($this->payroll->showRun($id, $this->tenant($request)));
    }

    /* ── Stage 3: Adjustments ─────────────────────────────────────────── */

    public function adjustments(Request $request, int $id)
    {
        $run = $this->run($request, $id);

        return response()->json(['data' => $this->workflow->adjustmentsFor($run)]);
    }

    public function addAdjustment(Request $request, int $recordId)
    {
        $this->assertCanManage($request);
        $record = $this->record($request, $recordId);

        $data = $request->validate([
            'type'   => ['required', Rule::in(HrPayrollAdjustment::TYPES)],
            'amount' => 'required|numeric|min:0.01',
            // Required at every layer — the column, the service and here. An
            // adjustment with no reason is indistinguishable from an error the
            // moment the person who entered it has moved on.
            'reason' => 'required|string|max:500',
            'attachment_path' => 'nullable|string|max:2048',
        ]);

        return response()->json([
            'data' => $this->workflow->adjust($record, $data, $this->tenant($request), $request->user()),
        ], 201);
    }

    public function removeAdjustment(Request $request, int $adjustmentId)
    {
        $this->assertCanManage($request);

        $adjustment = HrPayrollAdjustment::where('tenant_id', $this->tenant($request))
            ->find($adjustmentId);

        if (! $adjustment) {
            throw new BusinessException('Adjustment not found', 404);
        }

        $this->workflow->removeAdjustment($adjustment, $request->user());

        return response()->json(['message' => 'Adjustment removed']);
    }

    /* ── Stage 4: Approval ────────────────────────────────────────────── */

    /**
     * Approve — through the configured ladder.
     *
     * Only approve and reject are decisions. Calculation, disbursement and
     * marking somebody paid stay off the ladder: the first happens before
     * anyone signs, and the last two are what happens to a run that has
     * ALREADY been signed.
     *
     * The safety that matters is the stage. PayrollRunWorkflow::approve() is
     * what moves the run to Disburse, and markPayment() refuses a run that is
     * not there. An intermediate rung does not call it, so the run stays at
     * Approve: nothing is payable, no payslip is released, and adjust() stays
     * open because nobody has put their name to the numbers yet.
     */
    public function approve(Request $request, int $id)
    {
        $this->assertCanApprove($request);
        $run = $this->run($request, $id);

        $data = $request->validate(['note' => 'nullable|string|max:1000']);

        return $this->decide($request, $run, HrApprovalAction::APPROVED, $data['note'] ?? null);
    }

    public function reject(Request $request, int $id)
    {
        $this->assertCanApprove($request);
        $run = $this->run($request, $id);

        // Required, unlike the approval note. A rejection with no reason gets
        // re-submitted unchanged and the loop repeats.
        $data = $request->validate(['note' => 'required|string|max:1000']);

        return $this->decide($request, $run, HrApprovalAction::REJECTED, $data['note']);
    }

    /**
     * One decision, through the engine, for both verbs.
     *
     * PAYROLL IS THE FIRST PROCESS WITH NO EMPLOYEE. A run covers everybody in
     * it, so there is no single person to scope against: ScopeResolver is asked
     * with a null employee and correctly answers "nothing to check". The three
     * gates stay separate, but for a tenant-level object gate 2 has nothing to
     * constrain, and the capability and the ladder do the work. Saying so out
     * loud is better than passing an arbitrary employee id to make the shape
     * look uniform.
     *
     * REJECTION IS NOT TERMINAL HERE, unlike every other migrated process. A
     * rejected run goes back to Calculate for rework and can be approved later,
     * so the rejection closes THIS round and a later approval opens a fresh one
     * — the repeat-round behaviour added for variable earnings, generalising
     * again.
     */
    private function decide(Request $request, HrPayrollRun $run, string $action, ?string $note)
    {
        $tenantId = $this->tenant($request);
        $actor    = $request->user();

        $approval = $this->engine->requestFor(
            $run,
            ApprovalProcess::PAYROLL_RUN,
            $tenantId,
            null,                       // a run belongs to no one employee
            (float) $run->total_payable,
        );

        /*
         | Already signed — the workflow's business, not the ladder's.
         |
         | approve() and reject() each have their own message for an approved
         | run, and they are the useful ones. Passed straight through.
         */
        if ($run->isApproved()) {
            $this->engine->supersede($approval);

            return $this->runDomainAction($run, $tenantId, $actor, $action, $note);
        }

        $inspection = $this->engine->inspect($approval);
        if (! $inspection['resolvable']) {
            $this->engine->block($approval, $inspection['describe']);
            abort(409, 'This payroll run cannot be decided yet: '.$inspection['describe'].'.');
        }

        $this->engine->assertMayDecide($approval, $actor);

        /*
         | Segregation of duties, at EVERY rung.
         |
         | PayrollRunWorkflow::approve() already refuses the person who
         | processed the run when the setting is on, but it is only reached on
         | the final rung. Without this the processor could sign an
         | intermediate step of their own run — which is the thing the control
         | exists to prevent — and the refusal must come before the rung is
         | consumed so it leaves no approval action behind.
         */
        if ($action === HrApprovalAction::APPROVED) {
            $this->assertSeparateApprover($run, $tenantId, $actor);
        }

        $result = $this->engine->decide($approval, $actor, $action, $note);

        if (! $result['final']) {
            // Still climbing. The stage is untouched, so nothing is payable.
            return response()->json(
                $this->payroll->showRun($run->id, $tenantId)
                + ['approval' => $this->approvalPayload($result['request'])]
            );
        }

        return $this->runDomainAction($run, $tenantId, $actor, $action, $note, $result['request']);
    }

    /** The existing workflow call, unchanged. */
    private function runDomainAction(HrPayrollRun $run, int $tenantId, $actor, string $action, ?string $note, $approval = null)
    {
        $action === HrApprovalAction::APPROVED
            ? $this->workflow->approve($run, $tenantId, $actor, $note)
            : $this->workflow->reject($run, (string) $note, $actor);

        $payload = $this->payroll->showRun($run->id, $tenantId);

        return response()->json(
            $approval ? $payload + ['approval' => $this->approvalPayload($approval)] : $payload
        );
    }

    /**
     * Mirrors the check inside PayrollRunWorkflow::approve().
     *
     * Same setting, same comparison, same message — read from the same place
     * rather than reimplemented with a different rule, so the two cannot drift.
     */
    private function assertSeparateApprover(HrPayrollRun $run, int $tenantId, $actor): void
    {
        $separate = (bool) app(\App\Services\Settings\SettingsService::class)
            ->get($tenantId, 'payroll', 'require_separate_approver', false);

        if ($separate && $actor && $run->processed_by && (int) $run->processed_by === (int) $actor->id) {
            abort(422, 'Payroll must be approved by someone other than the person who processed it.');
        }
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

    /* ── Stage 5: Disbursement ────────────────────────────────────────── */

    public function markPayment(Request $request, int $recordId)
    {
        $this->assertCanManage($request);
        $record = $this->record($request, $recordId);

        $data = $request->validate([
            'payment_status' => ['required', Rule::in(HrPayrollRecord::PAYMENT_STATUSES)],
            'note'           => 'nullable|string|max:500',
        ]);

        $this->workflow->markPayment($record, $data['payment_status'], $data['note'] ?? null, $request->user());

        return response()->json(['message' => 'Payment status updated']);
    }

    public function markAllPayments(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $run = $this->run($request, $id);

        $data = $request->validate([
            'payment_status' => ['required', Rule::in(HrPayrollRecord::PAYMENT_STATUSES)],
        ]);

        $result = $this->workflow->markAllPayments($run, $data['payment_status'], $request->user());

        // The counts travel with the run so the screen can say what it actually
        // did — a blanket "all transfers marked Paid" is untrue the moment
        // anything was left alone, and that is exactly when it matters.
        return response()->json(array_merge(
            $this->payroll->showRun($id, $this->tenant($request)),
            ['changed' => $result['changed'], 'skipped' => $result['skipped']],
        ));
    }

    public function releasePayslips(Request $request, int $id)
    {
        $this->assertCanManage($request);
        $run = $this->run($request, $id);

        $data = $request->validate(['visible' => 'required|boolean']);

        $count = $this->workflow->releasePayslips($run, (bool) $data['visible'], $request->user());

        return response()->json([
            'message' => $data['visible'] ? 'Payslips released' : 'Payslips hidden',
            'records' => $count,
        ]);
    }

    public function setPayslipVisibility(Request $request, int $recordId)
    {
        $this->assertCanManage($request);
        $record = $this->record($request, $recordId);

        $data = $request->validate(['visible' => 'required|boolean']);

        $this->workflow->setPayslipVisibility($record, (bool) $data['visible'], $request->user());

        return response()->json(['message' => 'Payslip visibility updated']);
    }

    /* ── Helpers ──────────────────────────────────────────────────────── */

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function run(Request $request, int $id): HrPayrollRun
    {
        $run = HrPayrollRun::where('tenant_id', $this->tenant($request))->find($id);

        if (! $run) {
            throw new BusinessException('Payroll run not found', 404);
        }

        return $run;
    }

    private function record(Request $request, int $id): HrPayrollRecord
    {
        $record = HrPayrollRecord::where('tenant_id', $this->tenant($request))
            ->with('run')
            ->find($id);

        if (! $record) {
            throw new BusinessException('Payroll record not found', 404);
        }

        return $record;
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage payroll');
    }

    /**
     * Approval uses the same permission as management for now.
     *
     * The SEGREGATION that matters — the approver not being the person who
     * processed the run — is enforced in the workflow against the actual actor,
     * which is the check that cannot be satisfied by handing somebody a role.
     */
    private function assertCanApprove(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to approve payroll');
    }
}
