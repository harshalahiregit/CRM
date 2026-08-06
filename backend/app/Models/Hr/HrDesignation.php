<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class HrDesignation extends Model
{
    use Auditable;

    protected $table = 'hr_designations';

    protected $fillable = [
        // #43 — expected skills for this position; compared against the employee's own.
        'skills',
        'tenant_id', 'name', 'code', 'grade_id', 'description', 'is_active',
    ];

    protected $casts = [
        'skills' => 'array',
        'is_active' => 'boolean',
    ];

    public function grade()
    {
        return $this->belongsTo(HrGrade::class, 'grade_id');
    }

    public function employees()
    {
        return $this->hasMany(HrEmployee::class, 'designation_id');
    }
}
