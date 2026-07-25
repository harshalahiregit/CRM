<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * An employee's leave balance for one leave type (Leave Phase 2).
 * available_balance = opening + allocated + adjusted + carried_forward − used.
 * Every mutation is recorded as an HrLeaveBalanceTransaction; only one active
 * balance per employee + leave type (enforced in the service).
 */
class HrEmployeeLeaveBalance extends Model
{
    use Auditable;

    protected $table = 'hr_employee_leave_balances';

    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';

    protected $fillable = [
        'tenant_id', 'employee_id', 'leave_policy_id', 'leave_type_id',
        'allocated', 'opening_balance', 'used', 'adjusted', 'carried_forward', 'available_balance',
        'effective_from', 'effective_to', 'status', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'allocated'         => 'decimal:1',
        'opening_balance'   => 'decimal:1',
        'used'              => 'decimal:1',
        'adjusted'          => 'decimal:1',
        'carried_forward'   => 'decimal:1',
        'available_balance' => 'decimal:1',
        'effective_from'    => 'date',
        'effective_to'      => 'date',
    ];

    /** Recompute the stored available balance from its components. */
    public function recomputeAvailable(): void
    {
        $this->available_balance = round(
            (float) $this->opening_balance + (float) $this->allocated + (float) $this->adjusted
            + (float) $this->carried_forward - (float) $this->used,
            1
        );
    }

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function policy()
    {
        return $this->belongsTo(HrLeavePolicy::class, 'leave_policy_id');
    }

    public function leaveType()
    {
        return $this->belongsTo(HrLeaveType::class, 'leave_type_id');
    }

    public function transactions()
    {
        return $this->hasMany(HrLeaveBalanceTransaction::class, 'employee_leave_balance_id')->latest('id');
    }
}
