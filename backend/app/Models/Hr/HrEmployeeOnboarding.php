<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use App\Support\Hr\EmployeeOnboardingStage as Stage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Employee Onboarding process — 1:1 with an HrEmployee, post "Employee Created".
 * Isolated from the candidate onboarding (HrOnboarding). Auditable → the shared
 * polymorphic audit_logs feeds the AuditTimeline with zero extra schema.
 */
class HrEmployeeOnboarding extends Model
{
    use Auditable, HasFactory;

    protected $table = 'hr_employee_onboardings';

    protected $fillable = [
        'tenant_id', 'employee_id', 'candidate_id', 'onboarding_id', 'offer_id',
        'status', 'current_stage', 'progress_percent',
        'hr_owner_id', 'manager_id', 'manager_name', 'joining_date', 'target_completion_date',
        'started_at', 'personal_done_at', 'education_done_at', 'experience_done_at',
        'documents_verified_at', 'compliance_verified_at', 'bank_done_at', 'it_asset_done_at',
        'orientation_done_at', 'training_done_at',
        'hr_approved_by', 'hr_approved_at', 'hr_remarks',
        'manager_approved_by', 'manager_approved_at', 'manager_remarks', 'completed_at',
        'self_service_enabled', 'ess_invited_at', 'ess_first_login_at', 'ess_submitted_at',
    ];

    protected $casts = [
        'joining_date'           => 'date',
        'target_completion_date' => 'date',
        'progress_percent'       => 'integer',
        'self_service_enabled'   => 'boolean',
        'started_at'             => 'datetime',
        'personal_done_at'       => 'datetime',
        'education_done_at'      => 'datetime',
        'experience_done_at'     => 'datetime',
        'documents_verified_at'  => 'datetime',
        'compliance_verified_at' => 'datetime',
        'bank_done_at'           => 'datetime',
        'it_asset_done_at'       => 'datetime',
        'orientation_done_at'    => 'datetime',
        'training_done_at'       => 'datetime',
        'hr_approved_at'         => 'datetime',
        'manager_approved_at'    => 'datetime',
        'completed_at'           => 'datetime',
        'ess_invited_at'         => 'datetime',
        'ess_first_login_at'     => 'datetime',
        'ess_submitted_at'       => 'datetime',
    ];

    /* ── Relationships ── */
    public function employee()
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function profile()
    {
        return $this->hasOne(HrEmployeeOnboardingProfile::class, 'onboarding_id');
    }

    public function education()
    {
        return $this->hasMany(HrEmployeeOnboardingEducation::class, 'onboarding_id')->orderBy('sort_order');
    }

    public function experience()
    {
        return $this->hasMany(HrEmployeeOnboardingExperience::class, 'onboarding_id')->orderBy('sort_order');
    }

    public function family()
    {
        return $this->hasMany(HrEmployeeOnboardingFamily::class, 'onboarding_id')->orderBy('sort_order');
    }

    // Assets are Inventory records, not onboarding records. The list an
    // onboarding shows comes from EmployeeOnboardingService::assetsFor().

    public function backgroundVerification()
    {
        return $this->hasOne(HrBackgroundVerification::class, 'onboarding_id');
    }

    public function tasks()
    {
        return $this->hasMany(HrEmployeeOnboardingTask::class, 'onboarding_id')->orderBy('sort_order');
    }

    public function documents()
    {
        return $this->hasMany(HrEmployeeOnboardingDocument::class, 'onboarding_id')->latest('id');
    }

    /** The pre-offer candidate onboarding record this form belongs to. */
    public function candidateOnboarding()
    {
        return $this->belongsTo(HrOnboarding::class, 'onboarding_id');
    }

    /** Professional references (1:many). */
    public function references()
    {
        return $this->hasMany(HrEmployeeOnboardingReference::class, 'onboarding_id')->orderBy('sort_order');
    }

    /** Orientation feedback (1:1) — post-joining only. */
    public function orientationFeedback()
    {
        return $this->hasOne(HrEmployeeOnboardingOrientationFeedback::class, 'onboarding_id');
    }

    public function sectionStatuses()
    {
        return $this->hasMany(HrEmployeeOnboardingSectionStatus::class, 'onboarding_id');
    }

    /* ── Helpers ── */
    public function stageLabel(): string
    {
        return Stage::label($this->current_stage);
    }

    public function recomputeProgress(): int
    {
        return Stage::progress($this->current_stage);
    }
}
