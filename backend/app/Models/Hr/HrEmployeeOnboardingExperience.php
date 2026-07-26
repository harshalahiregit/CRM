<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/** Employment history row (1:many). */
class HrEmployeeOnboardingExperience extends Model
{
    protected $table = 'hr_employee_onboarding_experience';

    protected $guarded = ['id'];

    protected $casts = [
        'from_date' => 'date',
        'to_date'   => 'date',
        'last_ctc'  => 'decimal:2',
    ];

    public function onboarding()
    {
        return $this->belongsTo(HrEmployeeOnboarding::class, 'onboarding_id');
    }
}
