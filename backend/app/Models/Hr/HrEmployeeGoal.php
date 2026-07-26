<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** A goal assigned to an employee (PMS Phase 2), with progress + status. */
class HrEmployeeGoal extends Model
{
    use Auditable;

    protected $table = 'hr_employee_goals';

    protected $fillable = [
        'tenant_id', 'goal_id', 'employee_id', 'status', 'progress',
        'assigned_by', 'assigned_at', 'completed_at',
    ];

    protected $casts = [
        'progress'     => 'integer',
        'assigned_at'  => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function goal()
    {
        return $this->belongsTo(HrGoal::class, 'goal_id');
    }

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
