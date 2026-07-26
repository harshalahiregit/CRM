<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/** Policy ↔ Leave Type mapping (Leave Phase 1): per-type allocation within a policy. */
class HrLeavePolicyType extends Model
{
    protected $table = 'hr_leave_policy_types';

    protected $fillable = [
        'policy_id', 'leave_type_id', 'yearly_allocation', 'carry_forward_limit',
    ];

    protected $casts = [
        'yearly_allocation'   => 'decimal:1',
        'carry_forward_limit' => 'decimal:1',
    ];

    public function leaveType()
    {
        return $this->belongsTo(HrLeaveType::class, 'leave_type_id');
    }
}
