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
        'department_id','designation_id','grade_id','job_role_id',
        'reporting_manager_name','reporting_manager_id',
        // `location` is the office/city (free text, unchanged). `work_state` is the
        // statutory jurisdiction Professional Tax is levied under — the two are NOT
        // interchangeable, which is why PT no longer reads `location`.
        'location','work_state','shift','official_email','project_id',
        // #43 — the individual's own skills, carried from the candidate on hire.
        // Compared against the department/designation/grade/role skill profile.
        'skills',
        'joining_date','probation_end_date','confirmation_date','status',
        // #29 — what this person is, and whether they belong on the org chart.
        // MUST be listed here: create() silently drops any key not whitelisted,
        // so an omission would leave every new hire on the default.
        'worker_type','include_in_org_chart',
        // Record origin: 'sangoetrack' when created by the importer, 'manual' or
        // null otherwise. Drives the "via SangoeTrack" badge on the employee list.
        'source',
        // SangoeTrack (track.sangoe.in) HRM link — populated by
        // `sangoetrack:map-employees` or `sangoetrack:import-employees`.
        'sangoetrack_user_id','sangoetrack_workspace_id','sangoetrack_synced_at',
    ];

    /**
     * #29 — the org chart shows the whole working organisation, not just people
     * on payroll, so a consultant or freelancer is the same kind of node as an
     * employee. The distinction is carried for labelling, not for exclusion.
     */
    public const WORKER_TYPES = ['employee', 'consultant', 'freelancer'];

    protected $casts = [
        'skills'               => 'array',
        'include_in_org_chart' => 'boolean',
        'joining_date'         => 'date',
        'dob'                  => 'date',
        'probation_end_date'   => 'date',
        'confirmation_date'    => 'date',
        'sangoetrack_user_id'  => 'integer',
        'sangoetrack_synced_at' => 'datetime',
    ];

    /**
     * Store the canonical state name whatever spelling arrives ("mh", "MAHARASHTRA",
     * "Orissa"). Unrecognised input — a city, a typo — is stored as NULL rather than
     * kept as-is: a value that can never match a rule would only look configured.
     */
    public function setWorkStateAttribute($value): void
    {
        $this->attributes['work_state'] = \App\Support\Hr\WorkStates::normalize($value);
    }

    public function candidate()
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }

    public function onboarding()
    {
        return $this->belongsTo(HrOnboarding::class, 'onboarding_id');
    }

    /** Assigned Project (existing Projects module) — carried from the candidate. */
    public function project()
    {
        return $this->belongsTo(\App\Models\Project\Project::class, 'project_id');
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
     | Organization Setup links (nullable). These sit alongside the legacy
     | free-text department / designation / reporting_manager_name columns —
     | reads that still use the strings keep working; new code prefers the FKs.
     */
    public function departmentRef()
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    public function designationRef()
    {
        return $this->belongsTo(HrDesignation::class, 'designation_id');
    }

    public function grade()
    {
        return $this->belongsTo(HrGrade::class, 'grade_id');
    }

    public function jobRole()
    {
        return $this->belongsTo(HrJobRole::class, 'job_role_id');
    }

    /** Reporting manager (another employee), when linked by id. */
    public function reportingManager()
    {
        return $this->belongsTo(HrEmployee::class, 'reporting_manager_id');
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
