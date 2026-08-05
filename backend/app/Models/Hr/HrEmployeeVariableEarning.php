<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * #31 — a commission or incentive earned in one specific payroll period.
 *
 * How the money is TREATED (taxable, PF-applicable, ESIC-applicable) is not
 * stored here — it comes from the linked salary component, so a tenant changes
 * the treatment in one place and every future payout follows.
 */
class HrEmployeeVariableEarning extends Model
{
    use Auditable, BelongsToTenant;

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const PAID = 'paid';

    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::PAID];

    protected $table = 'hr_employee_variable_earnings';

    protected $fillable = [
        'tenant_id', 'employee_id', 'component_id', 'period', 'amount',
        'reference', 'remarks', 'status', 'approved_by', 'approved_at',
        'payroll_record_id', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function component()
    {
        return $this->belongsTo(HrSalaryComponent::class, 'component_id');
    }
}
