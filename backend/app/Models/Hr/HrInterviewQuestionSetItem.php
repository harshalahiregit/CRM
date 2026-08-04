<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * #10 — a bank question's place in a set.
 *
 * `marks_override` lets one set weight a shared question differently without
 * forking it into a second bank entry — the same approach HrQuizItem takes.
 */
class HrInterviewQuestionSetItem extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_interview_question_set_items';

    protected $fillable = ['tenant_id', 'set_id', 'question_id', 'marks_override', 'sort_order'];

    protected $casts = ['marks_override' => 'decimal:2', 'sort_order' => 'integer'];

    public function question()
    {
        return $this->belongsTo(HrInterviewQuestion::class, 'question_id');
    }

    public function set()
    {
        return $this->belongsTo(HrInterviewQuestionSet::class, 'set_id');
    }

    public function marks(): float
    {
        return (float) ($this->marks_override ?? $this->question?->marks ?? 0);
    }
}
