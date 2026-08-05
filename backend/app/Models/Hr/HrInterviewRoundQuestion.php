<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * #10 — a question actually asked in one interview round, and its evaluation.
 *
 * Carries a SNAPSHOT of the question text. An interview record must say what was
 * asked on the day; editing the bank six months later must not silently rewrite
 * a completed evaluation.
 */
class HrInterviewRoundQuestion extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_interview_round_questions';

    protected $fillable = [
        'tenant_id', 'interview_round_id', 'question_id', 'question_text_snapshot',
        'question_type', 'marks', 'score', 'answer_notes', 'selected_options',
        'is_correct', 'selection_mode', 'sort_order',
    ];

    protected $casts = [
        'selected_options' => 'array',
        'is_correct'       => 'boolean',
        'marks'            => 'decimal:2',
        'score'            => 'decimal:2',
        'sort_order'       => 'integer',
    ];

    public function question()
    {
        return $this->belongsTo(HrInterviewQuestion::class, 'question_id');
    }

    public function round()
    {
        return $this->belongsTo(HrInterviewRound::class, 'interview_round_id');
    }
}
