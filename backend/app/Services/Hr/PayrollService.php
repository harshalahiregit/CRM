<?php

namespace App\Services\Hr;

use App\Contracts\Hr\AttendanceProvider;
use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrPayrollRun;
use App\Models\User;
use App\Models\Hr\HrPayrollRecordLine;
use App\Repositories\Hr\PayrollRunRepository;
use App\Services\Hr\Statutory\StatutoryEngine;
use App\Services\Settings\SettingsService;
use App\Support\Hr\WorkStates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Payroll Processing engine (Payroll Phase 4).
 *
 * Creates monthly runs and processes them into frozen per-employee snapshots,
 * pulling salary figures from the active Employee Salary (Phase 3) and attendance
 * REFERENCE from the AttendanceProvider boundary (placeholder until SangoeTrack).
 * No attendance storage/computation, no payslip/tax/bank logic. Completed runs are
 * finalized and immutable — future salary changes never touch processed payroll.
 */
class PayrollService
{
    public function __construct(
        private PayrollRunRepository $repo,
        private AttendanceProvider $attendance,
        private StatutoryEngine $statutory,
        private SalaryStructureService $structures,
        private SettingsService $settings,
        private LoanDeductionService $loans,
        private VariableEarningService $variableEarnings,
    ) {
    }

    /**
     * The statutory jurisdiction for one employee: their own work state, else the
     * company-wide default, else null.
     *
     * `location` is deliberately NOT consulted. It holds a city, and a city cannot
     * identify the state a tax is levied by — that was the old bug. An employee with
     * no work state gets no PT and a recorded reason, never a guess.
     */
    private function workStateFor(?HrEmployee $employee, int $tenantId): ?string
    {
        return WorkStates::normalize($employee?->work_state)
            ?? WorkStates::normalize($this->settings->get($tenantId, 'payroll', 'default_work_state'));
    }

    public function listRuns(int $tenantId, array $filters): array
    {
        return $this->repo->filtered($tenantId, $filters)->map(fn ($r) => $this->presentRun($r))->all();
    }

    public function createRun(array $data, int $tenantId, ?User $actor = null): array
    {
        $month = (int) $data['month'];
        $year  = (int) $data['year'];

        // Cannot process the same month twice.
        if ($this->repo->existsForMonth($tenantId, $year, $month)) {
            throw new BusinessException('A payroll run for '.$this->periodLabel($year, $month).' already exists.');
        }

        $run = HrPayrollRun::create([
            'tenant_id'     => $tenantId,
            'payroll_month' => $month,
            'payroll_year'  => $year,
            'status'        => HrPayrollRun::DRAFT,
            'created_by'    => $actor?->id,
        ]);
        $run->recordAudit('Payroll Run Created', $actor, null, ['period' => $this->period($year, $month)]);
        $this->log('Payroll run created', $tenantId, $run->id);

        return $this->presentRun($run);
    }

    public function showRun(int $id, int $tenantId): array
    {
        return $this->presentRun($this->find($id, $tenantId), withAttendanceMeta: true);
    }

    public function records(int $id, int $tenantId): array
    {
        $this->find($id, $tenantId); // tenant guard
        return $this->repo->recordsForRun($id, $tenantId)->map(fn ($r) => $this->presentRecord($r))->all();
    }

    /**
     * Process a run: build a frozen snapshot for every employee with an ACTIVE
     * salary, roll up the totals, and finalize the run as Completed.
     */
    public function process(int $id, int $tenantId, ?User $actor = null): array
    {
        $run = $this->find($id, $tenantId);

        if ($run->status === HrPayrollRun::COMPLETED) {
            throw new BusinessException('This payroll run is finalized and cannot be reprocessed.');
        }
        if ($run->status === HrPayrollRun::CANCELLED) {
            throw new BusinessException('This payroll run is cancelled.');
        }

        $salaries = HrEmployeeSalary::where('tenant_id', $tenantId)
            ->where('status', HrEmployeeSalary::ACTIVE)
            // `work_state` is loaded because Professional Tax is resolved per state;
            // without it the constrained eager load returns a null state and every
            // state-specific PT rule silently fails to match.
            ->with('employee:id,name,work_state')
            ->get();

        if ($salaries->isEmpty()) {
            throw new BusinessException('No employees have an active salary to process. Assign salaries first.');
        }

        $period = $this->period($run->payroll_year, $run->payroll_month);

        DB::transaction(function () use ($run, $salaries, $tenantId, $period, $actor) {
            $run->update(['status' => HrPayrollRun::PROCESSING]);
            $run->recordAudit('Payroll Started', $actor, null, ['period' => $period, 'employees' => $salaries->count()]);

            // Release any loan instalments this run collected before its records are
            // deleted, or they would stay marked Deducted against a record that no
            // longer exists and would never be collected again.
            $this->loans->releaseForRun($run->id, $tenantId);
            // #31 — same reasoning for commissions: an earning still marked Paid
            // against a deleted record would never be paid at all.
            $this->variableEarnings->releaseForRun($run->id, $tenantId);

            $run->records()->delete(); // clean slate if a Draft run is (re)processed

            $gross = $deductions = $net = 0.0;
            $count = 0;

            foreach ($salaries as $salary) {
                if (! $salary->employee) {
                    continue; // employee removed — skip defensively
                }
                $att = $this->attendance->forPeriod($salary->employee_id, $tenantId, $period);

                // Statutory split from the SAME structure breakdown the salary was
                // frozen from — the engine re-reads the component flags rather than
                // recomputing any salary figure. An unconfigured tenant gets zeros,
                // which leaves the pre-existing totals untouched.
                // #31 — approved commissions/incentives for THIS period, resolved
                // before the statutory split so the engine sees them as the
                // earnings they are. A commission is taxable income; computing tax
                // without it would under-deduct TDS and land the employee with the
                // shortfall at year end.
                $variableLines = $this->variableEarnings->linesFor((int) $salary->employee_id, $tenantId, $period);

                [$lines, $stat] = $this->statutoryFor($salary, $tenantId, $period, $variableLines);

                $record = HrPayrollRecord::create([
                    'tenant_id'          => $tenantId,
                    'payroll_run_id'     => $run->id,
                    'employee_id'        => $salary->employee_id,
                    'employee_salary_id' => $salary->id,
                    // Frozen salary snapshot (copied — never recomputed later).
                    'annual_ctc'         => $salary->annual_ctc,
                    'monthly_ctc'        => $salary->monthly_ctc,
                    'gross_salary'       => $salary->gross_salary,
                    'total_benefits'     => $salary->total_benefits,
                    'total_deductions'   => $salary->total_deductions,
                    'net_salary'         => $salary->net_salary,
                    // Attendance reference from the provider (placeholder for now).
                    'attendance_source'  => $att['connected'] ? $att['source'] : $att['source'].' (not connected)',
                    'attendance_period'  => $att['period'],
                    'payable_days'       => $att['payable_days'],
                    'absent_days'        => $att['absent_days'],
                    'leave_days'         => $att['leave_days'],
                    'status'             => HrPayrollRecord::PROCESSED,
                ] + $stat);

                // Loan instalments due this period. Recorded AFTER the record exists
                // so each instalment can point at the run that collected it. A
                // tenant with no loans gets zero and nothing below changes.
                $loanLines = $this->loans->linesFor((int) $salary->employee_id, $tenantId, $period);
                $loanTotal = $this->loans->markDeducted($record, $tenantId, $period);

                // #31 — claim this period's commissions for this record, so the
                // same money cannot be paid twice by a later run.
                $variableTotal = $this->variableEarnings->markPaid($record, $tenantId, $period);

                $stamp = array_filter([
                    'loan_deduction'    => $loanTotal > 0 ? $loanTotal : null,
                    'variable_earnings' => $variableTotal > 0 ? $variableTotal : null,
                ], fn ($v) => $v !== null);

                if ($stamp !== []) {
                    $record->update($stamp);
                }

                $this->storeLines($record, array_merge($lines, $loanLines), $tenantId);

                $gross      += (float) $salary->gross_salary;
                $deductions += (float) $salary->total_deductions;
                $net        += (float) $salary->net_salary;
                $count++;
            }

            $run->update([
                'status'           => HrPayrollRun::COMPLETED,
                'total_employees'  => $count,
                'total_gross'      => round($gross, 2),
                'total_deductions' => round($deductions, 2),
                'total_net'        => round($net, 2),
                'processed_by'     => $actor?->id,
                'processed_at'     => now(),
            ]);
            $run->recordAudit('Payroll Completed', $actor, null, ['employees' => $count, 'total_net' => round($net, 2)]);
        });

        $this->log('Payroll processed', $tenantId, $run->id);

        return $this->showRun($id, $tenantId);
    }

    /**
     * Statutory figures + the component breakdown for one employee salary.
     *
     * The structure is the only source of per-component detail; the frozen salary
     * snapshot holds totals alone. If the structure has since been deleted, the
     * record still processes — it simply carries no breakdown and zero statutory,
     * which is exactly how every record behaved before this module existed.
     *
     * @param  array  $variableLines  #31 commission/incentive lines for this period
     * @return array{0: array, 1: array}  [component lines, statutory columns]
     */
    private function statutoryFor(HrEmployeeSalary $salary, int $tenantId, string $period, array $variableLines = []): array
    {
        // An employee with no structure but a commission this month still has
        // earnings, so the variable lines are the breakdown rather than nothing.
        $empty = [$variableLines, []];

        if (! $salary->salary_structure_id) {
            return $empty;
        }

        try {
            $structure = $this->structures->show($salary->salary_structure_id, $tenantId);
        } catch (\Throwable $e) {
            return $empty;   // structure gone — never block the run
        }

        $lines = array_merge($structure['lines'] ?? [], $variableLines);

        $stat = $this->statutory->forSalary($lines, $tenantId, [
            'state' => $this->workStateFor($salary->employee, $tenantId),
            'date'  => Carbon::parse($period.'-01'),
            // Employee context switches TDS from a 12x projection to the
            // year-to-date engine, which reads the months already paid.
            'employee_id'    => $salary->employee_id,
            'fy_start_month' => (int) $this->settings->get($tenantId, 'payroll', 'fy_start_month', 4),
        ]);

        $stat['statutory_meta'] = json_encode($stat['statutory_meta']);

        return [array_merge($lines, $this->statutory->toLines($stat)), $stat];
    }

    /** Freeze the component breakdown against the record. */
    private function storeLines(HrPayrollRecord $record, array $lines, int $tenantId): void
    {
        foreach ($lines as $i => $l) {
            HrPayrollRecordLine::create([
                'tenant_id'        => $tenantId,
                'payroll_record_id' => $record->id,
                'component_id'     => $l['component_id'] ?? null,
                'code'             => $l['code'] ?? null,
                'name'             => $l['component_name'] ?? $l['name'] ?? 'Component',
                'type'             => $l['type'] ?? 'Earning',
                'source'           => $l['source'] ?? 'structure',
                'amount'           => $l['computed_amount'] ?? $l['amount'] ?? 0,
                'taxable'          => (bool) ($l['taxable'] ?? false),
                'pf_applicable'    => (bool) ($l['pf_applicable'] ?? false),
                'esic_applicable'  => (bool) ($l['esic_applicable'] ?? false),
                'sort_order'       => $l['sort_order'] ?? $i,
            ]);
        }
    }

    /** Update run status (mainly Cancel). A finalized (Completed) run is immutable. */
    public function setStatus(int $id, string $status, int $tenantId, ?User $actor = null): array
    {
        $run = $this->find($id, $tenantId);

        if (! in_array($status, HrPayrollRun::STATUSES, true)) {
            throw new BusinessException('Invalid payroll status.');
        }
        if ($run->status === HrPayrollRun::COMPLETED) {
            throw new BusinessException('Cannot modify a finalized payroll run.');
        }
        if ($status === HrPayrollRun::COMPLETED) {
            throw new BusinessException('Use Process to complete a payroll run.');
        }

        $run->update(['status' => $status]);
        if ($status === HrPayrollRun::CANCELLED) {
            $run->recordAudit('Payroll Cancelled', $actor);
        } else {
            $run->recordAudit('Payroll Status Updated', $actor, null, ['status' => $status]);
        }
        $this->log('Payroll status updated', $tenantId, $run->id);

        return $this->presentRun($run);
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation + helpers
    |--------------------------------------------------------------------------
    */
    private function presentRun(HrPayrollRun $run, bool $withAttendanceMeta = false): array
    {
        $out = [
            'id'               => $run->id,
            'payroll_month'    => $run->payroll_month,
            'payroll_year'     => $run->payroll_year,
            'period_label'     => $this->periodLabel($run->payroll_year, $run->payroll_month),
            'status'           => $run->status,
            'total_employees'  => $run->total_employees,
            'total_gross'      => (float) $run->total_gross,
            'total_deductions' => (float) $run->total_deductions,
            'total_net'        => (float) $run->total_net,
            'processed_at'     => optional($run->processed_at)->toIso8601String(),
            'created_at'       => optional($run->created_at)->toIso8601String(),
        ];

        if ($withAttendanceMeta) {
            // Surfaces the integration state so the UI can show "not connected".
            $out['attendance'] = [
                'connected' => $this->attendance->isConnected(),
                'source'    => $this->attendance->source(),
                'message'   => $this->attendance->isConnected() ? null : 'Attendance data not connected',
            ];
            $out['statutory'] = $this->runStatutoryTotals($run);
            // #38 — what this run recovered against employee loans. A pure rollup
            // of `loan_deduction` on the frozen records: nothing is recalculated,
            // so surfacing it cannot alter the run.
            $out['loan_recovery'] = $this->runLoanTotals($run);
            // #31 — what the run paid out in commissions/incentives.
            $out['variable_earnings'] = $this->variableEarnings->runTotals($run->id, (int) $run->tenant_id);
        }

        return $out;
    }

    /**
     * #38 — loan recovery for one run, summed from the frozen records.
     *
     * Read-only by construction: it aggregates `loan_deduction`, a column payroll
     * already wrote. A tenant with no loans gets zeros and the UI hides the block.
     */
    private function runLoanTotals(HrPayrollRun $run): array
    {
        $records = HrPayrollRecord::where('payroll_run_id', $run->id)
            ->where('loan_deduction', '>', 0)
            ->get(['id', 'loan_deduction']);

        return [
            'total_recovered' => round((float) $records->sum('loan_deduction'), 2),
            'employees_count' => $records->count(),
        ];
    }

    /**
     * Run-level statutory rollup, summed from the frozen records.
     *
     * `unresolved_work_state` is the number the payroll officer actually needs: it
     * counts employees whose PT could not be determined, which is the difference
     * between "nobody owes PT" and "nobody's PT was calculated".
     */
    private function runStatutoryTotals(HrPayrollRun $run): array
    {
        $sums = HrPayrollRecord::where('payroll_run_id', $run->id)
            ->selectRaw('SUM(pf_employee) pf_ee, SUM(pf_employer) pf_er, SUM(esic_employee) esic_ee,
                         SUM(esic_employer) esic_er, SUM(pt_amount) pt, SUM(tds_amount) tds,
                         SUM(statutory_deductions) total,
                         SUM(wcp_employee) wcp_ee, SUM(wcp_employer) wcp_er,
                         SUM(mediclaim_employee) medi_ee, SUM(mediclaim_employer) medi_er')
            ->first();

        $unresolved = HrPayrollRecord::where('payroll_run_id', $run->id)
            ->where('pt_amount', 0)
            ->where('statutory_meta', 'like', '%Work state not set%')
            ->count();

        return [
            'pf_employee'          => round((float) ($sums->pf_ee ?? 0), 2),
            'pf_employer'          => round((float) ($sums->pf_er ?? 0), 2),
            'esic_employee'        => round((float) ($sums->esic_ee ?? 0), 2),
            'esic_employer'        => round((float) ($sums->esic_er ?? 0), 2),
            'pt_amount'            => round((float) ($sums->pt ?? 0), 2),
            'tds_amount'           => round((float) ($sums->tds ?? 0), 2),
            'total_deductions'     => round((float) ($sums->total ?? 0), 2),
            // #30 — premiums roll up alongside the contributions.
            'wcp_employee'         => round((float) ($sums->wcp_ee ?? 0), 2),
            'wcp_employer'         => round((float) ($sums->wcp_er ?? 0), 2),
            'mediclaim_employee'   => round((float) ($sums->medi_ee ?? 0), 2),
            'mediclaim_employer'   => round((float) ($sums->medi_er ?? 0), 2),
            'employer_cost'        => round((float) ($sums->pf_er ?? 0) + (float) ($sums->esic_er ?? 0)
                + (float) ($sums->wcp_er ?? 0) + (float) ($sums->medi_er ?? 0), 2),
            'unresolved_work_state' => $unresolved,
        ];
    }

    private function presentRecord(HrPayrollRecord $r): array
    {
        return [
            'id'                => $r->id,
            'employee_id'       => $r->employee_id,
            'employee_name'     => $r->employee?->name,
            'employee_code'     => $r->employee?->employee_code,
            'department'        => $r->employee?->department,
            'annual_ctc'        => (float) $r->annual_ctc,
            'monthly_ctc'       => (float) $r->monthly_ctc,
            'gross_salary'      => (float) $r->gross_salary,
            'total_benefits'    => (float) $r->total_benefits,
            'total_deductions'  => (float) $r->total_deductions,
            'net_salary'        => (float) $r->net_salary,
            'attendance_source' => $r->attendance_source,
            'attendance_period' => $r->attendance_period,
            'payable_days'      => $r->payable_days !== null ? (float) $r->payable_days : null,
            'absent_days'       => $r->absent_days !== null ? (float) $r->absent_days : null,
            'leave_days'        => $r->leave_days !== null ? (float) $r->leave_days : null,
            'status'            => $r->status,
            'loan_deduction'    => (float) $r->loan_deduction,
            // #31 — commission/incentive paid this period, kept beside the frozen
            // snapshot rather than folded into it.
            'variable_earnings' => (float) $r->variable_earnings,
            // What actually reaches the bank: the frozen net, PLUS this period's
            // variable earnings, less the statutory split and any loan instalment.
            // `net_salary` itself is left untouched — it is the frozen snapshot
            // every existing consumer already reads.
            'net_payable'       => round(
                (float) $r->net_salary + (float) $r->variable_earnings
                - (float) $r->statutory_deductions - (float) $r->loan_deduction, 2
            ),
        ] + $this->presentStatutory($r);
    }

    /**
     * The statutory block for one record.
     *
     * `meta` travels with the figures deliberately: it carries the reason each one
     * is what it is, so "why is PT zero for this employee?" is answerable from the
     * payslip itself rather than by re-running payroll.
     */
    private function presentStatutory(HrPayrollRecord $r): array
    {
        $meta = is_array($r->statutory_meta) ? $r->statutory_meta : json_decode((string) $r->statutory_meta, true);

        return [
            'statutory' => [
                'pf_wages'        => (float) $r->pf_wages,
                'pf_employee'     => (float) $r->pf_employee,
                'pf_employer'     => (float) $r->pf_employer,
                'eps_employer'    => (float) $r->eps_employer,
                'esic_wages'      => (float) $r->esic_wages,
                'esic_employee'   => (float) $r->esic_employee,
                'esic_employer'   => (float) $r->esic_employer,
                'pt_amount'       => (float) $r->pt_amount,
                'tds_amount'      => (float) $r->tds_amount,
                'bonus_amount'    => (float) $r->bonus_amount,
                'gratuity_amount' => (float) $r->gratuity_amount,
                'taxable_earnings'=> (float) $r->taxable_earnings,
                'total_deductions'=> (float) $r->statutory_deductions,
                // #30 — WCP and Mediclaim, split the same way as PF and ESIC.
                'wcp_employee'       => (float) $r->wcp_employee,
                'wcp_employer'       => (float) $r->wcp_employer,
                'mediclaim_employee' => (float) $r->mediclaim_employee,
                'mediclaim_employer' => (float) $r->mediclaim_employer,
                // Employer cost is NOT deducted from the employee — surfaced apart
                // from the deduction total so the UI cannot conflate the two.
                'employer_cost'   => round((float) $r->pf_employer + (float) $r->esic_employer
                    + (float) $r->wcp_employer + (float) $r->mediclaim_employer, 2),
                'work_state'      => $meta['state'] ?? null,
                'meta'            => $meta ?: null,
            ],
        ];
    }

    /** Frozen component breakdown for one record — earnings then deductions. */
    public function recordLines(int $recordId, int $tenantId): array
    {
        return HrPayrollRecordLine::where('tenant_id', $tenantId)
            ->where('payroll_record_id', $recordId)
            ->orderBy('sort_order')->get()
            ->map(fn ($l) => [
                'code'            => $l->code,
                'name'            => $l->name,
                'type'            => $l->type,
                'source'          => $l->source,
                'amount'          => (float) $l->amount,
                'taxable'         => (bool) $l->taxable,
                'pf_applicable'   => (bool) $l->pf_applicable,
                'esic_applicable' => (bool) $l->esic_applicable,
            ])->all();
    }

    private function find(int $id, int $tenantId): HrPayrollRun
    {
        $run = $this->repo->findForTenant($id, $tenantId);
        if (! $run) {
            throw new BusinessException('Payroll run not found', 404);
        }

        return $run;
    }

    private function period(int $year, int $month): string
    {
        return sprintf('%04d-%02d', $year, $month);
    }

    private function periodLabel(int $year, int $month): string
    {
        try {
            return Carbon::create($year, $month, 1)->format('F Y');
        } catch (\Throwable $e) {
            return $this->period($year, $month);
        }
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
