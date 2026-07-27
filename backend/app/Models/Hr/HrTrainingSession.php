<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Training Session (L&D Phase 3) — a scheduled instance of a Training Program.
 * Reuses Program / Provider / Department / Designation. Lifecycle:
 * Scheduled → Ongoing → Completed, with Cancelled from either (terminal).
 * Tenant-scoped; never hard-deleted.
 */
class HrTrainingSession extends Model
{
    use Auditable;

    protected $table = 'hr_training_sessions';

    public const SCHEDULED = 'Scheduled';
    public const ONGOING = 'Ongoing';
    public const COMPLETED = 'Completed';
    public const CANCELLED = 'Cancelled';

    /** Terminal statuses — no further edits/transitions. */
    public const TERMINAL = [self::COMPLETED, self::CANCELLED];

    protected $fillable = [
        'tenant_id', 'training_program_id', 'provider_id', 'department_id', 'designation_id',
        'title', 'trainer_name', 'mode', 'venue', 'meeting_url', 'start_at', 'end_at',
        'capacity', 'status', 'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'start_at'  => 'datetime',
        'end_at'    => 'datetime',
        'capacity'  => 'integer',
    ];

    public function program()
    {
        return $this->belongsTo(HrTrainingProgram::class, 'training_program_id');
    }

    public function provider()
    {
        return $this->belongsTo(HrTrainingProvider::class, 'provider_id');
    }

    public function department()
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    public function designation()
    {
        return $this->belongsTo(HrDesignation::class, 'designation_id');
    }
}
