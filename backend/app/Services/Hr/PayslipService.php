<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrPayrollRecordLine;
use App\Models\Hr\HrPayrollRun;
use App\Models\Hr\HrPayslip;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Hr\PayrollRunRepository;
use App\Repositories\Hr\PayslipRepository;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Payslip Management (Payroll Phase 5).
 *
 * Generates one payslip per completed payroll record, freezing the salary figures
 * and a component breakdown at generation time, then renders a PDF via the existing
 * dompdf + Blade pattern onto the hr_documents disk. No salary transfer / email /
 * tax logic. Payslips are never hard-deleted and, once generated, never recomputed.
 */
class PayslipService
{
    public const DOC_DISK = 'hr_documents';

    public function __construct(
        private PayslipRepository $repo,
        private PayrollRunRepository $runs,
        private SalaryStructureService $structures,
    ) {
    }

    public function list(int $tenantId, array $filters, ?User $actor = null): array
    {
        return $this->repo->filtered($tenantId, $filters, $actor)->map(fn ($p) => $this->present($p))->all();
    }

    public function show(int $id, int $tenantId, ?User $actor = null): array
    {
        return $this->present($this->find($id, $tenantId, $actor));
    }

    public function forEmployee(int $employeeId, int $tenantId, ?User $actor = null): array
    {
        $this->assertEmployee($employeeId, $tenantId);

        return $this->repo->forEmployee($employeeId, $tenantId, $actor)->map(fn ($p) => $this->present($p))->all();
    }

    /**
     * Generate payslips for every record of a COMPLETED run. Idempotent: records
     * that already have a payslip are skipped (duplicate-generation guard).
     */
    public function generateForRun(int $runId, int $tenantId, ?User $actor = null): array
    {
        $run = $this->runs->findForTenant($runId, $tenantId);
        if (! $run) {
            throw new BusinessException('Payroll run not found', 404);
        }
        if ($run->status !== HrPayrollRun::COMPLETED) {
            throw new BusinessException('Payroll run must be completed before generating payslips.');
        }

        $records = $this->runs->recordsForRun($runId, $tenantId);
        $generated = 0;
        $skipped = 0;

        foreach ($records as $record) {
            if ($this->repo->existsForRecord($record->id, $tenantId)) {
                $skipped++;
                continue;
            }

            DB::transaction(function () use ($record, $run, $tenantId, $actor, &$generated) {
                $seq = $this->repo->countForPeriod($tenantId, $run->payroll_year, $run->payroll_month) + 1;
                $number = sprintf('PS-%04d-%02d-%05d', $run->payroll_year, $run->payroll_month, $seq);

                $payslip = HrPayslip::create([
                    'tenant_id'         => $tenantId,
                    'payroll_run_id'    => $run->id,
                    'payroll_record_id' => $record->id,
                    'employee_id'       => $record->employee_id,
                    'payslip_number'    => $number,
                    'payslip_month'     => $run->payroll_month,
                    'payslip_year'      => $run->payroll_year,
                    // PERIOD figures, not the structure snapshot.
                    //
                    // This used to copy $record->total_deductions and
                    // $record->net_salary, which are the frozen salary-structure
                    // values: deductions 0 and net == gross, for every employee,
                    // always. A payslip therefore told somebody their net was
                    // ₹48,478 with no deductions while ₹1,800 PF and ₹200 PT had
                    // been withheld and ₹46,478 reached their bank — and an
                    // Indian payslip that does not show PF and PT is not just
                    // confusing, it is not a payslip.
                    //
                    // netPayable() is the single definition of take-home and is
                    // reused rather than re-derived here; see HrPayrollRecord.
                    'gross_salary'      => $record->periodGross(),
                    'total_benefits'    => $record->total_benefits,
                    'total_deductions'  => $record->periodDeductions(),
                    'net_salary'        => $record->netPayable(),
                    'breakdown'         => $this->buildBreakdown($record, $tenantId),
                    'status'            => HrPayslip::GENERATED,
                    'generated_by'      => $actor?->id,
                    'created_by'        => $actor?->id,
                    'updated_by'        => $actor?->id,
                ]);

                $payslip->update(['pdf_path' => $this->renderPdf($payslip), 'generated_at' => now()]);
                $payslip->recordAudit('Payslip Generated', $actor, null, ['number' => $number, 'employee_id' => $record->employee_id]);
                $generated++;
            });
        }

        $this->log('Payslips generated', $tenantId, $runId);

        return [
            'run_id'    => $runId,
            'period'    => $this->periodLabel($run->payroll_year, $run->payroll_month),
            'total'     => $records->count(),
            'generated' => $generated,
            'skipped'   => $skipped,
        ];
    }

    /** Prepare a payslip file for download (rendering the PDF if missing). Audited. */
    public function download(int $id, int $tenantId, ?User $actor = null): array
    {
        // The actor was already here for the audit line but was not reaching
        // the lookup, so the PDF was the one payslip surface scope never saw —
        // the export path around the list.
        $payslip = $this->find($id, $tenantId, $actor);

        if (empty($payslip->pdf_path) || ! Storage::disk(self::DOC_DISK)->exists($payslip->pdf_path)) {
            $payslip->update(['pdf_path' => $this->renderPdf($payslip)]);
        }

        $payslip->recordAudit('Payslip Downloaded', $actor, null, ['number' => $payslip->payslip_number]);
        $this->log('Payslip downloaded', $tenantId, $payslip->id);

        return [
            'disk'     => self::DOC_DISK,
            'path'     => $payslip->pdf_path,
            'filename' => $payslip->payslip_number.'.pdf',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | PDF + breakdown
    |--------------------------------------------------------------------------
    */

    /** Render the payslip PDF via the existing dompdf + Blade pattern. */
    public function renderPdf(HrPayslip $payslip): string
    {
        $payslip->loadMissing('employee');
        $tenant = Tenant::find($payslip->tenant_id);

        $pdf  = Pdf::loadView('pdf.payslip', ['payslip' => $payslip, 'tenant' => $tenant])->setPaper('a4');
        $path = "hr/documents/payslips/tenant_{$payslip->tenant_id}/payslip_{$payslip->id}.pdf";

        Storage::disk(self::DOC_DISK)->put($path, $pdf->output());

        return $path;
    }

    /**
     * Component breakdown for the payslip.
     *
     * Preferred source is the record's OWN frozen lines — they were captured when
     * payroll ran and they include the statutory deductions (PF/ESIC/PT/TDS), which
     * exist on no salary structure. Re-deriving from the structure is the fallback
     * for records processed before those lines existed; a structure edited since
     * would otherwise rewrite history on an already-issued payslip.
     */
    private function buildBreakdown(HrPayrollRecord $record, int $tenantId): array
    {
        $frozen = HrPayrollRecordLine::where('tenant_id', $tenantId)
            ->where('payroll_record_id', $record->id)
            ->orderBy('sort_order')->get();

        if ($frozen->isNotEmpty()) {
            $earnings = $benefits = $deductions = [];
            foreach ($frozen as $line) {
                $row = ['name' => $line->name, 'amount' => (float) $line->amount];
                match ($line->type) {
                    'Earning'   => $earnings[]   = $row,
                    'Benefit', 'Employer' => $benefits[] = $row,
                    'Deduction' => $deductions[] = $row,
                    default     => null,
                };
            }

            return ['earnings' => $earnings, 'benefits' => $benefits, 'deductions' => $deductions];
        }

        try {
            $salary = $record->employee_salary_id ? HrEmployeeSalary::find($record->employee_salary_id) : null;
            if ($salary && $salary->salary_structure_id) {
                $structure = $this->structures->show((int) $salary->salary_structure_id, $tenantId);
                $earnings = $benefits = $deductions = [];
                foreach ($structure['lines'] as $line) {
                    $row = ['name' => $line['component_name'], 'amount' => $line['computed_amount']];
                    match ($line['type']) {
                        'Earning'   => $earnings[]   = $row,
                        'Benefit'   => $benefits[]   = $row,
                        'Deduction' => $deductions[] = $row,
                        default     => null,
                    };
                }

                return ['earnings' => $earnings, 'benefits' => $benefits, 'deductions' => $deductions];
            }
        } catch (\Throwable $e) {
            // fall through to aggregate breakdown
        }

        return [
            'earnings'   => [['name' => 'Gross Earnings', 'amount' => (float) $record->gross_salary]],
            'benefits'   => [['name' => 'Employer Benefits', 'amount' => (float) $record->total_benefits]],
            'deductions' => [['name' => 'Total Deductions', 'amount' => (float) $record->total_deductions]],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation + helpers
    |--------------------------------------------------------------------------
    */
    private function present(HrPayslip $p): array
    {
        return [
            'id'               => $p->id,
            'payslip_number'   => $p->payslip_number,
            'payslip_month'    => $p->payslip_month,
            'payslip_year'     => $p->payslip_year,
            'period_label'     => $this->periodLabel($p->payslip_year, $p->payslip_month),
            'employee_id'      => $p->employee_id,
            'employee_name'    => $p->employee?->name,
            'employee_code'    => $p->employee?->employee_code,
            'department'       => $p->employee?->department,
            'designation'      => $p->employee?->designation,
            'gross_salary'     => (float) $p->gross_salary,
            'total_benefits'   => (float) $p->total_benefits,
            'total_deductions' => (float) $p->total_deductions,
            'net_salary'       => (float) $p->net_salary,
            'breakdown'        => $p->breakdown,
            'status'           => $p->status,
            'generated_at'     => optional($p->generated_at)->toIso8601String(),
            'has_pdf'          => ! empty($p->pdf_path),
        ];
    }

    private function find(int $id, int $tenantId, ?User $actor = null): HrPayslip
    {
        $payslip = $this->repo->findForTenant($id, $tenantId, $actor);
        if (! $payslip) {
            throw new BusinessException('Payslip not found', 404);
        }

        return $payslip;
    }

    private function assertEmployee(int $employeeId, int $tenantId): void
    {
        $exists = \App\Models\Hr\HrEmployee::where('tenant_id', $tenantId)->where('id', $employeeId)->exists();
        if (! $exists) {
            throw new BusinessException('Employee not found', 404);
        }
    }

    private function periodLabel(int $year, int $month): string
    {
        try {
            return Carbon::create($year, $month, 1)->format('F Y');
        } catch (\Throwable $e) {
            return sprintf('%04d-%02d', $year, $month);
        }
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
