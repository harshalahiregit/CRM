<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLoan;
use App\Models\Hr\HrLoanInstallment;
use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrPayrollRun;

/**
 * Review comment #38 — "Employee loan, advance, and sangoe track integration".
 *
 * The loan → payroll DEDUCTION already exists (LoanDeductionService writes the
 * instalment lines and stamps `payroll_record_id` on each one). What was missing
 * is the other half of the comment: seeing what payroll actually recovered.
 *
 * This service is READ-ONLY. It touches no payroll figure, writes nothing, and
 * adds no rule to a run — "do not break current payroll" means exactly that, so
 * the safe integration point is reporting, not arithmetic.
 *
 * THE SANGOETRACK CONNECTION is deliberately reported rather than acted on. A
 * loan instalment is collected by a payroll run, and that run's payable days come
 * from the AttendanceProvider — SangoeTrack once its provider is bound. So every
 * recovery here carries the attendance source of the run that collected it. What
 * this does NOT do is change a deduction based on attendance: "skip the EMI in a
 * month of unpaid leave" is a real policy question nobody has answered, and
 * inventing it would quietly alter what employees are charged.
 */
class LoanRecoveryService
{
    /** Recovery status for one loan: scheduled vs collected, and by which run. */
    public function forLoan(int $loanId, int $tenantId): array
    {
        $loan = HrEmployeeLoan::forTenant($tenantId)
            ->with(['employee:id,name,employee_code', 'loanType:id,name,is_advance', 'installments'])
            ->find($loanId);

        if (! $loan) {
            throw new BusinessException('Loan not found', 404);
        }

        // One query for every payroll record that collected an instalment of this
        // loan, so the per-instalment rows below need no N+1.
        $records = $this->recordsFor($loan->installments->pluck('payroll_record_id')->filter(), $tenantId);

        $installments = $loan->installments->map(function ($i) use ($records) {
            $record = $i->payroll_record_id ? ($records[$i->payroll_record_id] ?? null) : null;

            return [
                'id'             => $i->id,
                'sequence'       => (int) $i->sequence,
                'period'         => $i->period,
                'amount'         => (float) $i->amount,
                'status'         => $i->status,
                'deducted_amount' => $i->deducted_amount !== null ? (float) $i->deducted_amount : null,
                'deducted_on'    => optional($i->deducted_on)->toDateString(),
                // The audit trail the comment is really asking for: which run took it.
                'payroll_record_id' => $i->payroll_record_id,
                'payroll_run'    => $record ? [
                    'run_id'            => $record->payroll_run_id,
                    'period_label'      => $record->run?->payroll_month
                        ? sprintf('%04d-%02d', $record->run->payroll_year, $record->run->payroll_month) : null,
                    'run_status'        => $record->run?->status,
                    // SangoeTrack (or whichever provider) supplied this run's days.
                    'attendance_source' => $record->attendance_source,
                    'payable_days'      => $record->payable_days !== null ? (float) $record->payable_days : null,
                ] : null,
            ];
        })->values()->all();

        $collected = collect($installments)->where('status', HrLoanInstallment::DEDUCTED);
        $pending   = collect($installments)->where('status', HrLoanInstallment::PENDING);

        return [
            'loan' => [
                'id' => $loan->id, 'loan_number' => $loan->loan_number, 'status' => $loan->status,
                'employee_id' => $loan->employee_id, 'employee_name' => $loan->employee?->name,
                'loan_type' => $loan->loanType?->name, 'is_advance' => (bool) $loan->loanType?->is_advance,
                'principal' => (float) $loan->principal, 'total_payable' => (float) $loan->total_payable,
                'emi' => (float) $loan->emi,
            ],
            'recovery' => [
                'scheduled_total'   => round((float) collect($installments)->sum('amount'), 2),
                'collected_total'   => round((float) $collected->sum(fn ($i) => $i['deducted_amount'] ?? $i['amount']), 2),
                'outstanding_total' => round((float) $pending->sum('amount'), 2),
                'installments_total'     => count($installments),
                'installments_collected' => $collected->count(),
                'installments_pending'   => $pending->count(),
                'percent_recovered' => $loan->total_payable > 0
                    ? round((float) $loan->total_repaid / (float) $loan->total_payable * 100, 1) : 0.0,
                // Instalments whose period has passed but which payroll never took.
                'arrears' => $pending->filter(fn ($i) => $i['period'] < now()->format('Y-m'))->values()->all(),
            ],
            'installments' => $installments,
        ];
    }

    /**
     * #38 — one employee's loan position, for their profile.
     *
     * A summary, not the schedule: the profile answers "does this person owe
     * anything, and is it on track?", and the Loans screen answers the rest.
     */
    public function forEmployee(int $employeeId, int $tenantId): array
    {
        $loans = HrEmployeeLoan::forTenant($tenantId)
            ->where('employee_id', $employeeId)
            ->whereIn('status', [HrEmployeeLoan::DISBURSED, HrEmployeeLoan::CLOSED])
            ->with('loanType:id,name,is_advance')
            ->orderByDesc('id')->get();

        $active = $loans->where('status', HrEmployeeLoan::DISBURSED);
        $period = now()->format('Y-m');

        $arrears = $active->isEmpty() ? collect() : HrLoanInstallment::forTenant($tenantId)
            ->whereIn('loan_id', $active->pluck('id'))
            ->where('status', HrLoanInstallment::PENDING)
            ->where('period', '<', $period)->get();

        return [
            'has_loans'         => $loans->isNotEmpty(),
            'active_count'      => $active->count(),
            'closed_count'      => $loans->where('status', HrEmployeeLoan::CLOSED)->count(),
            'total_outstanding' => round((float) $active->sum('outstanding'), 2),
            'monthly_emi'       => round((float) $active->sum('emi'), 2),
            // The number that says "being repaid" vs "quietly not being repaid".
            'arrear_count'      => $arrears->count(),
            'arrear_amount'     => round((float) $arrears->sum('amount'), 2),
            'loans' => $loans->map(fn ($l) => [
                'id'          => $l->id,
                'loan_number' => $l->loan_number,
                'loan_type'   => $l->loanType?->name,
                'is_advance'  => (bool) $l->loanType?->is_advance,
                'status'      => $l->status,
                'principal'   => (float) $l->principal,
                'emi'         => (float) $l->emi,
                'total_repaid' => (float) $l->total_repaid,
                'outstanding' => (float) $l->outstanding,
                'percent_recovered' => $l->total_payable > 0
                    ? round((float) $l->total_repaid / (float) $l->total_payable * 100, 1) : 0.0,
            ])->values()->all(),
        ];
    }

    /** Every employee with an outstanding loan — the recovery queue. */
    public function outstanding(int $tenantId, array $filters = []): array
    {
        $q = HrEmployeeLoan::forTenant($tenantId)
            ->where('status', HrEmployeeLoan::DISBURSED)
            ->with(['employee:id,name,employee_code,department', 'loanType:id,name,is_advance']);

        if (! empty($filters['employee_id'])) {
            $q->where('employee_id', (int) $filters['employee_id']);
        }
        if (! empty($filters['department'])) {
            $q->whereHas('employee', fn ($e) => $e->where('department', $filters['department']));
        }

        $period = $filters['period'] ?? now()->format('Y-m');

        return $q->orderByDesc('outstanding')->get()->map(function ($loan) use ($tenantId, $period) {
            $arrears = HrLoanInstallment::forTenant($tenantId)
                ->where('loan_id', $loan->id)
                ->where('status', HrLoanInstallment::PENDING)
                ->where('period', '<', $period)->get();

            return [
                'loan_id'       => $loan->id,
                'loan_number'   => $loan->loan_number,
                'employee_id'   => $loan->employee_id,
                'employee_name' => $loan->employee?->name,
                'employee_code' => $loan->employee?->employee_code,
                'department'    => $loan->employee?->department,
                'loan_type'     => $loan->loanType?->name,
                'is_advance'    => (bool) $loan->loanType?->is_advance,
                'emi'           => (float) $loan->emi,
                'total_payable' => (float) $loan->total_payable,
                'total_repaid'  => (float) $loan->total_repaid,
                'outstanding'   => (float) $loan->outstanding,
                'percent_recovered' => $loan->total_payable > 0
                    ? round((float) $loan->total_repaid / (float) $loan->total_payable * 100, 1) : 0.0,
                // Missed periods are the actionable number on this screen.
                'arrear_count'  => $arrears->count(),
                'arrear_amount' => round((float) $arrears->sum('amount'), 2),
            ];
        })->all();
    }

    /**
     * What one payroll run recovered.
     *
     * Reads the frozen records, so a run's recovery figure never changes after the
     * fact — the same principle the rest of payroll follows.
     */
    public function forRun(int $runId, int $tenantId): array
    {
        $run = HrPayrollRun::where('tenant_id', $tenantId)->find($runId);
        if (! $run) {
            throw new BusinessException('Payroll run not found', 404);
        }

        $records = HrPayrollRecord::where('tenant_id', $tenantId)
            ->where('payroll_run_id', $runId)
            ->where('loan_deduction', '>', 0)
            ->with('employee:id,name,employee_code')
            ->get();

        $installments = HrLoanInstallment::forTenant($tenantId)
            ->whereIn('payroll_record_id', $records->pluck('id'))
            ->with('loan.loanType:id,name,is_advance')
            ->get()->groupBy('payroll_record_id');

        return [
            'run' => [
                'id' => $run->id,
                'period' => sprintf('%04d-%02d', $run->payroll_year, $run->payroll_month),
                'status' => $run->status,
                // Which attendance source underpinned the run that collected these.
                'attendance_source' => $records->first()?->attendance_source,
            ],
            'total_recovered' => round((float) $records->sum('loan_deduction'), 2),
            'employees_count' => $records->count(),
            'rows' => $records->map(fn ($r) => [
                'employee_id'   => $r->employee_id,
                'employee_name' => $r->employee?->name,
                'employee_code' => $r->employee?->employee_code,
                'loan_deduction' => (float) $r->loan_deduction,
                'net_payable'   => round((float) $r->net_salary - (float) $r->statutory_deductions - (float) $r->loan_deduction, 2),
                'installments'  => ($installments[$r->id] ?? collect())->map(fn ($i) => [
                    'loan_id'   => $i->loan_id,
                    'loan_type' => $i->loan->loanType?->name,
                    'sequence'  => (int) $i->sequence,
                    'period'    => $i->period,
                    'amount'    => (float) $i->amount,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** Payroll records keyed by id, with their run — one query, no N+1. */
    private function recordsFor($ids, int $tenantId)
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return HrPayrollRecord::where('tenant_id', $tenantId)
            ->whereIn('id', $ids->unique()->values())
            ->with('run:id,payroll_month,payroll_year,status')
            ->get()->keyBy('id');
    }
}
