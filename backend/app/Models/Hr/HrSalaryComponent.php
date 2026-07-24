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

    public const TYPES = ['Earning', 'Deduction', 'Benefit'];
    public const CALC_TYPES = ['Fixed', 'Percentage'];

    protected $fillable = [
        'tenant_id', 'name', 'code', 'type', 'calculation_type',
        'amount_value', 'percentage_value', 'based_on', 'description', 'is_active',
    ];

    protected $casts = [
        'amount_value'     => 'decimal:2',
        'percentage_value' => 'decimal:2',
        'is_active'        => 'boolean',
    ];
}
