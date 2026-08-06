<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * #26 — an employee survey.
 *
 * Lifecycle: Draft → Scheduled → Active → Closed → Archived. A Scheduled survey
 * opens and closes itself on its dates; an Active one was opened by hand.
 *
 * `is_anonymous` is IMMUTABLE once responses exist. Flipping it afterwards would
 * either retroactively expose people who answered believing they were anonymous,
 * or discard identities already collected — see SurveyService::assertEditable().
 */
class HrSurvey extends Model
{
    use Auditable, BelongsToTenant;

    public const DRAFT = 'Draft';
    public const SCHEDULED = 'Scheduled';
    public const ACTIVE = 'Active';
    public const CLOSED = 'Closed';
    public const ARCHIVED = 'Archived';

    public const STATUSES = [self::DRAFT, self::SCHEDULED, self::ACTIVE, self::CLOSED, self::ARCHIVED];

    public const AUDIENCES = ['All', 'Department', 'Designation'];

    protected $table = 'hr_surveys';

    protected $fillable = [
        'tenant_id', 'category_id', 'title', 'description', 'instructions',
        'status', 'is_anonymous', 'starts_at', 'ends_at',
        'audience', 'department_id', 'designation_id', 'allow_multiple_responses',
        'published_at', 'closed_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_anonymous'             => 'boolean',
        'allow_multiple_responses' => 'boolean',
        'starts_at'                => 'datetime',
        'ends_at'                  => 'datetime',
        'published_at'             => 'datetime',
        'closed_at'                => 'datetime',
    ];

    public function questions()
    {
        return $this->hasMany(HrSurveyQuestion::class, 'survey_id')->orderBy('sort_order');
    }

    public function responses()
    {
        return $this->hasMany(HrSurveyResponse::class, 'survey_id');
    }

    public function category()
    {
        return $this->belongsTo(HrSurveyCategory::class, 'category_id');
    }

    /** Open for responses right now. */
    public function isOpen(): bool
    {
        if ($this->status !== self::ACTIVE) {
            return false;
        }
        $now = Carbon::now();
        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }
        if ($this->ends_at && $now->gt($this->ends_at)) {
            return false;
        }

        return true;
    }
}
