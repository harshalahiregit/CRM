<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * #44 — one answer to one templated exit question.
 *
 * Typed columns rather than a single stringly value, so ratings stay numeric and
 * remain reportable.
 */
class HrExitInterviewAnswer extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_exit_interview_answers';

    protected $fillable = [
        'tenant_id', 'exit_interview_id', 'question_id',
        'answer_text', 'answer_rating', 'answer_boolean', 'answer_options',
    ];

    protected $casts = [
        'answer_options' => 'array',
        'answer_rating'  => 'integer',
        'answer_boolean' => 'boolean',
    ];

    public function question()
    {
        return $this->belongsTo(HrExitQuestionnaireQuestion::class, 'question_id');
    }
}
