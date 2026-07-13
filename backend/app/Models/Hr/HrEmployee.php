<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class HrEmployee extends Model
{
    use Auditable;

    protected $table = 'hr_employees';

    protected $fillable = [
        'tenant_id','candidate_id','onboarding_id','employee_code',
        'name','email','phone','dob','gender','address','department','designation',
        'reporting_manager_name','joining_date','probation_end_date','confirmation_date','status',
    ];

    protected $casts = [
        'joining_date'       => 'date',
        'dob'                => 'date',
        'probation_end_date' => 'date',
        'confirmation_date'  => 'date',
    ];

    public function candidate()
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }

    public function onboarding()
    {
        return $this->belongsTo(HrOnboarding::class, 'onboarding_id');
    }
}
