<?php

namespace App\Services\Hr;

use App\Contracts\Hr\AttendanceProvider;
use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrPayrollRun;
use App\Models\User;
use App\Repositories\Hr\PayrollRunRepository;
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
    ) {
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
            ->with('employee:id,name')
            ->get();

        if ($salaries->isEmpty()) {
            throw new BusinessException('No employees have an active salary to process. Assign salaries first.');
        }

        $period = $this->period($run->payroll_year, $run->payroll_month);

        DB::transaction(function () use ($run, $salaries, $tenantId, $period, $actor) {
            $run->update(['status' => HrPayrollRun::PROCESSING]);
            $run->recordAudit('Payroll Started', $actor, null, ['period' => $period, 'employees' => $salaries->count()]);
            $run->records()->delete(); // clean slate if a Draft run is (re)processed

            $gross = $deductions = $net = 0.0;
            $count = 0;

            foreach ($salaries as $salary) {
                if (! $salary->employee) {
                    continue; // employee removed — skip defensively
                }
                $att = $this->attendance->forPeriod($salary->employee_id, $tenantId, $period);

                HrPayrollRecord::create([
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
                ]);

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
        }

        return $out;
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
        ];
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
