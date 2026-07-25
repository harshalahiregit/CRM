<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Leave Policy (Leave Phase 1). Optionally scoped to a Grade / Designation from
 * Organization Setup (reused, not duplicated). Maps to leave types via policyTypes.
 */
class HrLeavePolicy extends Model
{
    use Auditable;

    protected $table = 'hr_leave_policies';

    protected $fillable = [
        'tenant_id', 'name', 'applies_to', 'grade_id', 'designation_id',
        'probation_allowed', 'notice_period_allowed', 'weekends_count', 'holidays_count',
        'half_day_allowed', 'negative_balance_allowed', 'description', 'is_active',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'probation_allowed'        => 'boolean',
        'notice_period_allowed'    => 'boolean',
        'weekends_count'           => 'boolean',
        'holidays_count'           => 'boolean',
        'half_day_allowed'         => 'boolean',
        'negative_balance_allowed' => 'boolean',
        'is_active'                => 'boolean',
    ];

    public function policyTypes()
    {
        return $this->hasMany(HrLeavePolicyType::class, 'policy_id');
    }

    public function grade()
    {
        return $this->belongsTo(HrGrade::class, 'grade_id');
    }

    public function designation()
    {
        return $this->belongsTo(HrDesignation::class, 'designation_id');
    }
}
