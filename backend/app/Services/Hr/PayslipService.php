<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrPayrollRecord;
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

    public function list(int $tenantId, array $filters): array
    {
        return $this->repo->filtered($tenantId, $filters)->map(fn ($p) => $this->present($p))->all();
    }

    public function show(int $id, int $tenantId): array
    {
        return $this->present($this->find($id, $tenantId));
    }

    public function forEmployee(int $employeeId, int $tenantId): array
    {
        $this->assertEmployee($employeeId, $tenantId);

        return $this->repo->forEmployee($employeeId, $tenantId)->map(fn ($p) => $this->present($p))->all();
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
                    'gross_salary'      => $record->gross_salary,
                    'total_benefits'    => $record->total_benefits,
                    'total_deductions'  => $record->total_deductions,
                    'net_salary'        => $record->net_salary,
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
        $payslip = $this->find($id, $tenantId);

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
     * Component breakdown captured from the salary structure at generation time.
     * Falls back to aggregate-only lines if the structure is unavailable, so the
     * payslip is always self-contained and frozen.
     */
    private function buildBreakdown(HrPayrollRecord $record, int $tenantId): array
    {
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

    private function find(int $id, int $tenantId): HrPayslip
    {
        $payslip = $this->repo->findForTenant($id, $tenantId);
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
