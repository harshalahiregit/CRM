<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Promotion recommendation (PMS Phase 5). Recommendation only — no org/payroll change. */
class HrPromotionRecommendation extends Model
{
    use Auditable;

    protected $table = 'hr_promotion_recommendations';

    public const STATUSES = ['Pending', 'Approved', 'Rejected'];

    protected $fillable = [
        'tenant_id', 'employee_id', 'review_id', 'eligible', 'overall_rating', 'completed_goals',
        'current_designation', 'recommended_designation', 'reason', 'status', 'created_by',
    ];

    protected $casts = [
        'eligible'        => 'boolean',
        'overall_rating'  => 'decimal:2',
        'completed_goals' => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
