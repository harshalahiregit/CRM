<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class HrDepartment extends Model
{
    use Auditable;

    protected $table = 'hr_departments';

    protected $fillable = [
        // #43 — expected skills for this position; compared against the employee's own.
        'skills',
        'tenant_id', 'name', 'code', 'head_employee_id', 'description', 'is_active',
    ];

    protected $casts = [
        'skills' => 'array',
        'is_active' => 'boolean',
    ];

    /** Department Head — an employee of the same tenant. */
    public function head()
    {
        return $this->belongsTo(HrEmployee::class, 'head_employee_id');
    }

    /** Employees assigned to this department (via the new department_id link). */
    public function employees()
    {
        return $this->hasMany(HrEmployee::class, 'department_id');
    }
}
