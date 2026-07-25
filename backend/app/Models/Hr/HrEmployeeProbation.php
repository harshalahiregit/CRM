<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Employee Probation (Probation Phase 2). Links an employee to a Probation Policy
 * + Type. One active probation per employee. Lifecycle: Assigned → Active →
 * Extended → Confirmed / Failed / Cancelled; terminal states are read-only.
 * Reuses Employee / Policy / Type — no duplicated data. Auditable trail is the
 * probation timeline.
 */
class HrEmployeeProbation extends Model
{
    use Auditable;

    protected $table = 'hr_employee_probations';

    public const ASSIGNED = 'Assigned';
    public const ACTIVE = 'Active';
    public const EXTENDED = 'Extended';
    public const CONFIRMED = 'Confirmed';
    public const FAILED = 'Failed';
    public const CANCELLED = 'Cancelled';

    /** Statuses that occupy the single "active probation" slot for an employee. */
    public const OPEN = [self::ASSIGNED, self::ACTIVE, self::EXTENDED];
    /** Terminal statuses — no further edits/transitions. */
    public const TERMINAL = [self::CONFIRMED, self::FAILED, self::CANCELLED];

    protected $fillable = [
        'tenant_id', 'employee_id', 'probation_policy_id', 'probation_type_id',
        'joining_date', 'probation_start_date', 'probation_end_date', 'confirmation_due_date',
        'current_status', 'review_cycle', 'extension_count', 'remarks',
        'assigned_by', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'joining_date'          => 'date',
        'probation_start_date'  => 'date',
        'probation_end_date'    => 'date',
        'confirmation_due_date' => 'date',
        'extension_count'       => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function policy()
    {
        return $this->belongsTo(HrProbationPolicy::class, 'probation_policy_id');
    }

    public function probationType()
    {
        return $this->belongsTo(HrProbationType::class, 'probation_type_id');
    }
}
