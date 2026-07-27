<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/** Allocated asset / IT hardware row (1:many). */
class HrEmployeeOnboardingAsset extends Model
{
    protected $table = 'hr_employee_onboarding_assets';

    protected $guarded = ['id'];

    protected $casts = [
        'issued_date'   => 'date',
        'returned_date' => 'date',
    ];

    public function onboarding()
    {
        return $this->belongsTo(HrEmployeeOnboarding::class, 'onboarding_id');
    }
}
