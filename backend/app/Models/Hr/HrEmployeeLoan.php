<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * An employee loan (or salary advance — see HrLoanType).
 *
 * Lifecycle: Draft → Submitted → Approved → Disbursed → Closed, with Rejected and
 * Cancelled as terminal branches. Only a DISBURSED loan is deducted by payroll:
 * approving a loan agrees to it, disbursing it is when money actually moved and
 * repayment can legitimately begin.
 */
class HrEmployeeLoan extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_employee_loans';

    public const DRAFT = 'Draft';
    public const SUBMITTED = 'Submitted';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';
    public const DISBURSED = 'Disbursed';
    public const CLOSED = 'Closed';
    public const CANCELLED = 'Cancelled';

    public const STATUSES = [
        self::DRAFT, self::SUBMITTED, self::APPROVED, self::REJECTED,
        self::DISBURSED, self::CLOSED, self::CANCELLED,
    ];

    protected $fillable = [
        'tenant_id', 'employee_id', 'loan_type_id', 'loan_number',
        'principal', 'interest_rate', 'tenure_months', 'emi', 'total_payable',
        'total_repaid', 'outstanding', 'start_period', 'disbursed_on',
        'status', 'purpose', 'remarks',
        'submitted_at', 'approved_at', 'approved_by', 'closed_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'principal'     => 'decimal:2',
        'interest_rate' => 'decimal:3',
        'tenure_months' => 'integer',
        'emi'           => 'decimal:2',
        'total_payable' => 'decimal:2',
        'total_repaid'  => 'decimal:2',
        'outstanding'   => 'decimal:2',
        'disbursed_on'  => 'date',
        'submitted_at'  => 'datetime',
        'approved_at'   => 'datetime',
        'closed_at'     => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function loanType()
    {
        return $this->belongsTo(HrLoanType::class, 'loan_type_id');
    }

    public function installments()
    {
        return $this->hasMany(HrLoanInstallment::class, 'loan_id')->orderBy('sequence');
    }

    /**
     * Whether payroll should deduct from this loan.
     *
     * Deliberately NOT "approved": an approved-but-undisbursed loan has handed the
     * employee nothing, so deducting from it would take money for a loan they have
     * not received.
     */
    public function isRepaying(): bool
    {
        return $this->status === self::DISBURSED;
    }
}
