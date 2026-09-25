<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrApprovalRequest;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeVariableEarning;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\Approval\WorkflowConfigService;
use App\Services\Hr\VariableEarningService;
use App\Support\Hr\Approval\ApprovalProcess;
use App\Support\Hr\Approval\ApprovalState;
use App\Support\Hr\Approval\ApproverType;
use App\Support\Hr\DataScope;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Variable earnings on the approval workflow engine — Phase 3.
 *
 * Two things make this process different from leave and loans, and both are
 * pinned here because getting either wrong costs somebody money:
 *
 *   PAYROLL. linesFor() pulls only APPROVED earnings into a run, so an earning
 *   halfway up a ladder is still Pending and cannot be paid. That is the
 *   property the multi-step tests actually guard.
 *
 *   RE-APPROVAL. VariableEarningService::save() resets an edited earning to
 *   Pending and clears its approval — "editing the figure invalidates the
 *   approval it was granted under". So unlike leave and loans, a record
 *   legitimately comes back for a SECOND decision, and the engine has to open a
 *   fresh round rather than hand back the closed one.
 */
class VariableEarningApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $componentId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'vewf', 'status' => 'active']);
        $this->componentId = $this->salaryComponent();
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function salaryComponent(string $type = 'Earning'): int
    {
        return DB::table('hr_salary_components')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'name' => 'C'.substr(uniqid(), -5), 'code' => 'C'.substr(uniqid(), -4),
            'type' => $type, 'calculation_type' => 'Fixed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function role(string $scope, string $key): StaffRole
    {
        return StaffRole::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'R'.$key, 'slug' => 'r_'.strtolower($key),
            'permissions' => ['hr_employees' => [StaffPermission::VIEW_GLOBAL]],
            'scope' => $scope, 'is_system' => false,
        ]);
    }

    private function user(string $email, ?StaffRole $role = null, string $userRole = 'staff'): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'U'.substr($email, 0, 4),
            'email' => $email, 'password' => Hash::make('Password123!'),
            'role' => $userRole, 'status' => 'active', 'staff_role_id' => $role?->id,
        ]);
    }

    private function employee(array $attrs = []): HrEmployee
    {
        return HrEmployee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => 'E'.substr(uniqid(), -5),
            'employee_code' => 'E'.substr(uniqid(), -6),
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2024-01-01', 'status' => 'Active',
        ], $attrs));
    }

    private function earning(
        HrEmployee $employee,
        string $status = HrEmployeeVariableEarning::PENDING,
        float $amount = 5000,
        ?int $componentId = null
    ): HrEmployeeVariableEarning {
        return HrEmployeeVariableEarning::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'component_id' => $componentId ?: $this->componentId,
            'period' => '2026-06', 'amount' => $amount, 'status' => $status,
        ]);
    }

    private function saveLadder(array $steps, bool $active = true): void
    {
        app(WorkflowConfigService::class)->save($this->tenant->id, ApprovalProcess::VARIABLE_EARNING, [
            'name' => 'Variable earning approval', 'is_active' => $active, 'steps' => $steps,
        ]);
    }

    private function approveVia(HrEmployeeVariableEarning $e)
    {
        return $this->postJson("/api/hr/variable-earnings/{$e->id}/approve");
    }

    private function rejectVia(HrEmployeeVariableEarning $e, string $remarks = 'no')
    {
        return $this->postJson("/api/hr/variable-earnings/{$e->id}/reject", ['remarks' => $remarks]);
    }

    private function approvedAudits(HrEmployeeVariableEarning $e): int
    {
        return DB::table('audit_logs')
            ->where('auditable_type', HrEmployeeVariableEarning::class)
            ->where('auditable_id', $e->id)
            ->where('action', 'Variable earning approved')->count();
    }

    /* ── 1-2. legacy compatibility and tenancy ────────────────────────── */

    public function test_with_no_ladder_configured_an_earning_approves_as_before(): void
    {
        $hr = $this->user('hr@ve.test', null, 'admin');
        $earning = $this->earning($this->employee());

        Sanctum::actingAs($hr);
        $this->approveVia($earning)->assertOk();

        $this->assertSame(HrEmployeeVariableEarning::APPROVED, $earning->fresh()->status);
    }

    public function test_the_legacy_rung_still_refuses_a_non_hr_user(): void
    {
        $nobody = $this->user('nobody@ve.test');
        $earning = $this->earning($this->employee());

        Sanctum::actingAs($nobody);
        $this->approveVia($earning)->assertStatus(403);

        $this->assertSame(HrEmployeeVariableEarning::PENDING, $earning->fresh()->status);
    }

    public function test_a_ladder_does_not_leak_to_another_tenant(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'vewf-o', 'status' => 'active']);
        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER]]);

        $shown = app(WorkflowConfigService::class)->show($other->id, ApprovalProcess::VARIABLE_EARNING);

        $this->assertNull($shown['workflow']);
        $this->assertSame([], $shown['steps']);
    }

    /* ── 3-4. capability and scope ────────────────────────────────────── */

    public function test_an_approver_without_the_capability_is_refused(): void
    {
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'NoCap', 'slug' => 'nocap_ve',
            'permissions' => [], 'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);
        $approver = $this->user('nocap@ve.test', $role);
        $earning = $this->earning($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::SPECIFIC_USER, 'approver_ref' => $approver->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($earning)->assertStatus(403);

        $this->assertSame(HrEmployeeVariableEarning::PENDING, $earning->fresh()->status);
    }

    public function test_an_out_of_scope_approver_is_refused_even_when_named(): void
    {
        $deptRole = $this->role(DataScope::DEPARTMENT, 'DeptVe');
        $approver = $this->user('dept@ve.test', $deptRole);
        $this->employee(['user_id' => $approver->id, 'department' => 'Ops']);

        $outsider = $this->employee(['department' => 'Sales']);
        $earning = $this->earning($outsider);

        $this->saveLadder([['approver_type' => ApproverType::SPECIFIC_USER, 'approver_ref' => $approver->id]]);

        Sanctum::actingAs($approver);

        // Named on the step and still refused — approver membership grants no
        // access to somebody else's employee record.
        $this->approveVia($earning)->assertStatus(404);
        $this->assertSame(HrEmployeeVariableEarning::PENDING, $earning->fresh()->status);
    }

    /* ── 5-7. sequence, and the payroll property it protects ──────────── */

    public function test_a_single_step_ladder_approves_on_one_decision(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'One');
        $approver = $this->user('one@ve.test', $role);
        $earning = $this->earning($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($earning)->assertOk();

        $this->assertSame(HrEmployeeVariableEarning::APPROVED, $earning->fresh()->status);
    }

    public function test_an_intermediate_approval_leaves_the_earning_out_of_payroll(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinVe');
        $financeUser = $this->user('fin@ve.test', $finance);

        $managerUser = $this->user('mgr@ve.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $earning = $this->earning($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($managerUser);
        $this->approveVia($earning)->assertOk();

        $fresh = $earning->fresh();
        $this->assertSame(HrEmployeeVariableEarning::PENDING, $fresh->status);
        $this->assertNull($fresh->approved_at);

        // The property that matters: payroll reads APPROVED only, so a
        // half-approved commission cannot be paid.
        $lines = app(VariableEarningService::class)
            ->linesFor($employee->id, $this->tenant->id, '2026-06');
        $this->assertSame([], $lines, 'A mid-ladder earning must not enter payroll.');

        $request = HrApprovalRequest::where('subject_id', $earning->id)
            ->where('subject_type', HrEmployeeVariableEarning::class)->first();
        $this->assertSame(2, $request->current_step);
    }

    public function test_the_final_approval_runs_the_domain_action_once_and_enters_payroll(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinVe2');
        $financeUser = $this->user('fin2@ve.test', $finance);

        $managerUser = $this->user('mgr2@ve.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $earning = $this->earning($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($managerUser);
        $this->approveVia($earning)->assertOk();

        Sanctum::actingAs($financeUser);
        $this->approveVia($earning)->assertOk();

        $this->assertSame(HrEmployeeVariableEarning::APPROVED, $earning->fresh()->status);
        $this->assertSame(1, $this->approvedAudits($earning));

        // And now it is payable.
        $lines = app(VariableEarningService::class)
            ->linesFor($employee->id, $this->tenant->id, '2026-06');
        $this->assertCount(1, $lines);
        $this->assertSame(5000.0, $lines[0]['amount']);
    }

    public function test_the_second_rung_cannot_act_before_the_first(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinVe3');
        $financeUser = $this->user('fin3@ve.test', $finance);

        $managerUser = $this->user('mgr3@ve.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $earning = $this->earning($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($financeUser);
        $this->approveVia($earning)->assertStatus(403);

        $this->assertSame(HrEmployeeVariableEarning::PENDING, $earning->fresh()->status);
    }

    /* ── 8. amount conditions ─────────────────────────────────────────── */

    public function test_a_large_earning_picks_up_the_extra_rung(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinAmtVe');
        $financeUser = $this->user('finamt@ve.test', $finance);

        $managerUser = $this->user('mgramt@ve.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id,
             'name' => 'Finance', 'conditions' => ['min_amount' => 100000]],
        ]);

        $small = $this->earning($employee, HrEmployeeVariableEarning::PENDING, 5000);
        Sanctum::actingAs($managerUser);
        $this->approveVia($small)->assertOk();
        $this->assertSame(HrEmployeeVariableEarning::APPROVED, $small->fresh()->status);

        $large = $this->earning($employee, HrEmployeeVariableEarning::PENDING, 250000);
        $this->approveVia($large)->assertOk();
        $this->assertSame(HrEmployeeVariableEarning::PENDING, $large->fresh()->status);

        Sanctum::actingAs($financeUser);
        $this->approveVia($large)->assertOk();
        $this->assertSame(HrEmployeeVariableEarning::APPROVED, $large->fresh()->status);
    }

    public function test_the_amount_is_read_from_the_database_not_the_request(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinAmtVe2');
        $this->user('finamt2@ve.test', $finance);
        $managerUser = $this->user('mgramt2@ve.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id,
             'name' => 'Finance', 'conditions' => ['min_amount' => 100000]],
        ]);

        $large = $this->earning($employee, HrEmployeeVariableEarning::PENDING, 250000);

        Sanctum::actingAs($managerUser);
        $this->postJson("/api/hr/variable-earnings/{$large->id}/approve", ['amount' => 1])->assertOk();

        $this->assertSame(HrEmployeeVariableEarning::PENDING, $large->fresh()->status,
            'A claimed amount must not shrink the ladder.');
    }

    /* ── 9-10. rejection and blocking ─────────────────────────────────── */

    public function test_rejection_is_terminal_and_keeps_the_domain_behaviour(): void
    {
        $managerUser = $this->user('mgrrej@ve.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $earning = $this->earning($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Skip', 'levels_up' => 2],
        ]);

        Sanctum::actingAs($managerUser);
        $this->rejectVia($earning, 'not this quarter')->assertOk();

        $fresh = $earning->fresh();
        $this->assertSame(HrEmployeeVariableEarning::REJECTED, $fresh->status);
        // The existing domain behaviour: the reason lands on the record.
        $this->assertSame('not this quarter', $fresh->remarks);

        $this->assertSame(ApprovalState::REJECTED, HrApprovalRequest::where('subject_id', $earning->id)
            ->where('subject_type', HrEmployeeVariableEarning::class)->first()->state);
    }

    public function test_an_unresolvable_rung_blocks_and_never_approves(): void
    {
        $employee = $this->employee();              // no reporting manager
        $earning = $this->earning($employee);

        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER]]);

        $hr = $this->user('hrblock@ve.test', null, 'admin');
        Sanctum::actingAs($hr);

        $this->approveVia($earning)->assertStatus(409);

        $this->assertSame(HrEmployeeVariableEarning::PENDING, $earning->fresh()->status);
        $this->assertSame(ApprovalState::BLOCKED, HrApprovalRequest::where('subject_id', $earning->id)
            ->where('subject_type', HrEmployeeVariableEarning::class)->first()->state);
    }

    /* ── 11. the snapshot ─────────────────────────────────────────────── */

    public function test_editing_the_ladder_does_not_reroute_an_earning_in_progress(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinSnapVe');
        $financeUser = $this->user('finsnap@ve.test', $finance);

        $managerUser = $this->user('mgrsnap@ve.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $earning = $this->earning($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($managerUser);
        $this->approveVia($earning)->assertOk();

        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager only']]);

        $request = HrApprovalRequest::where('subject_id', $earning->id)
            ->where('subject_type', HrEmployeeVariableEarning::class)->first();
        $this->assertCount(2, $request->steps_snapshot);
        $this->assertSame(2, $request->current_step);

        Sanctum::actingAs($financeUser);
        $this->approveVia($earning)->assertOk();
        $this->assertSame(HrEmployeeVariableEarning::APPROVED, $earning->fresh()->status);
    }

    /* ── 12. duplicate decisions ──────────────────────────────────────── */

    public function test_a_second_approval_cannot_run_the_domain_action_again(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'DupVe');
        $approver = $this->user('dup@ve.test', $role);
        $earning = $this->earning($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($earning)->assertOk();

        // VariableEarningService::approve() does NOT refuse an already-approved
        // earning — it only guards PAID — so unlike loans there is no domain
        // guard behind this. The refusal has to come from here.
        $this->approveVia($earning)->assertStatus(422);

        $this->assertSame(1, $this->approvedAudits($earning));
    }

    /* ── 13. bypass ───────────────────────────────────────────────────── */

    public function test_posted_step_fields_cannot_skip_the_ladder(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'BypVe');
        $financeUser = $this->user('byp@ve.test', $finance);

        $managerUser = $this->user('mgrbyp@ve.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $earning = $this->earning($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($financeUser);
        $this->postJson("/api/hr/variable-earnings/{$earning->id}/approve", [
            'step' => 2, 'current_step' => 2, 'final' => true, 'status' => 'approved',
        ])->assertStatus(403);

        $this->assertSame(HrEmployeeVariableEarning::PENDING, $earning->fresh()->status);
    }

    /* ── re-approval after an edit — the behaviour unique to this process ─ */

    public function test_editing_an_approved_earning_lets_it_be_approved_again(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'ReVe');
        $approver = $this->user('re@ve.test', $role);
        $employee = $this->employee();
        $earning = $this->earning($employee, HrEmployeeVariableEarning::PENDING, 5000);

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($earning)->assertOk();
        $this->assertSame(HrEmployeeVariableEarning::APPROVED, $earning->fresh()->status);

        // Editing the figure invalidates the approval — the service resets it
        // to Pending and clears approved_by/at.
        app(VariableEarningService::class)->save([
            'id' => $earning->id, 'employee_id' => $employee->id,
            'component_id' => $this->componentId, 'period' => '2026-06', 'amount' => 7000,
        ], $this->tenant->id, $approver);

        $this->assertSame(HrEmployeeVariableEarning::PENDING, $earning->fresh()->status);

        // The new figure must be approvable. Before the engine opened a fresh
        // round per decision, this returned 403 for ever: the closed request
        // from the first round was handed back and mayAct() refused it, leaving
        // the earning pending and unapprovable.
        $this->approveVia($earning)->assertOk();
        $this->assertSame(HrEmployeeVariableEarning::APPROVED, $earning->fresh()->status);
        $this->assertSame(7000.0, (float) $earning->fresh()->amount);
    }

    public function test_the_first_rounds_history_survives_a_second_round(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'HistVe');
        $approver = $this->user('hist@ve.test', $role);
        $employee = $this->employee();
        $earning = $this->earning($employee, HrEmployeeVariableEarning::PENDING, 5000);

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($earning)->assertOk();

        app(VariableEarningService::class)->save([
            'id' => $earning->id, 'employee_id' => $employee->id,
            'component_id' => $this->componentId, 'period' => '2026-06', 'amount' => 7000,
        ], $this->tenant->id, $approver);

        $this->approveVia($earning)->assertOk();

        // Two rounds, two decisions, both kept — an auditor can see that 5,000
        // was approved, the figure changed, and 7,000 was approved separately.
        $requests = HrApprovalRequest::where('subject_id', $earning->id)
            ->where('subject_type', HrEmployeeVariableEarning::class)->get();
        $this->assertCount(2, $requests);
        $this->assertSame(2, DB::table('hr_approval_actions')
            ->whereIn('approval_request_id', $requests->pluck('id'))->count());
    }

    /* ── payroll safety ───────────────────────────────────────────────── */

    public function test_a_paid_earning_keeps_its_existing_refusal(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'PaidVe');
        $approver = $this->user('paid@ve.test', $role);
        $earning = $this->earning($this->employee(), HrEmployeeVariableEarning::PAID);

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);

        // Payroll has already consumed it, so the service's own message is what
        // the user sees — not a generic workflow refusal.
        $this->approveVia($earning)->assertStatus(422)
            ->assertJsonPath('message', 'This earning has already been paid');

        $this->assertSame(HrEmployeeVariableEarning::PAID, $earning->fresh()->status);
    }

    public function test_raising_and_editing_are_not_gated_by_the_ladder(): void
    {
        $hr = $this->user('raise@ve.test', null, 'admin');
        $employee = $this->employee();

        // A ladder is configured, and raising a commission is still not an
        // approval — it is the request for one.
        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER]]);

        Sanctum::actingAs($hr);
        $created = $this->postJson('/api/hr/variable-earnings', [
            'employee_id' => $employee->id, 'component_id' => $this->componentId,
            'period' => '2026-07', 'amount' => 3000,
        ])->assertCreated()->json();

        $id = $created['data']['id'] ?? $created['id'] ?? null;
        $this->assertNotNull($id);
        $this->assertSame(HrEmployeeVariableEarning::PENDING,
            HrEmployeeVariableEarning::find($id)->status);
    }

    /* ── settings surface ─────────────────────────────────────────────── */

    public function test_variable_earnings_is_configurable_alongside_the_others(): void
    {
        $admin = $this->user('admin@ve.test', null, 'admin');

        Sanctum::actingAs($admin);
        $keys = array_column($this->getJson('/api/hr/approval-workflows')->assertOk()->json('data'), 'process');

        $this->assertContains(ApprovalProcess::VARIABLE_EARNING, $keys);
        $this->assertContains(ApprovalProcess::LEAVE, $keys);
        $this->assertContains(ApprovalProcess::LOAN, $keys);
    }

    public function test_the_process_offers_amount_and_component_conditions(): void
    {
        $admin = $this->user('admin2@ve.test', null, 'admin');

        Sanctum::actingAs($admin);
        $shown = $this->getJson('/api/hr/approval-workflows/variable_earning')->assertOk()->json();

        $this->assertTrue($shown['options']['supports_amount']);
        $this->assertContains('component_id', $shown['options']['conditions']);
    }
}
