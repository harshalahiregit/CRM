<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeOnboarding;
use App\Models\Hr\HrEmployeeOnboardingTask;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Hr\EmployeeOnboardingStage as Stage;
use App\Support\Hr\EmployeeOnboardingStatus as Status;
use App\Support\Hr\OnboardingAbility;
use App\Support\Hr\OnboardingTaskCategory as TaskCat;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Employee Onboarding — service layer (Sprint 1 foundation).
 *
 * Isolated from the candidate onboarding (HrOnboarding) and recruitment. All DB
 * access + business logic live here; row-level tenancy is enforced on every
 * query; mutations audit via the shared Auditable trait and (where relevant)
 * dispatch through NotificationService. Actor scope is resolved centrally so the
 * same methods will serve a future authenticated ESS actor.
 */
class EmployeeOnboardingService
{
    /** Profile columns owned by each editable 1:1 section. */
    private const SECTION_FIELDS = [
        'personal'   => ['first_name', 'middle_name', 'last_name', 'dob', 'gender', 'marital_status', 'blood_group', 'father_name', 'mother_name', 'nationality', 'religion'],
        'contact'    => ['personal_email', 'official_email', 'mobile_phone', 'alternate_phone'],
        'address'    => ['current_address', 'current_city', 'current_state', 'current_pincode', 'current_country', 'permanent_address', 'permanent_city', 'permanent_state', 'permanent_pincode', 'permanent_country', 'same_as_current'],
        'emergency'  => ['emergency_name', 'emergency_relationship', 'emergency_phone', 'emergency_alt_phone', 'emergency_address'],
        'bank'       => ['bank_account_holder_name', 'bank_account_number', 'bank_ifsc', 'bank_name', 'bank_branch', 'bank_account_type'],
        'compliance' => ['pan_number', 'aadhaar_number', 'uan_number', 'pf_number', 'esic_number', 'esic_ip_number', 'pf_nominee_name', 'pf_nominee_relation', 'is_international_worker', 'has_previous_pf', 'tax_regime'],

        // ── Candidate onboarding form (EMPLOYEES ONBOARDING FORM) ──
        // Every one of these writes to hr_employee_onboarding_profile through the
        // same saveSection() path, so section status, audit and stage all reuse
        // the existing engine.
        'application' => ['applied_for_company', 'designation_applied_for'],
        'identity'    => ['full_name', 'dob', 'blood_group', 'marital_status', 'complete_address_with_pin'],
        'health'      => ['covid_vaccinated', 'physical_fitness_level', 'mental_health_stress_level'],
        'joining'     => ['core_experience', 'expected_joining_date', 'salary_expected_or_confirmed', 'worked_here_before', 'applied_here_before'],
        'skills'      => ['skills_to_manage_job'],
        'bond'        => ['ready_to_sign_bond', 'has_pf_esic_uan'],
        'declaration' => ['declaration_accepted', 'declaration_accepted_at'],
    ];

    public function __construct(private NotificationService $notifications)
    {
    }

    /* ─────────────────────────────────── Dashboard ─────────────────────────────────── */
    public function dashboard(int $tenantId): array
    {
        $base = HrEmployeeOnboarding::where('tenant_id', $tenantId);
        $today = Carbon::today();

        $byStatus = fn (string $s) => (clone $base)->where('status', $s)->count();

        // Average completion time (days) across completed onboardings.
        $completed = (clone $base)->where('status', Status::COMPLETED)
            ->whereNotNull('started_at')->whereNotNull('completed_at')->get(['started_at', 'completed_at']);
        $avgDays = $completed->isEmpty() ? null
            : round($completed->avg(fn ($o) => $o->started_at->diffInDays($o->completed_at)), 1);

        return [
            'cards' => [
                'total'             => (clone $base)->count(),
                'total_employees'   => HrEmployee::where('tenant_id', $tenantId)->count(),
                'pending'           => $byStatus(Status::PENDING),
                'in_progress'       => $byStatus(Status::IN_PROGRESS),
                'waiting_documents' => $byStatus(Status::WAITING_DOCUMENTS),
                'waiting_hr'        => $byStatus(Status::WAITING_HR),
                'waiting_manager'   => $byStatus(Status::WAITING_MANAGER),
                'completed'         => $byStatus(Status::COMPLETED),
            ],
            'recent_joining'       => $this->joiningList((clone $base)->whereBetween('joining_date', [$today->copy()->subDays(30), $today])->orderByDesc('joining_date')),
            'upcoming_joining'     => $this->joiningList((clone $base)->whereBetween('joining_date', [$today, $today->copy()->addDays(30)])->orderBy('joining_date')),
            'overdue_joining'      => $this->joiningList((clone $base)->where('status', '!=', Status::COMPLETED)->whereNotNull('target_completion_date')->whereDate('target_completion_date', '<', $today)->orderBy('target_completion_date')),
            'avg_completion_days'  => $avgDays,
        ];
    }

    private function joiningList($query): array
    {
        return $query->with('employee:id,name,employee_code,designation,department')->limit(10)->get()
            ->map(fn ($o) => [
                'id'            => $o->id,
                'employee'      => $o->employee?->name,
                'employee_code' => $o->employee?->employee_code,
                'designation'   => $o->employee?->designation,
                'department'    => $o->employee?->department,
                'joining_date'  => optional($o->joining_date)->toDateString(),
                'status'        => $o->status,
                'stage'         => $o->current_stage,
                'progress'      => $o->progress_percent,
            ])->all();
    }

    /* ─────────────────────────────────── List (paginated) ─────────────────────────────────── */
    public function list(int $tenantId, array $filters = [])
    {
        $perPage = min((int) ($filters['per_page'] ?? 12), 100);
        $sort    = $filters['sort'] ?? 'created_at';
        $dir     = strtolower($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sortable = ['created_at', 'joining_date', 'progress_percent', 'status', 'current_stage'];
        $sort = in_array($sort, $sortable, true) ? $sort : 'created_at';

        return HrEmployeeOnboarding::where('tenant_id', $tenantId)
            ->when(! empty($filters['status']) && $filters['status'] !== 'All', fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['stage']) && $filters['stage'] !== 'All', fn ($q) => $q->where('current_stage', $filters['stage']))
            ->when(! empty($filters['search']), function ($q) use ($filters) {
                $s = '%'.$filters['search'].'%';
                $q->whereHas('employee', fn ($e) => $e->where('name', 'like', $s)
                    ->orWhere('employee_code', 'like', $s)
                    ->orWhere('department', 'like', $s)
                    ->orWhere('designation', 'like', $s));
            })
            ->with('employee:id,name,employee_code,designation,department,joining_date')
            ->orderBy($sort, $dir)
            ->paginate($perPage)
            ->through(fn ($o) => [
                'id'            => $o->id,
                'employee_id'   => $o->employee_id,
                'employee'      => $o->employee?->name,
                'employee_code' => $o->employee?->employee_code,
                'designation'   => $o->employee?->designation,
                'department'    => $o->employee?->department,
                'status'        => $o->status,
                'status_label'  => Status::label($o->status),
                'stage'         => $o->current_stage,
                'stage_label'   => Stage::label($o->current_stage),
                'progress'      => $o->progress_percent,
                'joining_date'  => optional($o->joining_date)->toDateString(),
                'hr_owner_id'   => $o->hr_owner_id,
                'manager_name'  => $o->manager_name,
                'created_at'    => optional($o->created_at)->toIso8601String(),
            ]);
    }

    /* ─────────────────────────────────── Create from employee ─────────────────────────────────── */
    public function createFromEmployee(int $employeeId, User $user, array $overrides = []): HrEmployeeOnboarding
    {
        $employee = HrEmployee::where('tenant_id', $user->tenant_id)->findOrFail($employeeId);

        if (HrEmployeeOnboarding::where('employee_id', $employee->id)->exists()) {
            throw new BusinessException('This employee already has an onboarding record.', 422);
        }

        return DB::transaction(function () use ($employee, $user, $overrides) {
            $joining = $employee->joining_date;
            $onboarding = HrEmployeeOnboarding::create([
                'tenant_id'              => $user->tenant_id,
                'employee_id'            => $employee->id,
                'candidate_id'           => $employee->candidate_id,
                'onboarding_id'          => $employee->onboarding_id,
                'status'                 => Status::PENDING,
                'current_stage'          => Stage::EMPLOYEE_CREATED,
                'progress_percent'       => Stage::progress(Stage::EMPLOYEE_CREATED),
                'hr_owner_id'            => $overrides['hr_owner_id'] ?? $user->id,
                'manager_name'           => $overrides['manager_name'] ?? $employee->reporting_manager_name,
                'joining_date'           => $joining,
                'target_completion_date' => $joining ? Carbon::parse($joining)->copy() : Carbon::today()->addDays(7),
                'started_at'             => now(),
            ]);

            // Prefill the profile from the employee record (name split + contact + personal).
            [$first, $last] = $this->splitName($employee->name);
            $onboarding->profile()->create([
                'tenant_id'      => $user->tenant_id,
                'first_name'     => $first,
                'last_name'      => $last,
                'dob'            => $employee->dob,
                'gender'         => $employee->gender,
                'personal_email' => $employee->email,
                'mobile_phone'   => $employee->phone,
                'current_address' => $employee->address,
            ]);

            $this->seedTasks($onboarding, $user->tenant_id);
            $this->seedSectionStatuses($onboarding, $user->tenant_id);

            $onboarding->recordAudit('Onboarding Started', $user, null, [
                'employee_code' => $employee->employee_code,
                'stage'         => Stage::EMPLOYEE_CREATED,
            ], $this->actorLabel($user));

            // Informational notification to the employee (safe no-op if no email).
            $this->notifications->email(
                $employee->email,
                'Your onboarding has started',
                "Hi {$employee->name},\n\nYour onboarding process has been initiated. Our HR team will guide you through the steps.\n\nRegards,\nHR Team",
                ['event' => 'onboarding_started', 'onboarding_id' => $onboarding->id]
            );

            Log::channel('hr')->info('Employee onboarding created', ['onboarding_id' => $onboarding->id, 'employee_id' => $employee->id, 'tenant_id' => $user->tenant_id]);

            // Best-effort notifications — a mail failure must never roll back onboarding.
            try {
                $emp = $onboarding->employee;
                if ($emp?->email) {
                    $this->notifications->email($emp->email, 'Your onboarding has started',
                        'Hello '.$emp->name.', your employee onboarding has been started. Please complete the pending sections.',
                        ['onboarding_id' => $onboarding->id, 'event' => 'employee_onboarding_started']);
                }
                if ($user->email) {
                    $this->notifications->email($user->email, 'Employee onboarding started — '.($emp->name ?? ''),
                        'Onboarding has been opened for '.($emp->name ?? 'the employee').'.',
                        ['onboarding_id' => $onboarding->id, 'event' => 'employee_onboarding_started']);
                }
            } catch (\Throwable $e) {
                Log::channel('hr')->warning('Onboarding start notification failed', ['onboarding_id' => $onboarding->id, 'error' => $e->getMessage()]);
            }

            return $onboarding->load('employee');
        });
    }

    private function seedTasks(HrEmployeeOnboarding $onboarding, int $tenantId): void
    {
        $sort = 0;
        foreach (TaskCat::DEFAULT_TASKS as $category => $rows) {
            foreach ($rows as $row) {
                $onboarding->tasks()->create([
                    'tenant_id'    => $tenantId,
                    'category'     => $category,
                    'title'        => $row['title'],
                    'status'       => TaskCat::STATUS_PENDING,
                    'is_mandatory' => $row['is_mandatory'],
                    'owner_role'   => $row['owner_role'],
                    'source'       => 'System',
                    'sort_order'   => $sort++,
                ]);
            }
        }
    }

    private function seedSectionStatuses(HrEmployeeOnboarding $onboarding, int $tenantId): void
    {
        foreach (OnboardingAbility::SECTIONS as $section) {
            if ($section === 'overview') {
                continue;
            }
            $onboarding->sectionStatuses()->create([
                'tenant_id' => $tenantId,
                'section'   => $section,
                'status'    => 'Draft',
            ]);
        }
    }

    /* ─────────────────────────────────── Show (workspace payload) ─────────────────────────────────── */
    public function show(HrEmployeeOnboarding $onboarding, string $scope = OnboardingAbility::HR): array
    {
        $onboarding->load([
            'employee', 'profile', 'education', 'experience', 'family', 'assets',
            'tasks', 'documents', 'sectionStatuses', 'auditLogs.actor',
            'references', 'orientationFeedback',
        ]);

        $emp = $onboarding->employee;
        $tasksByCat = $onboarding->tasks->groupBy('category')->map->values();
        $sections = $onboarding->sectionStatuses->keyBy('section');

        return [
            'onboarding' => [
                'id'               => $onboarding->id,
                'status'           => $onboarding->status,
                'status_label'     => Status::label($onboarding->status),
                'current_stage'    => $onboarding->current_stage,
                'current_stage_label' => Stage::label($onboarding->current_stage),
                'progress_percent' => $onboarding->progress_percent,
                // Link to the candidate onboarding record, so the HR verification panel
                // can call the EXISTING overall-approval endpoint. Read-only exposure.
                'candidate_onboarding_id' => $onboarding->onboarding_id,
                'verification_status'     => optional($onboarding->candidateOnboarding)->verification_status,
                'joining_date'     => optional($onboarding->joining_date)->toDateString(),
                'target_completion_date' => optional($onboarding->target_completion_date)->toDateString(),
                'hr_owner_id'      => $onboarding->hr_owner_id,
                'manager_name'     => $onboarding->manager_name,
                'self_service_enabled' => $onboarding->self_service_enabled,
                'started_at'       => optional($onboarding->started_at)->toIso8601String(),
                'completed_at'     => optional($onboarding->completed_at)->toIso8601String(),
                'hr_approved_at'   => optional($onboarding->hr_approved_at)->toIso8601String(),
                'manager_approved_at' => optional($onboarding->manager_approved_at)->toIso8601String(),
            ],
            'overview' => [
                'employee_id'    => $emp?->id,
                'name'           => $emp?->name,
                'employee_code'  => $emp?->employee_code,
                'designation'    => $emp?->designation,
                'department'     => $emp?->department,
                'manager'        => $onboarding->manager_name ?? $emp?->reporting_manager_name,
                'hr_owner_id'    => $onboarding->hr_owner_id,
                'joining_date'   => optional($onboarding->joining_date)->toDateString(),
                'status'         => Status::label($onboarding->status),
                'progress'       => $onboarding->progress_percent,
                'current_step'   => Stage::label($onboarding->current_stage),
            ],
            'tracker' => [
                'steps'        => Stage::steps(),
                'current'      => $onboarding->current_stage,
                'current_index' => Stage::index($onboarding->current_stage),
                'progress'     => $onboarding->progress_percent,
            ],
            'profile'         => $onboarding->profile,
            'education'       => $onboarding->education,
            'experience'      => $onboarding->experience,
            'family'          => $onboarding->family,
            'assets'          => $onboarding->assets,
            'references'          => $onboarding->references,
            'orientation_feedback' => $onboarding->orientationFeedback,
            'tasks'           => $tasksByCat,
            'documents'       => $onboarding->documents,
            'section_status'  => $sections,
            'abilities'       => OnboardingAbility::mapFor($scope),
            'audit_logs'      => $onboarding->auditLogs->map(fn ($l) => [
                'id'         => $l->id,
                'action'     => $l->action,
                'actor_name' => $l->actor_name,
                'actor_role' => $l->actor_role,
                'comment'    => $l->comment,
                'metadata'   => $l->metadata,
                'created_at' => optional($l->created_at)->toIso8601String(),
            ])->values(),
        ];
    }

    /* ─────────────────────────────────── Save a 1:1 section ─────────────────────────────────── */
    public function saveSection(HrEmployeeOnboarding $onboarding, string $section, array $data, ?User $user): HrEmployeeOnboarding
    {
        if (! isset(self::SECTION_FIELDS[$section])) {
            throw new BusinessException("Unknown section: {$section}", 422);
        }

        $fields = array_intersect_key($data, array_flip(self::SECTION_FIELDS[$section]));

        $profile = $onboarding->profile()->firstOrCreate(['tenant_id' => $onboarding->tenant_id]);
        $profile->fill($fields)->save();

        $this->markSection($onboarding, in_array($section, ['contact', 'address', 'emergency'], true) ? 'personal' : $section, $user);

        $onboarding->recordAudit(ucfirst($section).' details saved', $user, null, ['section' => $section], $this->actorLabel($user));
        $this->touchStage($onboarding, $section);

        return $onboarding->fresh(['profile']);
    }

    /* ─────────────────────────────────── Child collections ─────────────────────────────────── */
    public function saveEducation(HrEmployeeOnboarding $o, array $data, ?User $user, ?int $id = null)
    {
        $row = $id
            ? $o->education()->findOrFail($id)
            : $o->education()->make(['tenant_id' => $o->tenant_id]);
        $row->fill($data)->onboarding_id = $o->id;
        $row->tenant_id = $o->tenant_id;
        $row->save();
        $this->markSection($o, 'education', $user);
        $o->recordAudit($id ? 'Education updated' : 'Education added', $user, $data['degree'] ?? null, [], $this->actorLabel($user));
        $this->touchStage($o, 'education');

        return $row;
    }

    public function saveExperience(HrEmployeeOnboarding $o, array $data, ?User $user, ?int $id = null)
    {
        $row = $id ? $o->experience()->findOrFail($id) : $o->experience()->make();
        $row->fill($data);
        $row->onboarding_id = $o->id;
        $row->tenant_id = $o->tenant_id;
        $row->save();
        $this->markSection($o, 'experience', $user);
        $o->recordAudit($id ? 'Experience updated' : 'Experience added', $user, $data['company_name'] ?? null, [], $this->actorLabel($user));
        $this->touchStage($o, 'experience');

        return $row;
    }

    /** Professional reference (1:many) — same pattern as education/experience. */
    public function saveReference(HrEmployeeOnboarding $o, array $data, ?User $user, ?int $id = null)
    {
        $row = $id ? $o->references()->findOrFail($id) : $o->references()->make();
        $row->fill($data);
        $row->onboarding_id = $o->id;
        $row->tenant_id = $o->tenant_id;
        $row->save();
        $this->markSection($o, 'references', $user);
        $o->recordAudit($id ? 'Reference updated' : 'Reference added', $user, $data['reference_name'] ?? null, [], $this->actorLabel($user));

        return $row;
    }

    /**
     * Orientation feedback (1:1). Only available once the employee has actually
     * joined — the form is a post-joining review, not part of pre-offer onboarding.
     */
    public function saveOrientationFeedback(HrEmployeeOnboarding $o, array $data, ?User $user)
    {
        if (! $o->employee_id) {
            throw new BusinessException('Orientation feedback becomes available after the employee has joined.', 422);
        }

        $row = $o->orientationFeedback()->firstOrNew([]);
        $row->fill($data);
        $row->onboarding_id = $o->id;
        $row->tenant_id = $o->tenant_id;
        $row->submitted_at = now();
        $row->save();

        $this->markSection($o, 'orientation', $user);
        $o->recordAudit('Orientation feedback submitted', $user, null, ['rating' => $data['overall_orientation_rating'] ?? null], $this->actorLabel($user));
        $this->touchStage($o, 'orientation');

        return $row->fresh();
    }

    public function saveFamily(HrEmployeeOnboarding $o, array $data, ?User $user, ?int $id = null)
    {
        $row = $id ? $o->family()->findOrFail($id) : $o->family()->make();
        $row->fill($data);
        $row->onboarding_id = $o->id;
        $row->tenant_id = $o->tenant_id;
        $row->save();
        $o->recordAudit($id ? 'Family member updated' : 'Family member added', $user, $data['member_name'] ?? null, [], $this->actorLabel($user));

        return $row;
    }

    public function saveAsset(HrEmployeeOnboarding $o, array $data, ?User $user, ?int $id = null)
    {
        $row = $id ? $o->assets()->findOrFail($id) : $o->assets()->make();
        $row->fill($data);
        $row->onboarding_id = $o->id;
        $row->tenant_id = $o->tenant_id;
        $row->save();
        $this->markSection($o, 'assets', $user);
        $o->recordAudit($id ? 'Asset updated' : 'Asset allocated', $user, $data['asset_type'] ?? null, [], $this->actorLabel($user));
        $this->touchStage($o, 'assets');

        return $row;
    }

    public function deleteChild(HrEmployeeOnboarding $o, string $relation, int $id, ?User $user): void
    {
        $o->{$relation}()->where('id', $id)->delete();
        $o->recordAudit(ucfirst($relation).' entry removed', $user, null, ['id' => $id], $this->actorLabel($user));
    }

    /* ─────────────────────────────────── Tasks ─────────────────────────────────── */
    public function updateTask(HrEmployeeOnboarding $o, HrEmployeeOnboardingTask $task, array $data, User $user): HrEmployeeOnboardingTask
    {
        $wasStatus = $task->status;
        $task->fill(array_intersect_key($data, array_flip([
            'status', 'assigned_to', 'assigned_to_name', 'due_date', 'remarks',
        ])));

        if (($data['status'] ?? null) === TaskCat::STATUS_COMPLETED && $wasStatus !== TaskCat::STATUS_COMPLETED) {
            $task->completed_by = $user->id;
            $task->completed_by_name = $user->name;
            $task->completed_at = now();
        }
        $task->save();

        $o->recordAudit('Checklist task updated', $user, $task->title, [
            'category' => $task->category, 'status' => $task->status,
        ], $this->actorLabel($user));

        return $task;
    }

    /* ─────────────────────────────────── Stage / status ─────────────────────────────────── */
    public function setStage(HrEmployeeOnboarding $o, string $stage, User $user): HrEmployeeOnboarding
    {
        if (! in_array($stage, Stage::ORDER, true)) {
            throw new BusinessException("Invalid stage: {$stage}", 422);
        }
        $from = $o->current_stage;
        $o->current_stage = $stage;
        $o->progress_percent = Stage::progress($stage);
        $o->status = $this->deriveStatus($stage);
        if ($stage === Stage::COMPLETED && ! $o->completed_at) {
            $o->completed_at = now();
        }
        $o->save();

        $o->recordAudit('Stage advanced', $user, null, ['from' => $from, 'to' => $stage], $this->actorLabel($user));

        return $o;
    }

    /* ─────────────────────────────────── Internal helpers ─────────────────────────────────── */
    private function deriveStatus(string $stage): string
    {
        return match ($stage) {
            Stage::OFFER_ACCEPTED, Stage::EMPLOYEE_CREATED => Status::PENDING,
            Stage::DOCUMENTS_VERIFICATION, Stage::COMPLIANCE_VERIFICATION => Status::WAITING_DOCUMENTS,
            Stage::HR_APPROVAL => Status::WAITING_HR,
            Stage::MANAGER_APPROVAL => Status::WAITING_MANAGER,
            Stage::COMPLETED => Status::COMPLETED,
            default => Status::IN_PROGRESS,
        };
    }

    /**
     * When a data section is saved, nudge the tracker forward if it's still on an
     * earlier stage — never move it backwards. Keeps the progress honest without
     * forcing manual stage clicks for the data-capture stages.
     */
    private function touchStage(HrEmployeeOnboarding $o, string $section): void
    {
        $map = [
            'personal'   => Stage::PERSONAL_INFORMATION,
            'contact'    => Stage::PERSONAL_INFORMATION,
            'address'    => Stage::PERSONAL_INFORMATION,
            'emergency'  => Stage::PERSONAL_INFORMATION,
            'education'  => Stage::EDUCATION,
            'experience' => Stage::EMPLOYMENT_HISTORY,
            'bank'       => Stage::BANK_PAYROLL,
            'assets'     => Stage::IT_ASSET_ALLOCATION,
        ];
        $target = $map[$section] ?? null;
        if (! $target) {
            return;
        }
        if (Stage::index($o->current_stage) < Stage::index($target)) {
            $o->current_stage = $target;
            $o->progress_percent = Stage::progress($target);
            $o->status = $this->deriveStatus($target);
            $o->{$this->stageStampColumn($target)} = now();
            $o->save();
        }
    }

    private function stageStampColumn(string $stage): string
    {
        return match ($stage) {
            Stage::PERSONAL_INFORMATION => 'personal_done_at',
            Stage::EDUCATION            => 'education_done_at',
            Stage::EMPLOYMENT_HISTORY   => 'experience_done_at',
            Stage::BANK_PAYROLL         => 'bank_done_at',
            Stage::IT_ASSET_ALLOCATION  => 'it_asset_done_at',
            default                     => 'started_at',
        };
    }

    /**
     * HR saving a section still marks it Verified (unchanged behaviour). A candidate
     * saving from the public portal has no User, so the section is marked Submitted
     * and left for HR to verify — the existing HR verify flow is untouched.
     */
    private function markSection(HrEmployeeOnboarding $o, string $section, ?User $user): void
    {
        $o->sectionStatuses()->updateOrCreate(
            ['section' => $section],
            $user
                ? ['tenant_id' => $o->tenant_id, 'status' => 'Verified', 'verified_by' => $user->id, 'verified_at' => now()]
                : ['tenant_id' => $o->tenant_id, 'status' => 'Submitted', 'submitted_at' => now()]
        );
    }

    /**
     * HR verification of a single section. The only writer of Verified / Rejected /
     * Correction Requested + remarks — section_status stays the single source of truth
     * and every decision is audited through the existing trail.
     */
    public function verifySection(HrEmployeeOnboarding $o, string $section, string $status, ?string $remarks, User $user): HrEmployeeOnboarding
    {
        if (! in_array($status, ['Verified', 'Rejected', 'Correction Requested'], true)) {
            throw new BusinessException("Unknown verification status: {$status}", 422);
        }

        $o->sectionStatuses()->updateOrCreate(
            ['section' => $section],
            [
                'tenant_id'   => $o->tenant_id,
                'status'      => $status,
                'remarks'     => $remarks,
                'verified_by' => $user->id,
                'verified_at' => now(),
            ]
        );

        $o->recordAudit("Section {$status}: {$section}", $user, $remarks, ['section' => $section, 'status' => $status]);

        return $o->fresh(['sectionStatuses']);
    }

    /** Label used on the audit trail when the write came from the public candidate portal. */
    public const PORTAL_ACTOR = 'Candidate (Portal)';

    private function actorLabel(?User $user): ?string
    {
        return $user ? null : self::PORTAL_ACTOR;
    }

    private function splitName(?string $name): array
    {
        $parts = preg_split('/\s+/', trim((string) $name), 2);

        return [$parts[0] ?? null, $parts[1] ?? null];
    }
}
