<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of a salary structure: a reference to a Salary Component plus the
 * structure-specific value (fixed amount, or a percentage of `based_on`).
 * Type / calculation_type / name are read from the referenced component — the
 * component master stays the single source of truth.
 */
class HrSalaryStructureLine extends Model
{
    protected $table = 'hr_salary_structure_lines';

    protected $fillable = [
        'structure_id', 'component_id', 'calculation_type', 'amount', 'percentage', 'based_on', 'formula', 'sort_order',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'percentage' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function component()
    {
        return $this->belongsTo(HrSalaryComponent::class, 'component_id');
    }
}
