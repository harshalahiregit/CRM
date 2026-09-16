<?php

namespace App\Models\Hr;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Money HR adds to or takes off one person's pay, outside the salary structure.
 *
 * The structure covers what somebody earns every month. This covers what
 * happened THIS month and nowhere else: an unsettled claim carried over, an
 * arrear from a late confirmation, a recovery for an asset not returned. The
 * brief called it "HR ka ek extra column" and was specific that it carries a
 * written reason and, where there is one, a document.
 *
 * The reason is not optional and that is the entire design. An unexplained
 * ±₹1,000 against a name is indistinguishable from a calculation error the
 * moment the person who typed it has moved on, and the only way anybody finds
 * out is the employee asking why their salary is short.
 *
 * Adjustments are rows, never edits to the frozen snapshot. The structure
 * figures stay exactly as the engine computed them; the adjustment sits beside
 * them attributable to whoever made it. `HrPayrollRecord::netPayable()` is what
 * folds the two together, in one place.
 */
class HrPayrollAdjustment extends Model
{
    protected $table = 'hr_payroll_adjustments';

    public const ADDITION = 'Addition';
    public const DEDUCTION = 'Deduction';
    public const TYPES = [self::ADDITION, self::DEDUCTION];

    protected $fillable = [
        'tenant_id', 'payroll_run_id', 'payroll_record_id', 'employee_id',
        'type', 'amount', 'reason', 'attachment_path', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /**
     * The amount as it affects pay: positive for an addition, negative for a
     * deduction. Callers sum this rather than branching on `type`, so a total
     * cannot be computed with the sign the wrong way round.
     */
    public function signedAmount(): float
    {
        $amount = abs((float) $this->amount);

        return $this->type === self::DEDUCTION ? -$amount : $amount;
    }

    public function record()
    {
        return $this->belongsTo(HrPayrollRecord::class, 'payroll_record_id');
    }

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
