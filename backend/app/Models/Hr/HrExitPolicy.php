<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Exit Policy (Exit Phase 1). Optionally scoped to Grade / Designation / Department
 * from Organization Setup (reused, not duplicated), with a default Exit Type.
 */
class HrExitPolicy extends Model
{
    use Auditable;

    protected $table = 'hr_exit_policies';

    protected $fillable = [
        'tenant_id', 'name', 'grade_id', 'designation_id', 'department_id', 'default_exit_type_id',
        'notice_days', 'buyout_allowed', 'recovery_allowed', 'leave_encashment', 'gratuity_applicable',
        'clearance_required', 'exit_interview_required', 'description', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'notice_days'             => 'integer',
        'buyout_allowed'          => 'boolean',
        'recovery_allowed'        => 'boolean',
        'leave_encashment'        => 'boolean',
        'gratuity_applicable'     => 'boolean',
        'clearance_required'      => 'boolean',
        'exit_interview_required' => 'boolean',
        'is_active'               => 'boolean',
    ];

    public function grade()
    {
        return $this->belongsTo(HrGrade::class, 'grade_id');
    }

    public function designation()
    {
        return $this->belongsTo(HrDesignation::class, 'designation_id');
    }

    public function department()
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    public function defaultExitType()
    {
        return $this->belongsTo(HrExitType::class, 'default_exit_type_id');
    }
}
