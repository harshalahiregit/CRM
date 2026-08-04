<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * A leave application (Leave Phase 3) and its approval lifecycle (Phase 4).
 * Draft → Submitted → Approved / Rejected / Cancelled. Approved deducts balance
 * via the balance service; Rejected/Cancelled never touch balance. Auditable trail
 * doubles as the approval history.
 */
class HrLeaveApplication extends Model
{
    use Auditable;

    protected $table = 'hr_leave_applications';

    public const DRAFT = 'Draft';
    public const SUBMITTED = 'Submitted';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';
    public const CANCELLED = 'Cancelled';

    protected $fillable = [
        'tenant_id', 'employee_id', 'sangoetrack_leave_id',
        'leave_type_id', 'leave_policy_id', 'employee_leave_balance_id',
        'from_date', 'to_date', 'days', 'half_day', 'reason', 'attachment_path',
        'status', 'applied_at', 'decided_by', 'decided_at', 'decision_remarks',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'from_date'   => 'date',
        'to_date'     => 'date',
        'days'        => 'decimal:1',
        'half_day'    => 'boolean',
        'applied_at'  => 'datetime',
        'decided_at'  => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function leaveType()
    {
        return $this->belongsTo(HrLeaveType::class, 'leave_type_id');
    }

    public function policy()
    {
        return $this->belongsTo(HrLeavePolicy::class, 'leave_policy_id');
    }
}
