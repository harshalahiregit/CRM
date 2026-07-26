<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Training Quiz (L&D Phase 5) — a scored quiz on a training assignment.
 * Percentage + passed are computed in the service. Tenant-scoped, audited.
 */
class HrTrainingQuiz extends Model
{
    use Auditable;

    protected $table = 'hr_training_quizzes';

    protected $fillable = [
        'tenant_id', 'employee_training_id', 'quiz_name',
        'total_marks', 'obtained_marks', 'percentage', 'passed', 'remarks',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'total_marks'    => 'decimal:2',
        'obtained_marks' => 'decimal:2',
        'percentage'     => 'decimal:2',
        'passed'         => 'boolean',
    ];

    public function assignment()
    {
        return $this->belongsTo(HrEmployeeTraining::class, 'employee_training_id');
    }
}
