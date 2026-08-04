<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** #25 — one possible answer. `is_correct` is the answer key. */
class HrQuizQuestionOption extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_quiz_question_options';

    protected $fillable = ['tenant_id', 'question_id', 'option_text', 'is_correct', 'sort_order'];

    protected $casts = ['is_correct' => 'boolean', 'sort_order' => 'integer'];

    public function question()
    {
        return $this->belongsTo(HrQuizQuestion::class, 'question_id');
    }
}
