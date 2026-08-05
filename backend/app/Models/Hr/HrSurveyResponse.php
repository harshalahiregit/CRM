<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * #26 — one employee's submission.
 *
 * On an ANONYMOUS survey `employee_id` is NULL and stays null. Nothing derived
 * from the employee is stored in its place — not a hash, not a token. A survey
 * people believe is anonymous but is not is worse than no survey.
 *
 * `department` IS kept, because department analytics is an explicit requirement.
 * That is a real trade-off in a small team, which is why the report service
 * suppresses department breakdowns below a minimum group size rather than
 * publishing a segment of one.
 */
class HrSurveyResponse extends Model
{
    use BelongsToTenant;

    public const DRAFT = 'Draft';
    public const SUBMITTED = 'Submitted';

    protected $table = 'hr_survey_responses';

    protected $fillable = [
        'tenant_id', 'survey_id', 'employee_id', 'department', 'designation',
        'status', 'submitted_at',
    ];

    protected $casts = ['submitted_at' => 'datetime'];

    public function survey()
    {
        return $this->belongsTo(HrSurvey::class, 'survey_id');
    }

    public function answers()
    {
        return $this->hasMany(HrSurveyAnswer::class, 'response_id');
    }

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function isAnonymous(): bool
    {
        return $this->employee_id === null;
    }
}
