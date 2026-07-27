<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/**
 * Exit Clearance Item (Exit Phase 4) — one departmental checklist row on a
 * clearance. Each department moves independently: Pending → In Progress →
 * Cleared / Rejected. Cleared and Rejected are terminal (no clearing twice).
 * Audit is recorded on the parent HrExitClearance so the timeline stays unified.
 */
class HrExitClearanceItem extends Model
{
    protected $table = 'hr_exit_clearance_items';

    public const PENDING = 'Pending';
    public const IN_PROGRESS = 'In Progress';
    public const CLEARED = 'Cleared';
    public const REJECTED = 'Rejected';

    /** Mandatory departments seeded on every clearance. */
    public const DEPARTMENTS = ['HR', 'IT', 'Admin', 'Finance', 'Reporting Manager'];

    protected $fillable = [
        'tenant_id', 'clearance_id', 'department', 'is_mandatory', 'status',
        'assigned_to', 'remarks', 'started_at', 'decided_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'started_at'   => 'datetime',
        'decided_at'   => 'datetime',
    ];

    public function clearance()
    {
        return $this->belongsTo(HrExitClearance::class, 'clearance_id');
    }
}
