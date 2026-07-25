<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Probation Review (Probation Phase 3) — a periodic review on an employee
 * probation. Ratings 1-5; recommendation is advisory (drives later phases only).
 * Lifecycle: Draft → Submitted → Completed; Completed is read-only. Reuses
 * Employee / Probation — no duplicated data. Auditable trail is the review timeline.
 */
class HrProbationReview extends Model
{
    use Auditable;

    protected $table = 'hr_probation_reviews';

    public const DRAFT = 'Draft';
    public const SUBMITTED = 'Submitted';
    public const COMPLETED = 'Completed';

    public const RECOMMENDATIONS = ['Continue', 'Extend', 'Confirm', 'Fail'];
    public const RATING_FIELDS = ['overall_rating', 'technical_rating', 'behaviour_rating', 'attendance_rating', 'communication_rating'];

    protected $fillable = [
        'tenant_id', 'employee_probation_id', 'employee_id', 'review_no', 'review_date', 'reviewer_id',
        'overall_rating', 'technical_rating', 'behaviour_rating', 'attendance_rating', 'communication_rating',
        'strengths', 'improvements', 'manager_comments', 'hr_comments', 'recommendation', 'status',
        'submitted_at', 'completed_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'review_date'          => 'date',
        'review_no'            => 'integer',
        'overall_rating'       => 'integer',
        'technical_rating'     => 'integer',
        'behaviour_rating'     => 'integer',
        'attendance_rating'    => 'integer',
        'communication_rating' => 'integer',
        'submitted_at'         => 'datetime',
        'completed_at'         => 'datetime',
    ];

    public function probation()
    {
        return $this->belongsTo(HrEmployeeProbation::class, 'employee_probation_id');
    }

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(HrEmployee::class, 'reviewer_id');
    }
}
