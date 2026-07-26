<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/**
 * 1:1 onboarding profile — Personal, Contact, Address, Emergency, Bank and
 * Statutory Compliance single-value fields.
 */
class HrEmployeeOnboardingProfile extends Model
{
    protected $table = 'hr_employee_onboarding_profile';

    protected $guarded = ['id'];

    protected $casts = [
        'dob'                     => 'date',
        'same_as_current'         => 'boolean',
        'is_international_worker'  => 'boolean',
        'has_previous_pf'         => 'boolean',
    ];

    public function onboarding()
    {
        return $this->belongsTo(HrEmployeeOnboarding::class, 'onboarding_id');
    }
}
