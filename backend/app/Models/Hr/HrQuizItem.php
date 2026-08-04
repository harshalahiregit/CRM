<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * #25 — a bank question's place in a quiz.
 *
 * `marks_override` lets one quiz weight a shared question differently without
 * forking it into a second bank entry.
 */
class HrQuizItem extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_quiz_items';

    protected $fillable = ['tenant_id', 'quiz_id', 'question_id', 'marks_override', 'sort_order'];

    protected $casts = ['marks_override' => 'decimal:2', 'sort_order' => 'integer'];

    public function question()
    {
        return $this->belongsTo(HrQuizQuestion::class, 'question_id');
    }

    public function quiz()
    {
        return $this->belongsTo(HrQuiz::class, 'quiz_id');
    }

    public function marks(): float
    {
        return (float) ($this->marks_override ?? $this->question?->marks ?? 0);
    }
}
