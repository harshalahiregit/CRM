<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable salary component definition (Payroll Phase 1).
 *
 * Type: Earning | Deduction | Benefit.
 * CalculationType: Fixed (amount_value) | Percentage (percentage_value of based_on).
 * Never hard-deleted — deactivate (is_active=false) to retire while preserving history.
 */
class HrSalaryComponent extends Model
{
    use Auditable;

    protected $table = 'hr_salary_components';

    // 'Benefit' is retained for backward compatibility and is treated as an employer
    // contribution by the engine (Gross + Employer/Benefit = CTC).
    public const TYPES = ['Earning', 'Employer', 'Deduction', 'Benefit'];
    public const CALC_TYPES = ['Fixed', 'Percentage', 'Formula', 'Manual'];

    protected $fillable = [
        'tenant_id', 'name', 'code', 'type', 'calculation_type',
        'amount_value', 'percentage_value', 'based_on', 'formula', 'description', 'is_active',
        'taxable', 'pf_applicable', 'esic_applicable', 'sequence', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'amount_value'     => 'decimal:2',
        'percentage_value' => 'decimal:2',
        'is_active'        => 'boolean',
        'taxable'          => 'boolean',
        'pf_applicable'    => 'boolean',
        'esic_applicable'  => 'boolean',
        'sequence'         => 'integer',
    ];
}
