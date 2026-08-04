<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * #44 — one question on an exit questionnaire.
 *
 * The type vocabulary is HrSurveyQuestion's, referenced rather than copied: an
 * exit questionnaire asks the same KINDS of question an employee survey does, and
 * two independently-maintained lists would drift apart.
 */
class HrExitQuestionnaireQuestion extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_exit_questionnaire_questions';

    public const TYPES = HrSurveyQuestion::TYPES;
    public const CHOICE_TYPES = HrSurveyQuestion::CHOICE_TYPES;

    protected $fillable = [
        'tenant_id', 'questionnaire_id', 'question_text', 'question_type',
        'options', 'rating_max', 'is_required', 'sort_order',
    ];

    protected $casts = [
        'options'     => 'array',
        'rating_max'  => 'integer',
        'is_required' => 'boolean',
        'sort_order'  => 'integer',
    ];

    public function questionnaire()
    {
        return $this->belongsTo(HrExitQuestionnaire::class, 'questionnaire_id');
    }
}
