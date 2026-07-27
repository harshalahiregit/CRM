<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Probation Extension (Probation Phase 4) — an extension request on an employee
 * probation. Lifecycle: Pending → Approved / Rejected (both terminal). Approval
 * pushes the probation's end date and marks it Extended. Reuses Employee /
 * Probation — no duplicated data. Auditable trail is the extension timeline.
 */
class HrProbationExtension extends Model
{
    use Auditable;

    protected $table = 'hr_probation_extensions';

    public const PENDING = 'Pending';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';

    public const TERMINAL = [self::APPROVED, self::REJECTED];

    protected $fillable = [
        'tenant_id', 'probation_id', 'employee_id', 'requested_by', 'approved_by',
        'extension_number', 'current_end_date', 'extended_end_date', 'extension_days',
        'reason', 'manager_comments', 'hr_comments', 'status',
        'approved_at', 'rejected_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'extension_number'  => 'integer',
        'current_end_date'  => 'date',
        'extended_end_date' => 'date',
        'extension_days'    => 'integer',
        'approved_at'       => 'datetime',
        'rejected_at'       => 'datetime',
    ];

    public function probation()
    {
        return $this->belongsTo(HrEmployeeProbation::class, 'probation_id');
    }

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(HrEmployee::class, 'requested_by');
    }
}
