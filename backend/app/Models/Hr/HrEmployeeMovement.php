<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Review comments #41 (department transfer) and #42 (upgrade/degrade position).
 *
 * One record of an employee moving from one position to another. Both are the
 * same event with a different label, so they share a table and are told apart by
 * `movement_type` — see the migration for why that is not two tables.
 *
 * A movement is a HISTORICAL FACT, not a request: it is written at the moment the
 * change is applied to the employee record, and never edited afterwards. The
 * approval that may have preceded it lives on HrPromotionRecommendation.
 */
class HrEmployeeMovement extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hr_employee_movements';

    public const TRANSFER = 'Transfer';
    public const PROMOTION = 'Promotion';
    public const DEMOTION = 'Demotion';
    public const REDESIGNATION = 'Redesignation';

    public const TYPES = [self::TRANSFER, self::PROMOTION, self::DEMOTION, self::REDESIGNATION];

    protected $fillable = [
        'tenant_id', 'employee_id', 'movement_type', 'effective_date',
        'from_department_id', 'from_department', 'to_department_id', 'to_department',
        'from_designation_id', 'from_designation', 'to_designation_id', 'to_designation',
        'from_grade_id', 'to_grade_id', 'from_manager_id', 'to_manager_id',
        'promotion_recommendation_id', 'reason', 'remarks', 'actioned_by',
    ];

    protected $casts = [
        'effective_date' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function recommendation()
    {
        return $this->belongsTo(HrPromotionRecommendation::class, 'promotion_recommendation_id');
    }

    /** Human summary — "Engineering → Operations", "Engineer → Senior Engineer". */
    public function summary(): string
    {
        $parts = [];
        if ($this->from_department !== $this->to_department) {
            $parts[] = ($this->from_department ?: '—').' → '.($this->to_department ?: '—');
        }
        if ($this->from_designation !== $this->to_designation) {
            $parts[] = ($this->from_designation ?: '—').' → '.($this->to_designation ?: '—');
        }

        return $parts === [] ? 'No change recorded' : implode(' · ', $parts);
    }
}
