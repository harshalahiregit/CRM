<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One scheduled instalment.
 *
 * Generated once when the loan is disbursed and then frozen — recomputing on read
 * would rewrite an agreed EMI the moment a rate was edited. `payroll_record_id` is
 * the audit link to the run that actually collected it, so "was this deducted?"
 * never has to be inferred from dates.
 */
class HrLoanInstallment extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_loan_installments';

    public const PENDING = 'Pending';
    public const DEDUCTED = 'Deducted';
    public const WAIVED = 'Waived';
    public const SKIPPED = 'Skipped';

    public const STATUSES = [self::PENDING, self::DEDUCTED, self::WAIVED, self::SKIPPED];

    protected $fillable = [
        'tenant_id', 'loan_id', 'sequence', 'period', 'amount',
        'principal_component', 'interest_component', 'balance_after',
        'status', 'payroll_record_id', 'deducted_amount', 'deducted_on', 'remarks',
    ];

    protected $casts = [
        'sequence'            => 'integer',
        'amount'              => 'decimal:2',
        'principal_component' => 'decimal:2',
        'interest_component'  => 'decimal:2',
        'balance_after'       => 'decimal:2',
        'deducted_amount'     => 'decimal:2',
        'deducted_on'         => 'date',
    ];

    public function loan()
    {
        return $this->belongsTo(HrEmployeeLoan::class, 'loan_id');
    }
}
