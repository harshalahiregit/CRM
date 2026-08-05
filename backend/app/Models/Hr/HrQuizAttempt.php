<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * #25 — one employee's go at a quiz.
 *
 * Marks and the pass threshold are FROZEN onto the attempt at submission. The
 * bank question's marks or the quiz's pass percentage may change next year; an
 * attempt must keep reporting what it scored on the day it was taken.
 */
class HrQuizAttempt extends Model
{
    use Auditable, BelongsToTenant;

    public const IN_PROGRESS = 'In Progress';
    public const SUBMITTED = 'Submitted';
    public const EVALUATED = 'Evaluated';

    protected $table = 'hr_quiz_attempts';

    protected $fillable = [
        'tenant_id', 'quiz_id', 'employee_id', 'employee_training_id', 'attempt_number',
        'status', 'started_at', 'submitted_at', 'total_marks', 'obtained_marks',
        'percentage', 'pass_percentage', 'passed',
    ];

    protected $casts = [
        'started_at'      => 'datetime',
        'submitted_at'    => 'datetime',
        'total_marks'     => 'decimal:2',
        'obtained_marks'  => 'decimal:2',
        'percentage'      => 'decimal:2',
        'pass_percentage' => 'decimal:2',
        'passed'          => 'boolean',
        'attempt_number'  => 'integer',
    ];

    public function answers()
    {
        return $this->hasMany(HrQuizAnswer::class, 'attempt_id');
    }

    public function quiz()
    {
        return $this->belongsTo(HrQuiz::class, 'quiz_id');
    }

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    /** Ties the attempt to the training assignment it belongs to (#23 history). */
    public function employeeTraining()
    {
        return $this->belongsTo(HrEmployeeTraining::class, 'employee_training_id');
    }
}
