<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Training Attendance (L&D Phase 5) — attendance for a training assignment,
 * entirely separate from office attendance / SangoeTrack. One row per assignment.
 * Tenant-scoped, audited.
 */
class HrTrainingAttendance extends Model
{
    use Auditable;

    protected $table = 'hr_training_attendance';

    public const PRESENT = 'Present';
    public const ABSENT = 'Absent';

    protected $fillable = [
        'tenant_id', 'training_session_id', 'employee_training_id', 'employee_id',
        'attendance_status', 'check_in', 'check_out', 'remarks', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'check_in'  => 'datetime',
        'check_out' => 'datetime',
    ];

    public function assignment()
    {
        return $this->belongsTo(HrEmployeeTraining::class, 'employee_training_id');
    }

    public function session()
    {
        return $this->belongsTo(HrTrainingSession::class, 'training_session_id');
    }

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
