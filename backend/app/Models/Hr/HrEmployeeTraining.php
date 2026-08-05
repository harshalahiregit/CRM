<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Employee Training Assignment (L&D Phase 4). Links an employee to a Training
 * Session (instance of a Program). Reuses Employee / Program / Session — no
 * duplicated data. Lifecycle: Assigned → In Progress → Completed, Cancelled
 * before completion; Completed / Cancelled are terminal (read-only). Auditable
 * trail is the assignment timeline.
 */
class HrEmployeeTraining extends Model
{
    use Auditable;

    protected $table = 'hr_employee_trainings';

    public const ASSIGNED = 'Assigned';
    public const IN_PROGRESS = 'In Progress';
    public const COMPLETED = 'Completed';
    public const CANCELLED = 'Cancelled';

    /** Statuses that still occupy a session seat / count as active. */
    public const ACTIVE = [self::ASSIGNED, self::IN_PROGRESS];
    /** Terminal statuses — no further transitions/edits. */
    public const TERMINAL = [self::COMPLETED, self::CANCELLED];

    protected $fillable = [
        'tenant_id', 'employee_id', 'training_program_id', 'training_session_id',
        'assigned_by', 'assigned_at', 'due_date', 'status', 'remarks', 'completion_percentage',
        'started_at', 'completed_at', 'created_by', 'updated_by',
        // #23 — retraining. WITHOUT these in $fillable, create() drops them without
        // error and every attempt lands on the column default of 1, which is
        // indistinguishable from a correctly-computed first attempt.
        'attempt_number', 'is_retraining', 'previous_training_id', 'retraining_reason',
    ];

    protected $casts = [
        'attempt_number'        => 'integer',
        'is_retraining'         => 'boolean',
        'assigned_at'           => 'datetime',
        'due_date'              => 'date',
        'started_at'            => 'datetime',
        'completed_at'          => 'datetime',
        'completion_percentage' => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function program()
    {
        return $this->belongsTo(HrTrainingProgram::class, 'training_program_id');
    }

    public function session()
    {
        return $this->belongsTo(HrTrainingSession::class, 'training_session_id');
    }
}
