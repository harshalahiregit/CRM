<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class HrEmployee extends Model
{
    use Auditable;

    protected $table = 'hr_employees';

    protected $fillable = [
        'tenant_id','user_id','candidate_id','onboarding_id','employee_code',
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

    /** Exit Interview (SPK-1) — one per employee; answers only, never a copy of this record. */
    public function exitInterview()
    {
        return $this->hasOne(HrExitInterview::class, 'employee_id');
    }

    /**
     * ESS actor link — the login used for future Employee Self Service. Nullable;
     * the relation lives here so the User model never couples to HR entities.
     */
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /** Employee Onboarding process (post "Employee Created"). */
    public function employeeOnboarding()
    {
        return $this->hasOne(HrEmployeeOnboarding::class, 'employee_id');
    }

    /*
     | Onboarding lifecycle, derived read-only from hr_employee_onboardings.
     | HrEmployee.status is deliberately NOT touched — an employee stays Active
     | while their onboarding runs Pending -> In_Progress -> Completed.
     */
    protected $appends = ['onboarding_status', 'onboarding_progress'];

    public function getOnboardingStatusAttribute(): ?string
    {
        return $this->employeeOnboarding?->status;
    }

    public function getOnboardingProgressAttribute(): int
    {
        return (int) ($this->employeeOnboarding?->progress_percent ?? 0);
    }
}
