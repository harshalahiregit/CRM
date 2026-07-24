<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class HrDepartment extends Model
{
    use Auditable;

    protected $table = 'hr_departments';

    protected $fillable = [
        'tenant_id', 'name', 'code', 'head_employee_id', 'description', 'is_active',
    ];

    protected $casts = [
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
