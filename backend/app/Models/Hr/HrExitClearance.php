<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Exit Clearance (Exit Phase 4) — the per-exit parent record. Overall status is
 * derived from its departmental items: Completed only when every mandatory
 * department is Cleared; Rejected if any mandatory department is Rejected.
 * Auditable trail doubles as the clearance timeline.
 */
class HrExitClearance extends Model
{
    use Auditable;

    protected $table = 'hr_exit_clearances';

    public const PENDING = 'Pending';
    public const IN_PROGRESS = 'In Progress';
    public const COMPLETED = 'Completed';
    public const REJECTED = 'Rejected';

    protected $fillable = [
        'tenant_id', 'exit_request_id', 'employee_id', 'status',
        'started_at', 'completed_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(HrExitClearanceItem::class, 'clearance_id');
    }

    public function exitRequest()
    {
        return $this->belongsTo(HrExitRequest::class, 'exit_request_id');
    }

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
