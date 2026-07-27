<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class HrDesignation extends Model
{
    use Auditable;

    protected $table = 'hr_designations';

    protected $fillable = [
        'tenant_id', 'name', 'code', 'grade_id', 'description', 'is_active',
    ];

    protected $casts = [
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
