<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * A named salary structure (Payroll Phase 2) composing Salary Components into a
 * computed CTC breakdown. Never hard-deleted — deactivate to retire. The actual
 * amounts are computed on read by SalaryStructureService (nothing is denormalised).
 */
class HrSalaryStructure extends Model
{
    use Auditable;

    protected $table = 'hr_salary_structures';

    protected $fillable = [
        'tenant_id', 'name', 'code', 'grade_id', 'designation_id', 'description', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function lines()
    {
        return $this->hasMany(HrSalaryStructureLine::class, 'structure_id')->orderBy('sort_order');
    }

    public function grade()
    {
        return $this->belongsTo(HrGrade::class, 'grade_id');
    }

    public function designation()
    {
        return $this->belongsTo(HrDesignation::class, 'designation_id');
    }
}
