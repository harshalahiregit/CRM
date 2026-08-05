<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * #26 — one answer.
 *
 * Each question type writes its own typed column. A rating stored as text cannot
 * be averaged without parsing, and averaging ratings is the point of the
 * analytics — so `answer_number` is a real decimal.
 */
class HrSurveyAnswer extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_survey_answers';

    protected $fillable = [
        'tenant_id', 'response_id', 'question_id',
        'answer_text', 'answer_number', 'answer_boolean', 'selected_options',
    ];

    protected $casts = [
        'answer_number'    => 'decimal:2',
        'answer_boolean'   => 'boolean',
        'selected_options' => 'array',
    ];

    public function question()
    {
        return $this->belongsTo(HrSurveyQuestion::class, 'question_id');
    }

    public function response()
    {
        return $this->belongsTo(HrSurveyResponse::class, 'response_id');
    }

    /** Whether anything was actually answered — used for required-question checks. */
    public function isAnswered(): bool
    {
        return $this->answer_text !== null
            || $this->answer_number !== null
            || $this->answer_boolean !== null
            || ! empty($this->selected_options);
    }
}
