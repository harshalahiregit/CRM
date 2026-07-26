<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/**
 * Onboarding document — Identity / Compliance document OR a task attachment
 * (task_id set). New table; the candidate hr_onboarding_documents table is
 * untouched.
 */
class HrEmployeeOnboardingDocument extends Model
{
    protected $table = 'hr_employee_onboarding_documents';

    protected $guarded = ['id'];

    protected $casts = [
        'size_kb'     => 'integer',
        'verified_at' => 'datetime',
    ];

    public function onboarding()
    {
        return $this->belongsTo(HrEmployeeOnboarding::class, 'onboarding_id');
    }

    public function task()
    {
        return $this->belongsTo(HrEmployeeOnboardingTask::class, 'task_id');
    }
}
