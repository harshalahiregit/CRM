<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrApprovalRequest;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrLeaveApplication;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\Approval\ApprovalEngine;
use App\Services\Hr\Approval\WorkflowConfigService;
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
 * The approval workflow engine, proven on Leave.
 *
 * Three things are being held here at once, and they are easy to confuse:
 *
 *   - the LADDER decides who may act and when;
 *   - the SCOPE decides whose records they may act on, unchanged;
 *   - the SERVICE still decides what approval actually does to the business
 *     record — balance ledger, status, notification.
 *
 * The tests that matter most are the ones where those three disagree: an
 * approver named on a step for an employee outside their scope, a second rung
 * approving before the first, and a workflow edited while somebody is halfway
 * up it.
 */
class ApprovalWorkflowEngineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'wf', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

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

    /**
     * A submitted application, with the balance its approval will deduct.
     *
     * The balance is part of the fixture rather than mocked away because the
     * final rung calls the real LeaveApprovalService, which writes the ledger.
     * Without it these tests would pass on a 422 and prove nothing about the
     * ladder.
     */
    private function leave(HrEmployee $employee, string $status = HrLeaveApplication::SUBMITTED): HrLeaveApplication
    {
        DB::table('hr_employee_leave_balances')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'leave_type_id' => 1, 'allocated' => 20, 'opening_balance' => 20,
            'used' => 0, 'adjusted' => 0, 'carried_forward' => 0,
            // Lowercase: HrEmployeeLeaveBalance::ACTIVE, unlike hr_employees.status.
            'available_balance' => 20, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return HrLeaveApplication::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'leave_type_id' => 1, 'from_date' => '2026-06-01', 'to_date' => '2026-06-02',
            'days' => 2, 'status' => $status,
        ]);
    }

    private function config(): WorkflowConfigService
    {
        return app(WorkflowConfigService::class);
    }

    private function engine(): ApprovalEngine
    {
        return app(ApprovalEngine::class);
    }

    /** Save a ladder for Leave. */
    private function saveLadder(array $steps, bool $active = true): void
    {
        $this->config()->save($this->tenant->id, ApprovalProcess::LEAVE, [
            'name' => 'Leave approval', 'is_active' => $active, 'steps' => $steps,
        ]);
    }

    private function approveVia(HrLeaveApplication $leave, ?string $remarks = null)
    {
        return $this->patchJson("/api/hr/leave/approvals/{$leave->id}/approve", ['remarks' => $remarks]);
    }

    private function rejectVia(HrLeaveApplication $leave, ?string $remarks = null)
    {
        return $this->patchJson("/api/hr/leave/approvals/{$leave->id}/reject", ['remarks' => $remarks]);
    }

    /* ── 1. default / legacy compatibility ────────────────────────────── */

    public function test_with_no_workflow_configured_behaviour_is_unchanged(): void
    {
        $hr = $this->user('hr@wf.test', null, 'admin');
        $employee = $this->employee();
        $leave = $this->leave($employee);

        Sanctum::actingAs($hr);
        $this->approveVia($leave, 'fine')->assertOk();

        // The service still did its job: status moved and the audit line exists.
        $this->assertSame(HrLeaveApplication::APPROVED, $leave->fresh()->status);
    }

    public function test_the_legacy_rung_refuses_somebody_who_could_not_approve_before(): void
    {
        $nobody = $this->user('nobody@wf.test');          // no staff role at all
        $leave  = $this->leave($this->employee());

        Sanctum::actingAs($nobody);
        $this->approveVia($leave)->assertStatus(403);

        $this->assertSame(HrLeaveApplication::SUBMITTED, $leave->fresh()->status);
    }

    /* ── 2. tenant isolation ──────────────────────────────────────────── */

    public function test_a_workflow_from_another_tenant_never_resolves(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'wf-other', 'status' => 'active']);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
        ]);

        // Tenant B has configured nothing, so it falls back to legacy — it must
        // NOT inherit tenant A's ladder.
        $shown = $this->config()->show($other->id, ApprovalProcess::LEAVE);

        $this->assertNull($shown['workflow']);
        $this->assertSame([], $shown['steps']);
    }

    public function test_a_cross_tenant_approver_reference_cannot_be_saved(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'wf-other2', 'status' => 'active']);
        $theirRole = StaffRole::create([
            'tenant_id' => $other->id, 'name' => 'Theirs', 'slug' => 'theirs',
            'permissions' => [], 'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->saveLadder([
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $theirRole->id],
        ]);
    }

    /* ── 3-6. configuration, activation, versioning, ordering ─────────── */

    public function test_saving_a_ladder_stores_ordered_steps_and_bumps_the_version(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'Fin');

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id, 'name' => 'Finance'],
        ]);
        $first = $this->config()->show($this->tenant->id, ApprovalProcess::LEAVE);

        $this->assertSame(1, $first['workflow']['version']);
        $this->assertSame([1, 2], array_column($first['steps'], 'step_order'));
        $this->assertSame('Manager', $first['steps'][0]['name']);

        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager']]);
        $second = $this->config()->show($this->tenant->id, ApprovalProcess::LEAVE);

        $this->assertSame(2, $second['workflow']['version'], 'Editing a ladder bumps its version.');
        $this->assertCount(1, $second['steps']);
    }

    public function test_disabling_a_workflow_falls_back_rather_than_stranding_the_queue(): void
    {
        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER]]);
        $this->config()->setStatus($this->tenant->id, ApprovalProcess::LEAVE, false);

        $hr = $this->user('hr2@wf.test', null, 'admin');
        $leave = $this->leave($this->employee());

        // A disabled ladder must not mean "nobody can approve anything".
        Sanctum::actingAs($hr);
        $this->approveVia($leave)->assertOk();
        $this->assertSame(HrLeaveApplication::APPROVED, $leave->fresh()->status);
    }

    /* ── 7. sequential approval ───────────────────────────────────────── */

    public function test_a_two_step_ladder_does_not_approve_on_the_first_decision(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'Fin2');
        $financeUser = $this->user('fin@wf.test', $finance);

        $managerUser = $this->user('mgr@wf.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $leave = $this->leave($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        // Step 1 — the manager.
        Sanctum::actingAs($managerUser);
        $this->approveVia($leave, 'ok by me')->assertOk();

        // The application has NOT moved: approving halfway would deduct the
        // balance for a leave nobody has finished approving.
        $this->assertSame(HrLeaveApplication::SUBMITTED, $leave->fresh()->status);

        $request = HrApprovalRequest::where('subject_id', $leave->id)->first();
        $this->assertSame(ApprovalState::PENDING, $request->state);
        $this->assertSame(2, $request->current_step);

        // Step 2 — finance closes it.
        Sanctum::actingAs($financeUser);
        $this->approveVia($leave, 'approved')->assertOk();

        $this->assertSame(HrLeaveApplication::APPROVED, $leave->fresh()->status);
        $this->assertSame(ApprovalState::APPROVED, $request->fresh()->state);
    }

    public function test_the_second_step_approver_cannot_act_while_the_first_is_pending(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'Fin3');
        $financeUser = $this->user('fin2@wf.test', $finance);

        $managerUser = $this->user('mgr2@wf.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $leave = $this->leave($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($financeUser);
        $this->approveVia($leave)->assertStatus(403);

        $this->assertSame(HrLeaveApplication::SUBMITTED, $leave->fresh()->status);
    }

    /* ── 8. rejection is terminal ─────────────────────────────────────── */

    public function test_rejection_at_the_first_rung_ends_the_whole_ladder(): void
    {
        $managerUser = $this->user('mgr3@wf.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $leave = $this->leave($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Skip level', 'levels_up' => 2],
        ]);

        Sanctum::actingAs($managerUser);
        $this->rejectVia($leave, 'not this month')->assertOk();

        $this->assertSame(HrLeaveApplication::REJECTED, $leave->fresh()->status);
        $this->assertSame(
            ApprovalState::REJECTED,
            HrApprovalRequest::where('subject_id', $leave->id)->first()->state
        );
    }

    /* ── 9-10. the snapshot ───────────────────────────────────────────── */

    public function test_editing_a_workflow_does_not_reroute_an_approval_in_progress(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'Fin4');
        $financeUser = $this->user('fin3@wf.test', $finance);

        $managerUser = $this->user('mgr4@wf.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $leave = $this->leave($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($managerUser);
        $this->approveVia($leave)->assertOk();          // now sitting on step 2

        // Somebody rewrites the ladder to a single rung while this is in flight.
        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager only']]);

        $request = HrApprovalRequest::where('subject_id', $leave->id)->first();

        // The snapshot still has both rungs and still points at finance.
        $this->assertCount(2, $request->steps_snapshot);
        $this->assertSame(2, $request->current_step);

        // AdvanceTierService recomputes its ladder from live settings on every
        // call, so changing a limit rewrites an advance mid-flight. This is the
        // behaviour that does not.
        Sanctum::actingAs($financeUser);
        $this->approveVia($leave)->assertOk();
        $this->assertSame(HrLeaveApplication::APPROVED, $leave->fresh()->status);
    }

    public function test_a_new_request_uses_the_latest_version(): void
    {
        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Skip', 'levels_up' => 2],
        ]);
        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager only']]);

        $managerUser = $this->user('mgr5@wf.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $leave = $this->leave($employee);

        Sanctum::actingAs($managerUser);
        $this->approveVia($leave)->assertOk();

        // One rung in the new version, so the first approval is final.
        $this->assertSame(HrLeaveApplication::APPROVED, $leave->fresh()->status);
    }

    /* ── 11-13. approver types ────────────────────────────────────────── */

    public function test_the_reporting_manager_approver_resolves_the_actual_manager(): void
    {
        $managerUser = $this->user('mgr6@wf.test', null, 'admin');
        $manager  = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);

        $stranger = $this->user('other@wf.test', null, 'admin');   // also HR, not the manager
        $leave = $this->leave($employee);

        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER]]);

        Sanctum::actingAs($stranger);
        $this->approveVia($leave)->assertStatus(403);

        Sanctum::actingAs($managerUser);
        $this->approveVia($leave)->assertOk();
    }

    public function test_the_staff_role_approver_admits_any_holder_of_that_role(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'Appr');
        $a = $this->user('a@wf.test', $role);
        $this->user('b@wf.test', $role);
        $leave = $this->leave($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($a);
        $this->approveVia($leave)->assertOk();
        $this->assertSame(HrLeaveApplication::APPROVED, $leave->fresh()->status);
    }

    public function test_the_specific_user_approver_admits_only_that_user(): void
    {
        $role   = $this->role(DataScope::GLOBAL, 'Named');
        $named  = $this->user('named@wf.test', $role);
        $other  = $this->user('notnamed@wf.test', $role);
        $leave  = $this->leave($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::SPECIFIC_USER, 'approver_ref' => $named->id]]);

        Sanctum::actingAs($other);
        $this->approveVia($leave)->assertStatus(403);

        Sanctum::actingAs($named);
        $this->approveVia($leave)->assertOk();
    }

    /* ── 14-17. conditions ────────────────────────────────────────────── */

    public function test_a_department_condition_selects_which_rung_applies(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'Cond');
        $approver = $this->user('cond@wf.test', $role);

        $dept = DB::table('hr_departments')->insertGetId([
            'tenant_id' => $this->tenant->id, 'name' => 'Engineering',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $inDept  = $this->employee(['department_id' => $dept]);
        $noDept  = $this->employee(['department_id' => null]);

        $this->saveLadder([
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id,
             'name' => 'Engineering sign-off', 'conditions' => ['department_id' => [$dept]]],
        ]);

        // In the department: the configured rung applies.
        $leaveIn = $this->leave($inDept);
        Sanctum::actingAs($approver);
        $this->approveVia($leaveIn)->assertOk();

        // Outside it: every rung filtered out, so the ladder falls back to the
        // legacy rung rather than leaving the request undecidable.
        $leaveOut = $this->leave($noDept);
        $hr = $this->user('hrc@wf.test', null, 'admin');
        Sanctum::actingAs($hr);
        $this->approveVia($leaveOut)->assertOk();
    }

    public function test_a_branch_condition_is_evaluated_from_the_database_not_the_request(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'Br');
        $approver = $this->user('br@wf.test', $role);

        $pune = $this->employee(['branch' => 'Pune']);
        $leave = $this->leave($pune);

        $this->saveLadder([
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id,
             'conditions' => ['branch' => ['Pune']]],
        ]);

        // The client posts a contradicting branch; the server reads the
        // employee's real one, so this changes nothing.
        Sanctum::actingAs($approver);
        $this->patchJson("/api/hr/leave/approvals/{$leave->id}/approve", [
            'remarks' => 'ok', 'branch' => 'Mumbai', 'department_id' => 999,
        ])->assertOk();

        $this->assertSame(HrLeaveApplication::APPROVED, $leave->fresh()->status);
    }

    public function test_an_amount_condition_cannot_match_a_process_with_no_amount(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'Amt');
        $this->user('amt@wf.test', $role);

        $this->saveLadder([
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id,
             'conditions' => ['min_amount' => 1000]],
        ]);

        // Leave carries no money, so the rung cannot apply and the ladder falls
        // back — rather than the amount rule silently matching everything.
        $hr = $this->user('hra@wf.test', null, 'admin');
        $leave = $this->leave($this->employee());

        Sanctum::actingAs($hr);
        $this->approveVia($leave)->assertOk();
    }

    /* ── 18-20. the three gates ───────────────────────────────────────── */

    public function test_an_out_of_scope_approver_is_refused_even_when_named_on_the_step(): void
    {
        // Department-scoped, and explicitly named as THE approver.
        $deptRole = $this->role(DataScope::DEPARTMENT, 'Dept');
        $approver = $this->user('dept@wf.test', $deptRole);
        $this->employee(['user_id' => $approver->id, 'department' => 'Ops']);

        $outsider = $this->employee(['department' => 'Sales']);
        $leave = $this->leave($outsider);

        $this->saveLadder([
            ['approver_type' => ApproverType::SPECIFIC_USER, 'approver_ref' => $approver->id],
        ]);

        Sanctum::actingAs($approver);

        // Being the approver is not a grant of access to the employee. The
        // scope gate is independent and it refuses first.
        $this->approveVia($leave)->assertStatus(404);
        $this->assertSame(HrLeaveApplication::SUBMITTED, $leave->fresh()->status);
    }

    public function test_the_engine_enforces_scope_on_its_own_without_the_controller(): void
    {
        // The HTTP test above passes through LeaveApprovalController, which has
        // its own assertInScope() from the earlier scope work — so it proves the
        // controller, not the engine. This calls assertMayDecide() directly, the
        // way a future process adapter would, and pins the engine's own gate.
        $deptRole = $this->role(DataScope::DEPARTMENT, 'EngScope');
        $approver = $this->user('engscope@wf.test', $deptRole);
        $this->employee(['user_id' => $approver->id, 'department' => 'Ops']);

        $outsider = $this->employee(['department' => 'Sales']);
        $leave = $this->leave($outsider);

        $this->saveLadder([
            ['approver_type' => ApproverType::SPECIFIC_USER, 'approver_ref' => $approver->id],
        ]);

        $request = $this->engine()->requestFor(
            $leave, ApprovalProcess::LEAVE, $this->tenant->id, $leave->employee_id
        );

        // Named on the step and therefore past gate 3 — refused by gate 2.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->engine()->assertMayDecide($request, $approver);
    }

    public function test_an_approver_without_the_capability_is_still_refused(): void
    {
        // Named on the step, global scope — but holds no HR capability, so
        // can() refuses before the ladder is ever consulted.
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'NoCap', 'slug' => 'nocap',
            'permissions' => [], 'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);
        $approver = $this->user('nocap@wf.test', $role);
        $leave = $this->leave($this->employee());

        $this->saveLadder([
            ['approver_type' => ApproverType::SPECIFIC_USER, 'approver_ref' => $approver->id],
        ]);

        Sanctum::actingAs($approver);
        $this->approveVia($leave)->assertStatus(403);
    }

    /* ── 21. no approver resolvable ───────────────────────────────────── */

    public function test_an_unresolvable_rung_blocks_and_never_auto_approves(): void
    {
        $employee = $this->employee();                    // no reporting manager
        $leave = $this->leave($employee);

        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER]]);

        $hr = $this->user('hrb@wf.test', null, 'admin');
        Sanctum::actingAs($hr);

        $this->approveVia($leave)->assertStatus(409);

        $this->assertSame(HrLeaveApplication::SUBMITTED, $leave->fresh()->status,
            'A missing approver must never hand out the decision.');

        $request = HrApprovalRequest::where('subject_id', $leave->id)->first();
        $this->assertSame(ApprovalState::BLOCKED, $request->state);
        $this->assertNotEmpty($request->blocked_reason);
    }

    /* ── 22-23. duplicate / completed steps ───────────────────────────── */

    public function test_the_same_rung_cannot_be_decided_twice(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'Dup');
        $a = $this->user('dup@wf.test', $role);
        $leave = $this->leave($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($a);
        $this->approveVia($leave)->assertOk();

        // Second press: the request is closed and the application already moved.
        $this->approveVia($leave)->assertStatus(422);
    }

    public function test_the_action_history_survives_a_workflow_edit(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'Hist');
        $a = $this->user('hist@wf.test', $role);
        $leave = $this->leave($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($a);
        $this->approveVia($leave, 'because I said so')->assertOk();

        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER]]);

        $this->assertDatabaseHas('hr_approval_actions', [
            'actor_id' => $a->id, 'action' => 'approved', 'comment' => 'because I said so',
        ]);
    }

    /* ── 25. direct API bypass ────────────────────────────────────────── */

    public function test_a_raw_call_cannot_skip_to_the_final_step(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'Byp');
        $financeUser = $this->user('byp@wf.test', $finance);

        $managerUser = $this->user('mgrb@wf.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $leave = $this->leave($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        // Posting extra fields that look like step control changes nothing:
        // the engine reads current_step from its own row, never from input.
        Sanctum::actingAs($financeUser);
        $this->patchJson("/api/hr/leave/approvals/{$leave->id}/approve", [
            'remarks' => 'skip', 'step' => 2, 'current_step' => 2, 'final' => true,
        ])->assertStatus(403);

        $this->assertSame(HrLeaveApplication::SUBMITTED, $leave->fresh()->status);
    }

    /* ── 27. attendance app compatibility ─────────────────────────────── */

    public function test_the_attendance_app_service_path_still_decides_directly(): void
    {
        $hr = $this->user('appsvc@wf.test', null, 'admin');
        $employee = $this->employee();
        $leave = $this->leave($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Skip', 'levels_up' => 2],
        ]);

        // HrmAdminController calls the service directly, with its own
        // reporting-line guard. That path is deliberately unchanged, so a
        // two-step CRM ladder does not stop the phone deciding.
        app(\App\Services\Hr\LeaveApprovalService::class)
            ->approve($leave->id, 'from the app', $this->tenant->id, $hr);

        $this->assertSame(HrLeaveApplication::APPROVED, $leave->fresh()->status);
    }

    public function test_the_engine_closes_a_request_the_app_already_decided(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'Sup');
        $approver = $this->user('sup@wf.test', $role);
        $employee = $this->employee();
        $leave = $this->leave($employee);

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        // Open a request through the engine without deciding it.
        $request = $this->engine()->requestFor(
            $leave, ApprovalProcess::LEAVE, $this->tenant->id, $leave->employee_id
        );
        $this->assertSame(ApprovalState::PENDING, $request->state);

        // The app decides directly.
        app(\App\Services\Hr\LeaveApprovalService::class)
            ->approve($leave->id, 'app', $this->tenant->id, $approver);

        // Next CRM touch reconciles rather than contradicting the record.
        Sanctum::actingAs($approver);
        $this->approveVia($leave)->assertStatus(422);

        $this->assertSame(ApprovalState::SUPERSEDED, $request->fresh()->state);
    }

    /* ── settings authorization ───────────────────────────────────────── */

    public function test_an_approver_cannot_edit_the_ladder_they_stand_on(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'Cfg');
        $approver = $this->user('cfg@wf.test', $role);

        Sanctum::actingAs($approver);

        // Holds hr_employees:view_global — enough to approve, not to configure.
        $this->getJson('/api/hr/approval-workflows')->assertStatus(403);
        $this->putJson('/api/hr/approval-workflows/leave', ['steps' => []])->assertStatus(403);
    }

    public function test_an_administrator_can_read_and_write_the_configuration(): void
    {
        $admin = $this->user('admin@wf.test', null, 'admin');

        Sanctum::actingAs($admin);
        $this->getJson('/api/hr/approval-workflows')->assertOk()
            ->assertJsonPath('data.0.process', ApprovalProcess::LEAVE);

        $this->putJson('/api/hr/approval-workflows/leave', [
            'steps' => [['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager']],
        ])->assertOk()->assertJsonPath('steps.0.name', 'Manager');
    }

    public function test_an_unknown_process_is_refused(): void
    {
        $admin = $this->user('admin2@wf.test', null, 'admin');

        Sanctum::actingAs($admin);
        $this->getJson('/api/hr/approval-workflows/not-a-process')->assertStatus(404);
    }
}
