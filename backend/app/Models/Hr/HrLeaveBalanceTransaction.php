<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable ledger entry for a leave balance change (Leave Phase 2).
 * Balances are never overwritten silently — each change appends one of these.
 */
class HrLeaveBalanceTransaction extends Model
{
    protected $table = 'hr_leave_balance_transactions';

    public const TYPES = ['Allocation', 'Adjustment', 'Carry Forward', 'Opening Balance', 'Correction', 'Leave Deduction'];

    protected $fillable = [
        'tenant_id', 'employee_leave_balance_id', 'transaction_type', 'quantity', 'remarks', 'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:1',
    ];
}
