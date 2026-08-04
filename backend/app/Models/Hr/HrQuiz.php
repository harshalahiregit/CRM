<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * #25 — a quiz: which bank questions, in what order, and what counts as a pass.
 *
 * The pass mark is a PERCENTAGE, not an absolute. Total marks change whenever a
 * question is added or removed, and an absolute pass mark would silently change
 * meaning underneath everyone who already sat it.
 */
class HrQuiz extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_quizzes';

    protected $fillable = [
        'tenant_id', 'name', 'code', 'training_program_id', 'description',
        'pass_percentage', 'duration_minutes', 'max_attempts', 'shuffle_questions',
        'show_correct_answers', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'pass_percentage'      => 'decimal:2',
        'shuffle_questions'    => 'boolean',
        'show_correct_answers' => 'boolean',
        'is_active'            => 'boolean',
    ];

    public function items()
    {
        return $this->hasMany(HrQuizItem::class, 'quiz_id')->orderBy('sort_order');
    }

    public function attempts()
    {
        return $this->hasMany(HrQuizAttempt::class, 'quiz_id');
    }

    /** Reuses the existing training programme master. */
    public function program()
    {
        return $this->belongsTo(HrTrainingProgram::class, 'training_program_id');
    }

    /** Total marks on offer, honouring any per-quiz override. */
    public function totalMarks(): float
    {
        return round((float) $this->items->sum(
            fn ($i) => (float) ($i->marks_override ?? $i->question?->marks ?? 0)
        ), 2);
    }
}
