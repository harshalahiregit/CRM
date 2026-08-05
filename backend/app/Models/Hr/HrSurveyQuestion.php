<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * #26 — one question on a survey.
 *
 * Five types, each answered into its own column on HrSurveyAnswer rather than one
 * stringly-typed value: a rating that has to be parsed out of text cannot be
 * averaged, and averaging ratings is the whole point of the analytics.
 */
class HrSurveyQuestion extends Model
{
    use BelongsToTenant;

    public const TEXT = 'text';
    public const RATING = 'rating';
    public const SINGLE = 'single_choice';
    public const MULTIPLE = 'multiple_choice';
    public const BOOLEAN = 'boolean';

    public const TYPES = [self::TEXT, self::RATING, self::SINGLE, self::MULTIPLE, self::BOOLEAN];

    /** Types whose answer comes from a fixed option list. */
    public const CHOICE_TYPES = [self::SINGLE, self::MULTIPLE];

    protected $table = 'hr_survey_questions';

    protected $fillable = [
        'tenant_id', 'survey_id', 'question_text', 'question_type',
        'options', 'rating_max', 'is_required', 'sort_order',
    ];

    protected $casts = [
        'options'     => 'array',
        'rating_max'  => 'integer',
        'is_required' => 'boolean',
        'sort_order'  => 'integer',
    ];

    public function survey()
    {
        return $this->belongsTo(HrSurvey::class, 'survey_id');
    }

    public function answers()
    {
        return $this->hasMany(HrSurveyAnswer::class, 'question_id');
    }
}
