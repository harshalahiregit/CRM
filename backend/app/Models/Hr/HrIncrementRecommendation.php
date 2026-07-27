<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Increment recommendation (PMS Phase 6). Stores a suggestion only — it NEVER
 * modifies Payroll. Any actual salary revision happens later in the Payroll module.
 */
class HrIncrementRecommendation extends Model
{
    use Auditable;

    protected $table = 'hr_increment_recommendations';

    public const STATUSES = ['Pending', 'Approved', 'Rejected'];

    protected $fillable = [
        'tenant_id', 'employee_id', 'review_id', 'current_salary',
        'suggested_percentage', 'suggested_amount', 'reason', 'approval_status', 'created_by',
    ];

    protected $casts = [
        'current_salary'       => 'decimal:2',
        'suggested_percentage' => 'decimal:2',
        'suggested_amount'     => 'decimal:2',
    ];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
