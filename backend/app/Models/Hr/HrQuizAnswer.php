<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * #25 — what the employee picked, and what it scored.
 *
 * `selected_option_ids` is always an array, even for a single-choice question, so
 * every reader handles exactly one shape.
 */
class HrQuizAnswer extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_quiz_answers';

    protected $fillable = [
        'tenant_id', 'attempt_id', 'question_id', 'selected_option_ids',
        'is_correct', 'marks_awarded',
    ];

    protected $casts = [
        'selected_option_ids' => 'array',
        'is_correct'          => 'boolean',
        'marks_awarded'       => 'decimal:2',
    ];

    public function question()
    {
        return $this->belongsTo(HrQuizQuestion::class, 'question_id');
    }
}
