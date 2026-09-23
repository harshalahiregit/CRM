<?php

namespace App\Models\Hr;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

class HrEmployee extends Model
{
    use Auditable;

    /**
     * Keep department_id / designation_id in step with the names on every save.
     *
     * Both forms, the SangoeTrack importer and the onboarding conversion all
     * write the text and none of them wrote the link, so the org chart and the
     * reporting rollups — which read the FK — saw an empty company. Doing it
     * here means no caller has to remember. See OrgLink for why an unknown name
     * creates a record rather than being dropped.
     */
    protected static function booted(): void
    {
        static::saving(fn (self $employee) => \App\Support\Hr\OrgLink::apply($employee));
    }

    protected $table = 'hr_employees';

    /** Employee codes read SNE-YYYY-NNN. */
    public const CODE_PREFIX = 'SNE-';

    /**
     * The next free employee code for a tenant.
     *
     * There were two generators and both could hand back a code that was already
     * taken. EmployeeService used `count() + 1`, which reuses a code the moment
     * anyone is deleted — five employees, delete the third, and the next create
     * asks for -005 while -005 already exists. The SangoeTrack importer looped
     * `where tenant_id = ? and employee_code = ?` against what was then a GLOBAL
     * unique index, so it could exit that loop believing a code was free while
     * another tenant held it.
     *
     * This takes the highest sequence actually issued rather than counting rows,
     * which is immune to deletions, and it is the single source both callers use.
     * Same shape as Estimate::nextLocalReference, so the two read alike.
     *
     * The caller is expected to retry on a unique violation: two simultaneous
     * creates can still read the same MAX, and the index — not this method — is
     * what guarantees uniqueness. See EmployeeService::create.
     */
    public static function nextEmployeeCode(int $tenantId): string
    {
        $prefix = self::CODE_PREFIX . date('Y') . '-';

        $highest = static::query()
            ->where('tenant_id', $tenantId)
            ->where('employee_code', 'like', $prefix . '%')
            ->selectRaw('MAX(CAST(SUBSTR(employee_code, ?) AS INTEGER)) AS seq', [strlen($prefix) + 1])
            ->value('seq');

        return $prefix . str_pad((string) (((int) $highest) + 1), 3, '0', STR_PAD_LEFT);
    }

    protected $fillable = [
        'tenant_id','user_id','candidate_id','onboarding_id','employee_code',
        'name','email','phone','dob','gender','address','department','designation',
        'department_id','designation_id','grade_id','job_role_id',
        // The tenant's own employment classification — Permanent, Contract,
        // Intern, whatever they configured. Null means none chosen; there was
        // never a legacy string on this table to inherit from.
        'employment_type_id',
        'reporting_manager_name','reporting_manager_id',
        // `location` is the office/city (free text, unchanged). `work_state` is the
        // statutory jurisdiction Professional Tax is levied under — the two are NOT
        // interchangeable, which is why PT no longer reads `location`.
        'location','work_state','shift','official_email','project_id',
        // Grouping ABOVE department, which every payroll report subtotals by, and
        // the skill category that decides which minimum-wage floor applies.
        'branch','division','unit','zone','category',
        // The salary register's DOLeft, and pay withheld for this run.
        'exit_date','hold_salary',
        // Stronger than deactivating, and a different thing from hold_salary:
        // hold is a temporary withholding for somebody still employed, this is
        // a decision that the person is not re-engaged. MUST be listed — create()
        // drops any key not whitelisted.
        'blacklisted','blacklist_reason','blacklisted_at',
        // #43 — the individual's own skills, carried from the candidate on hire.
        // Compared against the department/designation/grade/role skill profile.
        'skills',
        'joining_date','probation_end_date','confirmation_date','status',
        // A standing notice period for this person. NULL means "inherit" —
        // the exit policy matched to their grade, then the exit type's
        // default. 0 is a real value meaning no notice is served.
        'notice_days',
        // #29 — what this person is, and whether they belong on the org chart.
        // MUST be listed here: create() silently drops any key not whitelisted,
        // so an omission would leave every new hire on the default.
        'worker_type','include_in_org_chart',
        // Whether this person may sign in to the attendance app. Granted by HR,
        // never assumed — see the migration for why it defaults to off.
        'app_login_enabled',
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
        'app_login_enabled'    => 'boolean',
        'joining_date'         => 'date',
        'exit_date'            => 'date',
        'hold_salary'          => 'boolean',
        'blacklisted'          => 'boolean',
        'blacklisted_at'       => 'datetime',
        'dob'                  => 'date',
        'probation_end_date'   => 'date',
        'confirmation_date'    => 'date',
        // 'integer' leaves null as null, which is what keeps "no override"
        // distinguishable from an explicit zero.
        'notice_days'          => 'integer',
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

    public function employmentType()
    {
        return $this->belongsTo(HrEmploymentType::class, 'employment_type_id');
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

    /**
     * Personal, bank, identity and statutory detail.
     *
     * A separate row created on demand: most of it is blank for most people, and
     * these are the fields most likely to need their own permission later.
     */
    public function detail()
    {
        return $this->hasOne(HrEmployeeDetail::class, 'employee_id');
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
