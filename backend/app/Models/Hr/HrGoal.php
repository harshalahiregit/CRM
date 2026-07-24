<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Goal / KRA definition (PMS Phase 2). Assigned to employees via HrEmployeeGoal. */
class HrGoal extends Model
{
    use Auditable;

    protected $table = 'hr_goals';

    protected $fillable = [
        'tenant_id', 'title', 'description', 'department', 'designation',
        'weightage', 'target', 'due_date', 'status', 'created_by',
    ];

    protected $casts = [
        'weightage' => 'decimal:2',
        'due_date'  => 'date',
    ];

    public function assignments()
    {
        return $this->hasMany(HrEmployeeGoal::class, 'goal_id');
    }
}
