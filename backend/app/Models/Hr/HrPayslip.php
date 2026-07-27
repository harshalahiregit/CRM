<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * An employee payslip for one completed payroll record (Payroll Phase 5).
 * Salary figures and the component breakdown are frozen at generation and never
 * recomputed. Never hard-deleted — a payslip is Cancelled, not removed.
 */
class HrPayslip extends Model
{
    use Auditable;

    protected $table = 'hr_payslips';

    public const GENERATED = 'Generated';
    public const CANCELLED = 'Cancelled';

    protected $fillable = [
        'tenant_id', 'payroll_run_id', 'payroll_record_id', 'employee_id',
        'payslip_number', 'payslip_month', 'payslip_year',
        'gross_salary', 'total_benefits', 'total_deductions', 'net_salary',
        'breakdown', 'pdf_path', 'generated_at', 'status',
        'generated_by', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'payslip_month'    => 'integer',
        'payslip_year'     => 'integer',
        'gross_salary'     => 'decimal:2',
        'total_benefits'   => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_salary'       => 'decimal:2',
        'breakdown'        => 'array',
        'generated_at'     => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function run()
    {
        return $this->belongsTo(HrPayrollRun::class, 'payroll_run_id');
    }

    public function record()
    {
        return $this->belongsTo(HrPayrollRecord::class, 'payroll_record_id');
    }
}
