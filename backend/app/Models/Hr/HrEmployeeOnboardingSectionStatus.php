<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-section submit → verify status. In Sprint 1 HR edits and marks Verified.
 * The Draft → Submitted → Verified split is the seam for future Employee Self
 * Service (employee submits, HR verifies) — same table, no schema change.
 */
class HrEmployeeOnboardingSectionStatus extends Model
{
    protected $table = 'hr_employee_onboarding_section_status';

    protected $guarded = ['id'];

    protected $casts = [
        'submitted_at' => 'datetime',
        'verified_at'  => 'datetime',
    ];

    public function onboarding()
    {
        return $this->belongsTo(HrEmployeeOnboarding::class, 'onboarding_id');
    }
}
