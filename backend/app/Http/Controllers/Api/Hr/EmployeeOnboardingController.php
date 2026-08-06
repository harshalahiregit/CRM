<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeOnboarding;
use App\Models\Hr\HrEmployeeOnboardingTask;
use App\Services\Hr\EmployeeOnboardingService;
use App\Support\Hr\OnboardingAbility;
use Illuminate\Http\Request;

/**
 * Employee Onboarding — internal (HR) API. Thin controller: assert permission →
 * assert row-level tenancy → validate → delegate to the service → wrap in the
 * standard {status,message,data} envelope. Permission is enforced here (not on
 * the route), mirroring RecruitmentServicesController.
 */
class EmployeeOnboardingController extends Controller
{
    use ApiResponse;

    public function __construct(private EmployeeOnboardingService $service)
    {
    }

    /* ── Guards ── */
    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageOnboarding(), 403, 'You are not authorised to manage Employee Onboarding');
    }

    private function assertTenant(Request $request, HrEmployeeOnboarding $o): void
    {
        abort_unless((int) $o->tenant_id === (int) $request->user()->tenant_id, 404);
    }

    /** Actor scope — HR only in Sprint 1; the seam for future ESS resolution. */
    private function scope(Request $request): string
    {
        return OnboardingAbility::HR;
    }

    /* ── Dashboard ── */
    public function dashboard(Request $request)
    {
        $this->assertCanManage($request);

        return $this->success($this->service->dashboard($request->user()->tenant_id));
    }

    /* ── List ── */
    public function index(Request $request)
    {
        $this->assertCanManage($request);

        return $this->success($this->service->list(
            $request->user()->tenant_id,
            $request->only(['status', 'stage', 'search', 'sort', 'dir', 'per_page'])
        ));
    }

    /** Employees who don't yet have an onboarding record — the "start onboarding" picker. */
    public function eligibleEmployees(Request $request)
    {
        $this->assertCanManage($request);
        $tenantId = $request->user()->tenant_id;

        // Onboarding is meant to run off the candidate database, so the caller can
        // ask for candidate-linked employees only (`source=candidate`, the UI's
        // default). Employees created directly still appear under `source=all`,
        // because refusing them outright would strand everyone hired before the
        // candidate pipeline existed.
        $source = $request->query('source', 'all');

        // Base scope: everyone still awaiting an onboarding record.
        $base = fn () => HrEmployee::where('tenant_id', $tenantId)
            ->whereDoesntHave('employeeOnboarding');

        $employees = $base()
            ->when($source === 'candidate', fn ($q) => $q->whereNotNull('candidate_id'))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$request->search.'%')
                ->orWhere('employee_code', 'like', '%'.$request->search.'%')))
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'name', 'employee_code', 'designation', 'department', 'joining_date', 'candidate_id']);

        return $this->success([
            'employees' => $employees,
            // Counted over the FULL eligible set, not the filtered one — the toggle
            // has to say how many are hidden, which the filtered list cannot know.
            'counts'    => [
                'from_candidate' => $base()->whereNotNull('candidate_id')->count(),
                'direct_entry'   => $base()->whereNull('candidate_id')->count(),
            ],
        ]);
    }

    /* ── Create ── */
    public function store(Request $request)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'employee_id'  => 'required|integer|exists:hr_employees,id',
            'hr_owner_id'  => 'nullable|integer|exists:users,id',
            'manager_name' => 'nullable|string|max:150',
        ]);

        $onboarding = $this->service->createFromEmployee($data['employee_id'], $request->user(), $data);

        return $this->success($this->service->show($onboarding, $this->scope($request)), 'Onboarding started', 201);
    }

    /* ── Show (workspace) ── */
    public function show(Request $request, HrEmployeeOnboarding $onboarding)
    {
        $this->assertCanManage($request);
        $this->assertTenant($request, $onboarding);

        return $this->success($this->service->show($onboarding, $this->scope($request)));
    }

    /* ── Save a 1:1 section (personal/contact/address/emergency/bank/compliance) ── */
    public function saveSection(Request $request, HrEmployeeOnboarding $onboarding, string $section)
    {
        $this->assertCanManage($request);
        $this->assertTenant($request, $onboarding);

        $this->service->saveSection($onboarding, $section, $request->all(), $request->user());

        return $this->success($this->service->show($onboarding, $this->scope($request)), 'Section saved');
    }

    /* ── Education ── */
    public function storeEducation(Request $request, HrEmployeeOnboarding $onboarding)
    {
        return $this->childWrite($request, $onboarding, 'saveEducation', [
            'degree' => 'nullable|string|max:150', 'specialization' => 'nullable|string|max:150',
            'institution' => 'nullable|string|max:200', 'board_university' => 'nullable|string|max:200',
            'year_of_passing' => 'nullable|string|max:10', 'percentage_grade' => 'nullable|string|max:20',
        ]);
    }

    public function updateEducation(Request $request, HrEmployeeOnboarding $onboarding, int $id)
    {
        return $this->childWrite($request, $onboarding, 'saveEducation', [
            'degree' => 'nullable|string|max:150', 'specialization' => 'nullable|string|max:150',
            'institution' => 'nullable|string|max:200', 'board_university' => 'nullable|string|max:200',
            'year_of_passing' => 'nullable|string|max:10', 'percentage_grade' => 'nullable|string|max:20',
        ], $id);
    }

    public function destroyEducation(Request $request, HrEmployeeOnboarding $onboarding, int $id)
    {
        return $this->childDelete($request, $onboarding, 'education', $id);
    }

    /* ── Experience ── */
    public function storeExperience(Request $request, HrEmployeeOnboarding $onboarding)
    {
        return $this->childWrite($request, $onboarding, 'saveExperience', $this->experienceRules());
    }

    public function updateExperience(Request $request, HrEmployeeOnboarding $onboarding, int $id)
    {
        return $this->childWrite($request, $onboarding, 'saveExperience', $this->experienceRules(), $id);
    }

    public function destroyExperience(Request $request, HrEmployeeOnboarding $onboarding, int $id)
    {
        return $this->childDelete($request, $onboarding, 'experience', $id);
    }

    private function experienceRules(): array
    {
        return [
            'company_name' => 'nullable|string|max:200', 'designation' => 'nullable|string|max:150',
            'from_date' => 'nullable|date', 'to_date' => 'nullable|date',
            'last_ctc' => 'nullable|numeric|min:0', 'reason_for_leaving' => 'nullable|string|max:255',
        ];
    }

    /* ── Family ── */
    public function storeFamily(Request $request, HrEmployeeOnboarding $onboarding)
    {
        return $this->childWrite($request, $onboarding, 'saveFamily', $this->familyRules());
    }

    public function updateFamily(Request $request, HrEmployeeOnboarding $onboarding, int $id)
    {
        return $this->childWrite($request, $onboarding, 'saveFamily', $this->familyRules(), $id);
    }

    public function destroyFamily(Request $request, HrEmployeeOnboarding $onboarding, int $id)
    {
        return $this->childDelete($request, $onboarding, 'family', $id);
    }

    private function familyRules(): array
    {
        return [
            'member_name' => 'nullable|string|max:150', 'relationship' => 'nullable|string|max:60',
            'dob' => 'nullable|date', 'occupation' => 'nullable|string|max:120',
            'contact' => 'nullable|string|max:30', 'is_dependent' => 'boolean', 'is_nominee' => 'boolean',
        ];
    }

    /* ── Assets ──
     |
     | There is no asset CRUD here any more. Assets are Inventory records: they are
     | assigned in Inventory, which advances this onboarding's IT & Asset Allocation
     | stage through AdvanceOnboardingAssetStage. The onboarding payload's `assets`
     | key is a read of the Inventory register for the employee.
     */
    /* ── Tasks ── */
    public function updateTask(Request $request, HrEmployeeOnboarding $onboarding, HrEmployeeOnboardingTask $task)
    {
        $this->assertCanManage($request);
        $this->assertTenant($request, $onboarding);
        abort_unless((int) $task->onboarding_id === (int) $onboarding->id, 404);

        $data = $request->validate([
            'status'           => 'nullable|in:Pending,In Progress,Completed,Rejected,Skipped',
            'assigned_to'      => 'nullable|integer|exists:users,id',
            'assigned_to_name' => 'nullable|string|max:150',
            'due_date'         => 'nullable|date',
            'remarks'          => 'nullable|string|max:1000',
        ]);

        return $this->success($this->service->updateTask($onboarding, $task, $data, $request->user()), 'Task updated');
    }

    /* ── Stage ── */
    public function setStage(Request $request, HrEmployeeOnboarding $onboarding)
    {
        $this->assertCanManage($request);
        $this->assertTenant($request, $onboarding);
        $data = $request->validate(['stage' => 'required|string']);

        $this->service->setStage($onboarding, $data['stage'], $request->user());

        return $this->success($this->service->show($onboarding, $this->scope($request)), 'Stage updated');
    }

    /* ── Background Verification (Phase 4) ── */
    public function saveBackgroundVerification(Request $request, HrEmployeeOnboarding $onboarding)
    {
        $this->assertCanManage($request);
        $this->assertTenant($request, $onboarding);
        $data = $request->validate([
            'vendor'           => 'nullable|string|max:150',
            'reference_number' => 'nullable|string|max:120',
            'status'           => 'required|in:Pending,In Progress,Verified,Rejected',
            'remarks'          => 'nullable|string|max:2000',
            'completed_date'   => 'nullable|date',
        ]);
        $this->service->saveBackgroundVerification($onboarding, $data, $request->user());

        return $this->success($this->service->show($onboarding, $this->scope($request)), 'Background verification updated');
    }

    /* ── Approvals + Activation (Phase 4) ── */
    public function hrApprove(Request $request, HrEmployeeOnboarding $onboarding)
    {
        $this->assertCanManage($request);
        $this->assertTenant($request, $onboarding);
        $data = $request->validate(['remarks' => 'nullable|string|max:2000']);
        $this->service->hrApprove($onboarding, $request->user(), $data['remarks'] ?? null);

        return $this->success($this->service->show($onboarding, $this->scope($request)), 'HR approved');
    }

    public function managerApprove(Request $request, HrEmployeeOnboarding $onboarding)
    {
        $this->assertCanManage($request);
        $this->assertTenant($request, $onboarding);
        $data = $request->validate(['remarks' => 'nullable|string|max:2000']);
        $this->service->managerApprove($onboarding, $request->user(), $data['remarks'] ?? null);

        return $this->success($this->service->show($onboarding, $this->scope($request)), 'Manager approved');
    }

    public function confirmJoining(Request $request, HrEmployeeOnboarding $onboarding)
    {
        $this->assertCanManage($request);
        $this->assertTenant($request, $onboarding);
        $this->service->confirmJoining($onboarding, $request->user());

        return $this->success($this->service->show($onboarding, $this->scope($request)), 'Employee activated');
    }

    /* ── Shared child helpers ── */
    /* ── HR section verification (Approve / Reject / Request Correction) ── */
    public function verifySection(Request $request, HrEmployeeOnboarding $onboarding, string $section)
    {
        $this->assertCanManage($request);
        $this->assertTenant($request, $onboarding);
        $data = $request->validate([
            'status'  => 'required|in:Verified,Rejected,Correction Requested',
            'remarks' => 'nullable|string|max:2000',
        ]);

        $updated = $this->service->verifySection($onboarding, $section, $data['status'], $data['remarks'] ?? null, $request->user());

        return $this->success($this->service->show($updated), 'Section '.$data['status']);
    }

    /* ── Professional references (1:many) — reuses childWrite/childDelete ── */
    public function storeReference(Request $request, HrEmployeeOnboarding $onboarding)
    {
        return $this->childWrite($request, $onboarding, 'saveReference', $this->referenceRules());
    }

    public function updateReference(Request $request, HrEmployeeOnboarding $onboarding, int $id)
    {
        return $this->childWrite($request, $onboarding, 'saveReference', $this->referenceRules(), $id);
    }

    public function destroyReference(Request $request, HrEmployeeOnboarding $onboarding, int $id)
    {
        return $this->childDelete($request, $onboarding, 'references', $id);
    }

    private function referenceRules(): array
    {
        return [
            'reference_name' => 'nullable|string|max:150',
            'relationship'   => 'nullable|string|max:60',
            'phone'          => 'nullable|string|max:30',
            'email'          => 'nullable|email|max:150',
            'sort_order'     => 'nullable|integer|min:0',
        ];
    }

    /* ── Orientation feedback (1:1) — post-joining only, enforced in the service ── */
    public function saveOrientationFeedback(Request $request, HrEmployeeOnboarding $onboarding)
    {
        $this->assertCanManage($request);
        $this->assertTenant($request, $onboarding);
        $data = $request->validate([
            'date_of_orientation'          => 'nullable|date',
            'overall_orientation_rating'   => 'nullable|string|max:60',
            'information_helpfulness'      => 'nullable|string|max:60',
            'topics_covered_overall'       => 'nullable|string|max:60',
            'topic_benefits_compensation'  => 'nullable|string|max:60',
            'topic_company_culture_values' => 'nullable|string|max:60',
            'topic_job_specific_training'  => 'nullable|string|max:60',
            'topics_not_covered'           => 'nullable|string|max:2000',
            'orientation_duration'         => 'nullable|string|max:60',
            'comfort_level_starting'       => 'nullable|string|max:60',
            'additional_comments'          => 'nullable|string|max:2000',
        ]);

        $row = $this->service->saveOrientationFeedback($onboarding, $data, $request->user());

        return $this->success($row, 'Orientation feedback saved');
    }

    private function childWrite(Request $request, HrEmployeeOnboarding $onboarding, string $method, array $rules, ?int $id = null)
    {
        $this->assertCanManage($request);
        $this->assertTenant($request, $onboarding);
        $data = $request->validate($rules);

        $row = $this->service->{$method}($onboarding, $data, $request->user(), $id);

        return $this->success($row, $id ? 'Updated' : 'Added', $id ? 200 : 201);
    }

    private function childDelete(Request $request, HrEmployeeOnboarding $onboarding, string $relation, int $id)
    {
        $this->assertCanManage($request);
        $this->assertTenant($request, $onboarding);
        $this->service->deleteChild($onboarding, $relation, $id, $request->user());

        return $this->success(null, 'Removed');
    }
}
