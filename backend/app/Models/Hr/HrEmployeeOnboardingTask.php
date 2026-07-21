<?php

namespace App\Models\Hr;

use Illuminate\Database\Eloquent\Model;

/**
 * Universal trackable checklist task (Orientation / Training / IT Setup /
 * HR Checklist / Manager Checklist / General). Carries the full task attribute
 * set: status, assigned_to, due_date, completed_by, completed_date, remarks,
 * and attachments (via documents.task_id). owner_role / visible_to_employee are
 * dormant ESS flags.
 */
class HrEmployeeOnboardingTask extends Model
{
    protected $table = 'hr_employee_onboarding_tasks';

    protected $guarded = ['id'];

    protected $casts = [
        'due_date'            => 'date',
        'completed_at'        => 'datetime',
        'is_mandatory'        => 'boolean',
        'visible_to_employee' => 'boolean',
    ];

    public function onboarding()
    {
        return $this->belongsTo(HrEmployeeOnboarding::class, 'onboarding_id');
    }

    public function attachments()
    {
        return $this->hasMany(HrEmployeeOnboardingDocument::class, 'task_id');
    }
}
