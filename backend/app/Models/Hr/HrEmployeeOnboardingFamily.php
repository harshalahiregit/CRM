<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/** Family details row (1:many). */
class HrEmployeeOnboardingFamily extends Model
{
    protected $table = 'hr_employee_onboarding_family';

    protected $guarded = ['id'];

    protected $casts = [
        'dob'          => 'date',
        'is_dependent' => 'boolean',
        'is_nominee'   => 'boolean',
    ];

    public function onboarding()
    {
        return $this->belongsTo(HrEmployeeOnboarding::class, 'onboarding_id');
    }
}
