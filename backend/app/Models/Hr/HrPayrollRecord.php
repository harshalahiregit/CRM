<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/**
 * A frozen per-employee payroll snapshot within a run (Payroll Phase 4).
 * Salary figures are copied at process time and never recomputed; attendance
 * fields are a reference supplied by the AttendanceProvider, not computed here.
 */
class HrPayrollRecord extends Model
{
    protected $table = 'hr_payroll_records';

    public const DRAFT = 'Draft';
    public const PROCESSED = 'Processed';
    public const FINALIZED = 'Finalized';

    protected $fillable = [
        'tenant_id', 'payroll_run_id', 'employee_id', 'employee_salary_id',
        'annual_ctc', 'monthly_ctc', 'gross_salary', 'total_benefits', 'total_deductions', 'net_salary',
        'attendance_source', 'attendance_period', 'payable_days', 'absent_days', 'leave_days',
        'status',
    ];

    protected $casts = [
        'annual_ctc'       => 'decimal:2',
        'monthly_ctc'      => 'decimal:2',
        'gross_salary'     => 'decimal:2',
        'total_benefits'   => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_salary'       => 'decimal:2',
        'payable_days'     => 'decimal:1',
        'absent_days'      => 'decimal:1',
        'leave_days'       => 'decimal:1',
    ];

    public function run()
    {
        return $this->belongsTo(HrPayrollRun::class, 'payroll_run_id');
    }

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
