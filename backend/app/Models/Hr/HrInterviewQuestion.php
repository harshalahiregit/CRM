<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * #10 — one entry in the interview question bank.
 *
 * Human-scored by default. Only MCQ carries an answer key, and even then the
 * scoring happens in the round (HrInterviewRoundQuestion) rather than through a
 * quiz attempt — this is the interviewer's judgement, not a machine's.
 */
class HrInterviewQuestion extends Model
{
    use Auditable, BelongsToTenant;

    public const MCQ = 'mcq';
    public const SUBJECTIVE = 'subjective';
    public const CODING = 'coding';
    public const PRACTICAL = 'practical';
    public const BEHAVIOURAL = 'behavioural';
    public const TECHNICAL = 'technical';
    public const HR = 'hr';

    public const TYPES = [
        self::MCQ, self::SUBJECTIVE, self::CODING,
        self::PRACTICAL, self::BEHAVIOURAL, self::TECHNICAL, self::HR,
    ];

    /** The only type with an answer key. Everything else is scored by a person. */
    public const AUTO_SCORABLE = [self::MCQ];

    public const DIFFICULTIES = ['easy', 'medium', 'hard', 'expert'];

    protected $table = 'hr_interview_questions';

    protected $fillable = [
        'tenant_id', 'question_text', 'question_type', 'category', 'designation_id',
        'skills', 'tags', 'difficulty', 'experience_min', 'experience_max',
        'options', 'expected_answer', 'marks', 'time_limit_seconds', 'is_active',
        'source', 'ai_meta', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'skills'         => 'array',
        'tags'           => 'array',
        'options'        => 'array',
        'ai_meta'        => 'array',
        'is_active'      => 'boolean',
        'marks'          => 'decimal:2',
        'experience_min' => 'decimal:1',
        'experience_max' => 'decimal:1',
    ];

    public function designation()
    {
        return $this->belongsTo(HrDesignation::class, 'designation_id');
    }

    /**
     * The correct option values, for the one type that has any.
     *
     * More than one may be flagged — that IS "multiple correct answers where
     * applicable", so no separate single/multiple type is needed.
     */
    public function correctOptions(): array
    {
        return collect($this->options ?: [])
            ->filter(fn ($o) => ! empty($o['is_correct']))
            ->pluck('text')->values()->all();
    }
}
