<?php

namespace App\Services\Hr\Payroll;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrPayrollAdjustment;
use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrPayrollRun;
use App\Models\Hr\HrPayrollRunEmployee;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * The approval chain around a payroll run.
 *
 * Computing salaries and AGREEING to pay them are different acts by different
 * people, and this file owns the second one. PayrollService still does the
 * arithmetic; nothing here recalculates a figure.
 *
 *     Pre-check  → HR chooses who is in the run, and sees who cannot be paid
 *     Inputs     → attendance, leave, variable earnings settle
 *     Calculate  → the engine runs; HR may add or deduct WITH A REASON
 *     Approve    → the reporting manager signs, and the run locks
 *     Disburse   → accounts marks each person paid, and payslips are released
 *
 * ── Why a stage cannot be skipped ──
 *
 * Every guard here refuses forward motion rather than correcting it. A run that
 * reaches Disburse without an approver has lost the only evidence that anybody
 * agreed to the amounts, and there is no way to reconstruct it afterwards. So
 * `approve()` refuses a run that has not been calculated, `disburse()` refuses
 * one that has not been approved, and `adjust()` refuses one that has — because
 * an adjustment made after sign-off is a change to a number somebody already
 * put their name to.
 */
class PayrollRunWorkflow
{
    public function __construct(
        private PayrollEligibilityService $eligibility,
        private SettingsService $settings,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Stage 1 — Pre-check
    |--------------------------------------------------------------------------
    */

    /** Who could be paid, who could not, and who is currently selected. */
    public function precheck(HrPayrollRun $run, int $tenantId): array
    {
        $assessment = $this->eligibility->assess($tenantId);
        $selected   = $run->selections()->pluck('employee_id')->all();

        // A run with no explicit selection yet defaults to "everybody who can be
        // paid". That keeps the one-click path for the common month while still
        // recording the selection, so the run always knows who was in it.
        $touched = $run->selections()->exists();

        return [
            'stage'      => $run->stage,
            'summary'    => $this->eligibility->summarise($assessment),
            'employees'  => array_map(function ($a) use ($selected, $touched) {
                $a['selected'] = $touched
                    ? in_array($a['employee_id'], $selected, true)
                    : ! $a['blocked'];

                return $a;
            }, $assessment),
        ];
    }

    /**
     * Record which employees are in this run.
     *
     * Blocked employees are written too, carrying their reason. Leaving them out
     * entirely would make "not selected" and "could not be paid" the same row —
     * which is exactly the ambiguity the pre-check exists to remove.
     */
    public function selectEmployees(HrPayrollRun $run, array $employeeIds, int $tenantId, ?User $actor = null): array
    {
        $this->assertNotLocked($run);

        $assessment = collect($this->eligibility->assess($tenantId))->keyBy('employee_id');
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));

        if ($ids === []) {
            throw new BusinessException('Select at least one employee to run payroll for.');
        }

        DB::transaction(function () use ($run, $ids, $assessment, $tenantId) {
            $run->selections()->delete();

            foreach ($ids as $employeeId) {
                $a = $assessment->get($employeeId);
                if (! $a) {
                    continue;   // not an active employee of this tenant — ignore
                }

                HrPayrollRunEmployee::create([
                    'tenant_id'      => $tenantId,
                    'payroll_run_id' => $run->id,
                    'employee_id'    => $employeeId,
                    'blocked_reason' => $a['blocked_reason'],
                ]);
            }

            $run->update(['stage' => HrPayrollRun::STAGE_INPUTS]);
        });

        $payable = $run->selections()->whereNull('blocked_reason')->count();
        $run->recordAudit('Payroll Employees Selected', $actor, null, [
            'selected' => count($ids),
            'payable'  => $payable,
            'blocked'  => count($ids) - $payable,
        ]);

        return $this->precheck($run->fresh(), $tenantId);
    }

    /** Move Inputs → Calculate. Purely a stage marker; nothing is computed here. */
    public function confirmInputs(HrPayrollRun $run, ?User $actor = null): void
    {
        $this->assertNotLocked($run);

        if (! $run->selections()->exists()) {
            throw new BusinessException('Select the employees for this run before continuing.');
        }

        $run->update(['stage' => HrPayrollRun::STAGE_CALCULATE]);
        $run->recordAudit('Payroll Inputs Confirmed', $actor);
    }

    /*
    |--------------------------------------------------------------------------
    | Stage 3 — HR adjustments
    |--------------------------------------------------------------------------
    */

    /**
     * Add money to, or take money off, one person's pay for this month only.
     *
     * The reason is mandatory at the database level and validated again here,
     * because an adjustment without one is indistinguishable from a mistake by
     * the time anybody queries it.
     */
    public function adjust(HrPayrollRecord $record, array $data, int $tenantId, ?User $actor = null): array
    {
        $run = $record->run;
        $this->assertNotLocked($run, 'Payroll has been approved — adjustments can no longer be made.');

        $type = $data['type'] ?? HrPayrollAdjustment::ADDITION;
        if (! in_array($type, HrPayrollAdjustment::TYPES, true)) {
            throw new BusinessException('An adjustment must be an Addition or a Deduction.');
        }

        $amount = round(abs((float) ($data['amount'] ?? 0)), 2);
        if ($amount <= 0) {
            throw new BusinessException('An adjustment needs an amount greater than zero.');
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new BusinessException('An adjustment needs a reason.');
        }

        $adjustment = HrPayrollAdjustment::create([
            'tenant_id'         => $tenantId,
            'payroll_run_id'    => $run->id,
            'payroll_record_id' => $record->id,
            'employee_id'       => $record->employee_id,
            'type'              => $type,
            'amount'            => $amount,
            'reason'            => $reason,
            'attachment_path'   => $data['attachment_path'] ?? null,
            'created_by'        => $actor?->id,
        ]);

        $this->recomputeRecord($record);
        $this->recomputeRunTotals($run);

        $run->recordAudit('Payroll Adjustment Added', $actor, null, [
            'employee_id' => $record->employee_id,
            'type'        => $type,
            'amount'      => $amount,
            'reason'      => $reason,
        ]);

        return $this->presentAdjustment($adjustment->fresh());
    }

    public function removeAdjustment(HrPayrollAdjustment $adjustment, ?User $actor = null): void
    {
        $run = $adjustment->record?->run;
        if ($run) {
            $this->assertNotLocked($run, 'Payroll has been approved — adjustments can no longer be removed.');
        }

        $record = $adjustment->record;
        $snapshot = [
            'employee_id' => $adjustment->employee_id,
            'type'        => $adjustment->type,
            'amount'      => (float) $adjustment->amount,
        ];

        $adjustment->delete();

        if ($record) {
            $this->recomputeRecord($record->fresh());
            $this->recomputeRunTotals($run);
        }
        $run?->recordAudit('Payroll Adjustment Removed', $actor, null, $snapshot);
    }

    /** Every adjustment on a run, newest first, for the Calculate screen. */
    public function adjustmentsFor(HrPayrollRun $run): array
    {
        return $run->adjustments()
            ->with(['employee:id,name,employee_code', 'createdBy:id,name'])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($a) => $this->presentAdjustment($a))
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Stage 4 — Approval (level 2, the reporting manager)
    |--------------------------------------------------------------------------
    */

    public function approve(HrPayrollRun $run, int $tenantId, ?User $actor = null, ?string $note = null): HrPayrollRun
    {
        if ($run->status !== HrPayrollRun::COMPLETED) {
            throw new BusinessException('This run has not been calculated yet.');
        }
        if ($run->isApproved()) {
            throw new BusinessException('This payroll run is already approved.');
        }

        // Segregation of duties, off by default.
        //
        // Requiring a second person is the correct control and it is also the
        // control that stops a one-admin tenant from ever paying anybody. So it
        // is a setting rather than a rule: switched on where there are enough
        // people to satisfy it, and never silently blocking a tenant where
        // there are not.
        $separate = (bool) $this->settings->get($tenantId, 'payroll', 'require_separate_approver', false);
        if ($separate && $actor && $run->processed_by && (int) $run->processed_by === (int) $actor->id) {
            throw new BusinessException('Payroll must be approved by someone other than the person who processed it.');
        }

        $run->update([
            'stage'         => HrPayrollRun::STAGE_DISBURSE,
            'approved_by'   => $actor?->id,
            'approved_at'   => now(),
            'approval_note' => $note,
        ]);
        $run->recordAudit('Payroll Approved', $actor, null, ['note' => $note]);

        return $run->fresh();
    }

    /**
     * Send the run back to HR with a reason.
     *
     * The note is required. A rejection with no reason gets re-submitted
     * unchanged, and the loop repeats until somebody picks up the phone — which
     * is the failure mode this whole chain exists to avoid.
     */
    public function reject(HrPayrollRun $run, string $note, ?User $actor = null): HrPayrollRun
    {
        if ($run->isApproved()) {
            throw new BusinessException('This run is already approved and cannot be sent back.');
        }
        if (trim($note) === '') {
            throw new BusinessException('Say why the run is being sent back.');
        }

        $run->update([
            'stage'         => HrPayrollRun::STAGE_CALCULATE,
            'approval_note' => $note,
        ]);
        $run->recordAudit('Payroll Sent Back', $actor, null, ['note' => $note]);

        return $run->fresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Stage 5 — Disbursement (level 3, accounts)
    |--------------------------------------------------------------------------
    */

    /**
     * Accounts marks what actually happened to one person's transfer.
     *
     * Paid is not the default and is not inferred from the run being finished:
     * transfers fail individually, and "the run completed" is a statement about
     * arithmetic rather than about money arriving.
     */
    public function markPayment(HrPayrollRecord $record, string $status, ?string $note, ?User $actor = null): HrPayrollRecord
    {
        $run = $record->run;

        if (! $run?->isApproved()) {
            throw new BusinessException('Payroll must be approved before payments can be recorded.');
        }
        if (! in_array($status, HrPayrollRecord::PAYMENT_STATUSES, true)) {
            throw new BusinessException('Invalid payment status.');
        }

        $record->update([
            'payment_status' => $status,
            'paid_at'        => $status === HrPayrollRecord::PAY_PAID ? now() : null,
            'payment_note'   => $note,
        ]);

        $this->syncDisbursementStage($run, $actor);

        return $record->fresh();
    }

    /**
     * The same decision for every transfer still AWAITING one.
     *
     * Only pending records are touched. A Hold or a Failed is something Accounts
     * already decided about a specific person — the bank rejected the transfer,
     * or somebody withheld it deliberately — and a bulk action must not quietly
     * reverse that. It used to: an unqualified update over every record turned a
     * rejected transfer into a paid one, released the payslip saying so, and
     * closed the run, while the money had never left. The contradicting note
     * ("Bank rejected — IFSC mismatch") stayed attached to the row now marked
     * Paid, which is the only reason it would ever have been noticed.
     *
     * Re-paying a failed transfer is still possible, and deliberately one person
     * at a time: that is what the per-row buttons are for.
     *
     * @return array{run:HrPayrollRun, changed:int, skipped:array<string,int>}
     */
    public function markAllPayments(HrPayrollRun $run, string $status, ?User $actor = null): array
    {
        if (! $run->isApproved()) {
            throw new BusinessException('Payroll must be approved before payments can be recorded.');
        }
        if (! in_array($status, HrPayrollRecord::PAYMENT_STATUSES, true)) {
            throw new BusinessException('Invalid payment status.');
        }

        // Counted before the write, so the caller can say what it left alone
        // rather than reporting a blanket success that skipped half the run.
        $skipped = $run->records()
            ->where('payment_status', '!=', HrPayrollRecord::PAY_PENDING)
            ->where('payment_status', '!=', $status)
            ->selectRaw('payment_status, COUNT(*) as total')
            ->groupBy('payment_status')
            ->pluck('total', 'payment_status')
            ->map(fn ($n) => (int) $n)
            ->all();

        $changed = $run->records()
            ->where('payment_status', HrPayrollRecord::PAY_PENDING)
            ->update([
                'payment_status' => $status,
                'paid_at'        => $status === HrPayrollRecord::PAY_PAID ? now() : null,
            ]);

        $run->recordAudit('Payroll Payments Updated', $actor, null, [
            'status' => $status, 'changed' => $changed, 'skipped' => $skipped,
        ]);

        $this->syncDisbursementStage($run->fresh(), $actor);

        return ['run' => $run->fresh(), 'changed' => $changed, 'skipped' => $skipped];
    }

    /**
     * Release payslips to employees.
     *
     * Hidden by default, on purpose: a payslip visible before the transfer
     * clears produces a queue of questions HR cannot answer yet.
     */
    public function releasePayslips(HrPayrollRun $run, bool $visible, ?User $actor = null): int
    {
        if ($visible && ! $run->isApproved()) {
            throw new BusinessException('Payslips can only be released after the run is approved.');
        }

        $count = $run->records()->update(['payslip_visible' => $visible]);
        $run->recordAudit($visible ? 'Payslips Released' : 'Payslips Hidden', $actor, null, ['records' => $count]);

        return $count;
    }

    /** One person's payslip shown or hidden, without touching the rest. */
    public function setPayslipVisibility(HrPayrollRecord $record, bool $visible, ?User $actor = null): HrPayrollRecord
    {
        if ($visible && ! $record->run?->isApproved()) {
            throw new BusinessException('Payslips can only be released after the run is approved.');
        }

        $record->update(['payslip_visible' => $visible]);
        $record->run?->recordAudit(
            $visible ? 'Payslip Released' : 'Payslip Hidden',
            $actor, null, ['employee_id' => $record->employee_id]
        );

        return $record->fresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The run reaches Paid only when nothing is still Pending.
     *
     * Held and failed transfers do not hold the stage open — they are decisions
     * accounts has already recorded, and a run that can never reach Paid because
     * one person is on hold would leave every later month blocked behind it.
     */
    private function syncDisbursementStage(HrPayrollRun $run, ?User $actor = null): void
    {
        $pending = $run->records()->where('payment_status', HrPayrollRecord::PAY_PENDING)->count();

        $stage = $pending === 0 ? HrPayrollRun::STAGE_PAID : HrPayrollRun::STAGE_DISBURSE;

        if ($run->stage !== $stage) {
            $run->update([
                'stage'        => $stage,
                'disbursed_by' => $stage === HrPayrollRun::STAGE_PAID ? $actor?->id : null,
                'disbursed_at' => $stage === HrPayrollRun::STAGE_PAID ? now() : null,
            ]);

            if ($stage === HrPayrollRun::STAGE_PAID) {
                $run->recordAudit('Payroll Disbursed', $actor);
            }
        }
    }

    /** Re-cache one record's adjustment total from its rows. */
    private function recomputeRecord(HrPayrollRecord $record): void
    {
        $total = $record->adjustments()->get()->sum(fn ($a) => $a->signedAmount());

        $record->update(['adjustment_total' => round((float) $total, 2)]);
    }

    /**
     * Re-cache the run's bank total.
     *
     * `total_net` is left alone — it is the sum of the frozen structure figures
     * and existing consumers read it. `total_payable` is what the bank is asked
     * for, and the two differ by exactly the columns that live beside the
     * snapshot.
     */
    private function recomputeRunTotals(HrPayrollRun $run): void
    {
        $payable = $run->records()->get()->sum(fn (HrPayrollRecord $r) => $r->netPayable());

        $run->update(['total_payable' => round((float) $payable, 2)]);
    }

    private function assertNotLocked(HrPayrollRun $run, ?string $message = null): void
    {
        if ($run->isApproved()) {
            throw new BusinessException($message ?? 'This payroll run is approved and can no longer be changed.');
        }
        if ($run->status === HrPayrollRun::CANCELLED) {
            throw new BusinessException('This payroll run is cancelled.');
        }
    }

    private function presentAdjustment(HrPayrollAdjustment $a): array
    {
        return [
            'id'              => $a->id,
            'employee_id'     => $a->employee_id,
            'employee_name'   => $a->employee?->name,
            'employee_code'   => $a->employee?->employee_code,
            'type'            => $a->type,
            'amount'          => (float) $a->amount,
            'signed_amount'   => $a->signedAmount(),
            'reason'          => $a->reason,
            'attachment_path' => $a->attachment_path,
            'created_by'      => $a->createdBy?->name,
            'created_at'      => optional($a->created_at)->toIso8601String(),
        ];
    }
}
