<?php

namespace App\Services\Hr;

use App\Models\Hr\HrEmployeeLoan;
use App\Models\Hr\HrLoanInstallment;
use App\Models\Hr\HrPayrollRecord;

/**
 * The bridge between loans and payroll.
 *
 * Kept out of both PayrollService and LoanService on purpose: payroll should not
 * know how a schedule is built, and the loan module should not know how a payroll
 * run is assembled. This is the only place that knows both.
 *
 * A tenant with no loans gets an empty array from every method here, so payroll
 * behaves exactly as it did before this module existed.
 */
class LoanDeductionService
{
    public function __construct(private LoanService $loans)
    {
    }

    /**
     * Instalments due for one employee in one period.
     *
     * Only PENDING instalments of a DISBURSED loan qualify. Anything already
     * deducted, waived or skipped is not due again, and an approved-but-undisbursed
     * loan has handed the employee nothing to repay.
     *
     * Earlier unpaid periods are included: if March's payroll was never run, the
     * March instalment is still owed and appears in April rather than vanishing.
     */
    public function dueFor(int $employeeId, int $tenantId, string $period): array
    {
        return HrLoanInstallment::where('hr_loan_installments.tenant_id', $tenantId)
            ->where('status', HrLoanInstallment::PENDING)
            ->where('period', '<=', $period)
            ->whereHas('loan', fn ($q) => $q
                ->where('employee_id', $employeeId)
                ->where('status', HrEmployeeLoan::DISBURSED))
            ->with('loan.loanType:id,name,is_advance')
            ->orderBy('period')->orderBy('sequence')
            ->get()->all();
    }

    /**
     * Payroll record lines for this employee's due instalments.
     *
     * Shaped like the statutory lines so PayrollService can append them without
     * knowing what they are — `source` is 'loan', which is what tells the payslip
     * and the breakup UI to label them.
     */
    public function linesFor(int $employeeId, int $tenantId, string $period): array
    {
        $lines = [];

        foreach ($this->dueFor($employeeId, $tenantId, $period) as $i => $installment) {
            $loan = $installment->loan;
            $label = $loan?->loanType?->name ?? 'Loan';
            // An instalment carried over from an earlier month must say so, or it
            // reads as a double deduction on the payslip.
            $suffix = $installment->period < $period ? " (arrear {$installment->period})" : '';

            $lines[] = [
                'code'       => 'LOAN_'.$installment->loan_id,
                'name'       => "{$label} instalment {$installment->sequence}/{$loan?->tenure_months}{$suffix}",
                'type'       => 'Deduction',
                'source'     => 'loan',
                'amount'     => (float) $installment->amount,
                'sort_order' => 950 + $i,
            ];
        }

        return $lines;
    }

    public function totalFor(int $employeeId, int $tenantId, string $period): float
    {
        return round(array_sum(array_map(
            fn ($i) => (float) $i->amount,
            $this->dueFor($employeeId, $tenantId, $period)
        )), 2);
    }

    /**
     * Mark the instalments this record collected.
     *
     * Called AFTER the payroll record exists so `payroll_record_id` can point at
     * it — that link is what makes "was this deducted, and by which run?"
     * answerable without inferring from dates.
     */
    public function markDeducted(HrPayrollRecord $record, int $tenantId, string $period): float
    {
        $due = $this->dueFor((int) $record->employee_id, $tenantId, $period);
        if ($due === []) {
            return 0.0;
        }

        $total = 0.0;
        $loans = [];

        foreach ($due as $installment) {
            $installment->update([
                'status'            => HrLoanInstallment::DEDUCTED,
                'payroll_record_id' => $record->id,
                'deducted_amount'   => $installment->amount,
                'deducted_on'       => now()->toDateString(),
            ]);
            $total += (float) $installment->amount;
            $loans[$installment->loan_id] = $installment->loan;
        }

        // Recompute each affected loan once, not once per instalment.
        foreach ($loans as $loan) {
            if ($loan) {
                $this->loans->refreshOutstanding($loan->fresh());
            }
        }

        return round($total, 2);
    }

    /**
     * Undo the deductions a record made.
     *
     * Reprocessing a Draft run deletes and rewrites its records; without this the
     * instalments would stay marked Deducted against a record that no longer
     * exists, and would never be collected again.
     */
    public function releaseForRun(int $runId, int $tenantId): void
    {
        $recordIds = HrPayrollRecord::where('tenant_id', $tenantId)
            ->where('payroll_run_id', $runId)->pluck('id');

        if ($recordIds->isEmpty()) {
            return;
        }

        $installments = HrLoanInstallment::where('tenant_id', $tenantId)
            ->whereIn('payroll_record_id', $recordIds)->with('loan')->get();

        if ($installments->isEmpty()) {
            return;
        }

        $loans = [];
        foreach ($installments as $installment) {
            $installment->update([
                'status' => HrLoanInstallment::PENDING,
                'payroll_record_id' => null, 'deducted_amount' => null, 'deducted_on' => null,
            ]);
            $loans[$installment->loan_id] = $installment->loan;
        }

        foreach ($loans as $loan) {
            if ($loan) {
                $this->loans->refreshOutstanding($loan->fresh());
            }
        }
    }
}
