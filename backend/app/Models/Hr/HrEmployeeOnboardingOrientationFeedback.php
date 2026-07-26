<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/**
 * Orientation feedback (1:1) — captured only AFTER the employee has joined.
 * Employee Name / Position / Department are intentionally NOT stored here; they are
 * read from the existing candidate / onboarding records.
 */
class HrEmployeeOnboardingOrientationFeedback extends Model
{
    protected $table = 'hr_employee_onboarding_orientation_feedback';

    protected $guarded = ['id'];

    protected $casts = ['date_of_orientation' => 'date', 'submitted_at' => 'datetime'];

    public function onboarding()
    {
        return $this->belongsTo(HrEmployeeOnboarding::class, 'onboarding_id');
    }
}
