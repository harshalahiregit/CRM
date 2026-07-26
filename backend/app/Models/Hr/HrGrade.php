<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class HrGrade extends Model
{
    use Auditable;

    protected $table = 'hr_grades';

    protected $fillable = [
        'tenant_id', 'name', 'code', 'level', 'description', 'is_active',
    ];

    protected $casts = [
        'level'     => 'integer',
        'is_active' => 'boolean',
    ];

    public function designations()
    {
        return $this->hasMany(HrDesignation::class, 'grade_id');
    }
}
