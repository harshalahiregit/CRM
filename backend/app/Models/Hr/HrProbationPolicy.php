<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Probation Policy (Probation Phase 1). Points at a mandatory Probation Type and,
 * optionally, a Grade / Designation / Department from Organization Setup (reused,
 * not duplicated). Tenant-scoped; never hard-deleted (deactivate to retire).
 */
class HrProbationPolicy extends Model
{
    use Auditable;

    protected $table = 'hr_probation_policies';

    protected $fillable = [
        'tenant_id', 'name', 'probation_type_id', 'department_id', 'designation_id', 'grade_id',
        'review_frequency', 'notice_days', 'extension_limit', 'auto_confirmation',
        'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'notice_days'       => 'integer',
        'extension_limit'   => 'integer',
        'auto_confirmation' => 'boolean',
        'is_active'         => 'boolean',
    ];

    public function probationType()
    {
        return $this->belongsTo(HrProbationType::class, 'probation_type_id');
    }

    public function department()
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    public function designation()
    {
        return $this->belongsTo(HrDesignation::class, 'designation_id');
    }

    public function grade()
    {
        return $this->belongsTo(HrGrade::class, 'grade_id');
    }
}
