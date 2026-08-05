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
        // Statutory split. Employer contributions are recorded but are NOT part of
        // total_deductions — they are a company cost, not an employee deduction.
        'pf_wages', 'pf_employee', 'pf_employer', 'eps_employer',
        'esic_wages', 'esic_employee', 'esic_employer',
        'pt_amount', 'tds_amount', 'bonus_amount', 'gratuity_amount',
        'taxable_earnings', 'statutory_deductions', 'statutory_meta',
        // Year-to-date tax context. Without these in $fillable, create() drops them
        // silently and every figure lands as zero — which is exactly what happened
        // the first time the statutory columns were added.
        'financial_year', 'tax_regime', 'ytd_taxable_earnings', 'ytd_tds',
        'annual_taxable_income', 'annual_tax_liability',
        // Loan / salary-advance instalments collected this period.
        'loan_deduction',
        // #30 WCP + Mediclaim premiums, #31 commission/incentive paid this period.
        // Same $fillable trap as the tax columns above: omit them and create()
        // discards them without a word.
        'wcp_employee', 'wcp_employer', 'mediclaim_employee', 'mediclaim_employer',
        'variable_earnings',
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
