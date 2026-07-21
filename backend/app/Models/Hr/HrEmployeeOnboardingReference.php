<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/** Professional reference row (1:many) — dedicated table so multiple references are supported. */
class HrEmployeeOnboardingReference extends Model
{
    protected $table = 'hr_employee_onboarding_references';

    protected $guarded = ['id'];

    public function onboarding()
    {
        return $this->belongsTo(HrEmployeeOnboarding::class, 'onboarding_id');
    }
}
