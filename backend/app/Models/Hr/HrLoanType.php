<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A loan type. A SALARY ADVANCE is one of these with `is_advance` set — single
 * instalment, normally interest-free — rather than a parallel module with its own
 * schedule and workflow to keep in step.
 *
 * Caps are nullable: null means "no ceiling", which is different from 0.
 */
class HrLoanType extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_loan_types';

    protected $fillable = [
        'tenant_id', 'name', 'code', 'is_advance', 'max_amount', 'max_tenure_months',
        'interest_rate', 'requires_approval', 'description', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_advance'        => 'boolean',
        'requires_approval' => 'boolean',
        'is_active'         => 'boolean',
        'max_amount'        => 'decimal:2',
        'max_tenure_months' => 'integer',
        'interest_rate'     => 'decimal:3',
    ];

    public function loans()
    {
        return $this->hasMany(HrEmployeeLoan::class, 'loan_type_id');
    }
}
