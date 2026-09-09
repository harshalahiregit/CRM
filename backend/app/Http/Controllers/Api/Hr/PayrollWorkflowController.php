<?php

namespace App\Http\Controllers\Api\Hr;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Hr\HrPayrollAdjustment;
use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrPayrollRun;
use App\Services\Hr\Payroll\PayrollRunWorkflow;
use App\Services\Hr\PayrollService;
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

    public function approve(Request $request, int $id)
    {
        $this->assertCanApprove($request);
        $run = $this->run($request, $id);

        $data = $request->validate(['note' => 'nullable|string|max:1000']);

        $this->workflow->approve($run, $this->tenant($request), $request->user(), $data['note'] ?? null);

        return response()->json($this->payroll->showRun($id, $this->tenant($request)));
    }

    public function reject(Request $request, int $id)
    {
        $this->assertCanApprove($request);
        $run = $this->run($request, $id);

        // Required, unlike the approval note. A rejection with no reason gets
        // re-submitted unchanged and the loop repeats.
        $data = $request->validate(['note' => 'required|string|max:1000']);

        $this->workflow->reject($run, $data['note'], $request->user());

        return response()->json($this->payroll->showRun($id, $this->tenant($request)));
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
