<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** A performance review (PMS Phase 4) with per-KPI ratings and a lifecycle status. */
class HrPerformanceReview extends Model
{
    use Auditable;

    protected $table = 'hr_performance_reviews';

    public const TYPES = ['Monthly', 'Quarterly', 'Half-Yearly', 'Annual'];
    public const STATUSES = ['Draft', 'Submitted', 'Reviewed', 'Approved'];

    protected $fillable = [
        'tenant_id', 'employee_id', 'reviewer_name', 'reviewer_id', 'department', 'designation',
        'review_type', 'period_month', 'period_year', 'period_label',
        'overall_rating', 'comments', 'strengths', 'improvements', 'recommendation',
        'status', 'created_by', 'submitted_at', 'approved_at',
    ];

    protected $casts = [
        'period_month'   => 'integer',
        'period_year'    => 'integer',
        'overall_rating' => 'decimal:2',
        'submitted_at'   => 'datetime',
        'approved_at'    => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function kpiRatings()
    {
        return $this->hasMany(HrPerformanceReviewKpi::class, 'review_id');
    }
}
