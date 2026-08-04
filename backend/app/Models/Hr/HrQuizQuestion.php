<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * #25 — a reusable QUESTION BANK entry.
 *
 * Lives independently of any quiz, so the same question serves an induction quiz
 * and its yearly refresher without being copied — and later fixed — twice.
 */
class HrQuizQuestion extends Model
{
    use Auditable, BelongsToTenant;

    public const SINGLE = 'single_choice';
    public const MULTIPLE = 'multiple_choice';
    public const BOOLEAN = 'boolean';

    public const TYPES = [self::SINGLE, self::MULTIPLE, self::BOOLEAN];

    protected $table = 'hr_quiz_questions';

    protected $fillable = [
        'tenant_id', 'category_id', 'question_text', 'question_type', 'marks',
        'explanation', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'marks'     => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function options()
    {
        return $this->hasMany(HrQuizQuestionOption::class, 'question_id')->orderBy('sort_order');
    }

    /** Reuses the EXISTING training category master — no parallel taxonomy. */
    public function category()
    {
        return $this->belongsTo(HrTrainingCategory::class, 'category_id');
    }

    /** The answer key: option ids marked correct. */
    public function correctOptionIds(): array
    {
        return $this->options->where('is_correct', true)->pluck('id')->map(fn ($i) => (int) $i)->values()->all();
    }
}
