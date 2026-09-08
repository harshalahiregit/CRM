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

    /** Per-person payment tracking, owned by accounts at the Disburse stage. */
    public const PAY_PENDING = 'Pending';
    public const PAY_PAID = 'Paid';
    public const PAY_HOLD = 'Hold';
    public const PAY_FAILED = 'Failed';
    public const PAYMENT_STATUSES = [self::PAY_PENDING, self::PAY_PAID, self::PAY_HOLD, self::PAY_FAILED];

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
        // LWF (half-yearly) and voluntary PF. Same $fillable trap the comments
        // below already warn about — added as columns without being listed here,
        // create() drops them and the register prints zeros.
        'lwf_employee', 'lwf_employer', 'vpf_amount',
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
        // Workflow: what accounts did with this person's money, whether the
        // payslip has been released, and HR's net adjustment. Same $fillable
        // trap as every block above — omitted here, create() drops them and the
        // payable figure silently loses the adjustment.
        'payment_status', 'paid_at', 'payment_note', 'payslip_visible', 'adjustment_total',
    ];

    protected $casts = [
        'annual_ctc'       => 'decimal:2',
        'monthly_ctc'      => 'decimal:2',
        'gross_salary'     => 'decimal:2',
        'lwf_employee'     => 'decimal:2',
        'lwf_employer'     => 'decimal:2',
        'vpf_amount'       => 'decimal:2',
        'total_benefits'   => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_salary'       => 'decimal:2',
        'payable_days'     => 'decimal:1',
        'absent_days'      => 'decimal:1',
        'leave_days'       => 'decimal:1',
        'adjustment_total' => 'decimal:2',
        'payslip_visible'  => 'boolean',
        'paid_at'          => 'datetime',
    ];

    /**
     * What this person is actually owed — the ONLY definition of it.
     *
     * `net_salary` is the frozen snapshot of the salary structure and is
     * deliberately never recomputed, so everything that happened during the
     * period lives in its own column beside it. Adding them up is therefore a
     * step every consumer has to perform, and for a while they did not all
     * perform it the same way: BankAdviceService transferred `net_salary`
     * directly, so on a July run reproducing employee SD104 it wrote an advice
     * for ₹48,478 against a true net of ₹46,539 — PF ₹1,800 and ESIC ₹139 were
     * withheld on the payslip, remitted to the government, AND paid to the
     * employee. Nothing in that file disagreed with itself; the arithmetic was
     * only wrong against a column that service never read.
     *
     * The formula lives here so there is one of it. Adding a new column that
     * affects pay means changing this method, and the payslip, the bank advice
     * and the register all move together or none of them do.
     */
    public function netPayable(): float
    {
        return round(
            (float) $this->net_salary
            + (float) $this->variable_earnings
            + (float) $this->adjustment_total   // HR's additions net of deductions
            - (float) $this->statutory_deductions
            - (float) $this->loan_deduction,
            2
        );
    }

    public function run()
    {
        return $this->belongsTo(HrPayrollRun::class, 'payroll_run_id');
    }

    public function adjustments()
    {
        return $this->hasMany(HrPayrollAdjustment::class, 'payroll_record_id');
    }

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
