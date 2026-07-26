<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Training Assessment (L&D Phase 5) — a marked assessment on a training
 * assignment. Percentage + Pass/Fail are computed in the service. Tenant-scoped,
 * audited.
 */
class HrTrainingAssessment extends Model
{
    use Auditable;

    protected $table = 'hr_training_assessments';

    public const PASS = 'Pass';
    public const FAIL = 'Fail';

    protected $fillable = [
        'tenant_id', 'employee_training_id', 'assessment_name',
        'total_marks', 'obtained_marks', 'passing_marks', 'percentage', 'result', 'remarks',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'total_marks'    => 'decimal:2',
        'obtained_marks' => 'decimal:2',
        'passing_marks'  => 'decimal:2',
        'percentage'     => 'decimal:2',
    ];

    public function assignment()
    {
        return $this->belongsTo(HrEmployeeTraining::class, 'employee_training_id');
    }
}
