<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/** Education row (1:many). */
class HrEmployeeOnboardingEducation extends Model
{
    protected $table = 'hr_employee_onboarding_education';

    protected $guarded = ['id'];

    public function onboarding()
    {
        return $this->belongsTo(HrEmployeeOnboarding::class, 'onboarding_id');
    }
}
