<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrApprovalRequest;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrExitRequest;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Hr\ClearanceRepository;
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
 * Exit APPROVAL on the approval workflow engine — Phase 6.
 *
 * Exit is three domains and only the first is wired here:
 *
 *   APPROVAL   — whether somebody leaves.        ← this phase
 *   CLEARANCE  — handing things back, item by item.
 *   SETTLEMENT — the final figure, with its own review/approve/settle chain.
 *
 * The two later domains keep their own lifecycles and their own queues, and
 * nothing in this suite touches them beyond proving that a half-approved exit
 * does not leak into the clearance queue.
 *
 * The shape of this process differs from the five before it in one way that
 * matters: a decision is only legal once the request is UNDER REVIEW.
 * assertDecidable() enforces that, startReview() is what gets it there, and
 * neither is a rung — picking work up is not deciding it.
 */
class ExitApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $exitTypeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'exwf', 'status' => 'active']);
        $this->exitTypeId = $this->exitType();
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function exitType(string $name = 'Resignation'): int
    {
        return DB::table('hr_exit_types')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'name' => $name.substr(uniqid(), -4), 'code' => 'X'.substr(uniqid(), -5),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
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

    /** An exit request. Defaults to Under Review — the only decidable state. */
    private function exit(
        HrEmployee $employee,
        string $status = HrExitRequest::UNDER_REVIEW,
        ?int $typeId = null
    ): HrExitRequest {
        return HrExitRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'exit_type_id' => $typeId ?: $this->exitTypeId,
            'request_date' => '2026-06-01', 'status' => $status,
            'reason' => 'Moving on',
        ]);
    }

    private function saveLadder(array $steps, bool $active = true): void
    {
        app(WorkflowConfigService::class)->save($this->tenant->id, ApprovalProcess::EXIT_REQUEST, [
            'name' => 'Exit approval', 'is_active' => $active, 'steps' => $steps,
        ]);
    }

    private function approveVia(HrExitRequest $e, ?string $remarks = null)
    {
        return $this->patchJson("/api/hr/exit/approvals/{$e->id}/approve", ['remarks' => $remarks]);
    }

    private function rejectVia(HrExitRequest $e, ?string $remarks = 'not accepted')
    {
        return $this->patchJson("/api/hr/exit/approvals/{$e->id}/reject", ['remarks' => $remarks]);
    }

    private function request(HrExitRequest $e)
    {
        return HrApprovalRequest::where('subject_type', HrExitRequest::class)
            ->where('subject_id', $e->id)->latest('id')->first();
    }

    private function approvedAudits(HrExitRequest $e): int
    {
        return DB::table('audit_logs')
            ->where('auditable_type', HrExitRequest::class)
            ->where('auditable_id', $e->id)
            ->where('action', 'Exit Approved')->count();
    }

    /* ── 1-2. legacy compatibility and tenancy ────────────────────────── */

    public function test_with_no_ladder_configured_an_exit_approves_as_before(): void
    {
        $hr = $this->user('hr@ex.test', null, 'admin');
        $exit = $this->exit($this->employee());

        Sanctum::actingAs($hr);
        $this->approveVia($exit, 'accepted')->assertOk();

        $this->assertSame(HrExitRequest::APPROVED, $exit->fresh()->status);
    }

    public function test_the_legacy_rung_still_refuses_a_non_hr_user(): void
    {
        $nobody = $this->user('nobody@ex.test');
        $exit = $this->exit($this->employee());

        Sanctum::actingAs($nobody);
        $this->approveVia($exit)->assertStatus(403);

        $this->assertSame(HrExitRequest::UNDER_REVIEW, $exit->fresh()->status);
    }

    public function test_a_ladder_does_not_leak_to_another_tenant(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'exwf-o', 'status' => 'active']);
        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER]]);

        $shown = app(WorkflowConfigService::class)->show($other->id, ApprovalProcess::EXIT_REQUEST);

        $this->assertNull($shown['workflow']);
        $this->assertSame([], $shown['steps']);
    }

    /* ── 3-4. capability and scope ────────────────────────────────────── */

    public function test_an_approver_without_the_capability_is_refused(): void
    {
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'NoCap', 'slug' => 'nocap_ex',
            'permissions' => [], 'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);
        $approver = $this->user('nocap@ex.test', $role);
        $exit = $this->exit($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::SPECIFIC_USER, 'approver_ref' => $approver->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($exit)->assertStatus(403);

        $this->assertSame(HrExitRequest::UNDER_REVIEW, $exit->fresh()->status);
    }

    public function test_an_out_of_scope_approver_is_refused_even_when_named(): void
    {
        $deptRole = $this->role(DataScope::DEPARTMENT, 'DeptEx');
        $approver = $this->user('dept@ex.test', $deptRole);
        $this->employee(['user_id' => $approver->id, 'department' => 'Ops']);

        $outsider = $this->employee(['department' => 'Sales']);
        $exit = $this->exit($outsider);

        $this->saveLadder([['approver_type' => ApproverType::SPECIFIC_USER, 'approver_ref' => $approver->id]]);

        Sanctum::actingAs($approver);

        $this->approveVia($exit)->assertStatus(404);
        $this->assertSame(HrExitRequest::UNDER_REVIEW, $exit->fresh()->status);
    }

    /* ── 5-7. sequence, and what an intermediate rung must not do ─────── */

    public function test_a_single_step_ladder_approves_on_one_decision(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'OneEx');
        $approver = $this->user('one@ex.test', $role);
        $exit = $this->exit($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($exit)->assertOk();

        $this->assertSame(HrExitRequest::APPROVED, $exit->fresh()->status);
    }

    public function test_an_intermediate_approval_does_not_owe_a_clearance(): void
    {
        $hrRole = $this->role(DataScope::GLOBAL, 'HrEx');
        $this->user('hrstep@ex.test', $hrRole);

        $managerUser = $this->user('mgr@ex.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $exit = $this->exit($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $hrRole->id, 'name' => 'HR head'],
        ]);

        Sanctum::actingAs($managerUser);
        $this->approveVia($exit)->assertOk();

        $fresh = $exit->fresh();
        $this->assertSame(HrExitRequest::UNDER_REVIEW, $fresh->status);
        $this->assertNull($fresh->decided_at);

        // The downstream consequence is a PULL: clearance looks for APPROVED
        // exits. Half-approved is still Under Review, so nothing is owed.
        $pending = app(ClearanceRepository::class)->approvedExitsNeedingClearance($this->tenant->id);
        $this->assertCount(0, $pending, 'A mid-ladder exit must not enter the clearance queue.');

        $this->assertSame(2, $this->request($exit)->current_step);
    }

    public function test_the_terminal_approval_decides_once_and_owes_a_clearance(): void
    {
        $hrRole = $this->role(DataScope::GLOBAL, 'HrEx2');
        $hrUser = $this->user('hrstep2@ex.test', $hrRole);

        $managerUser = $this->user('mgr2@ex.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $exit = $this->exit($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $hrRole->id, 'name' => 'HR head'],
        ]);

        Sanctum::actingAs($managerUser);
        $this->approveVia($exit)->assertOk();

        Sanctum::actingAs($hrUser);
        $this->approveVia($exit, 'released')->assertOk();

        $fresh = $exit->fresh();
        $this->assertSame(HrExitRequest::APPROVED, $fresh->status);
        $this->assertNotNull($fresh->decided_at);
        $this->assertSame(1, $this->approvedAudits($exit));

        // Now — and only now — the leaver appears for clearance.
        $pending = app(ClearanceRepository::class)->approvedExitsNeedingClearance($this->tenant->id);
        $this->assertCount(1, $pending);
    }

    public function test_the_second_rung_cannot_act_before_the_first(): void
    {
        $hrRole = $this->role(DataScope::GLOBAL, 'HrEx3');
        $hrUser = $this->user('hrstep3@ex.test', $hrRole);

        $managerUser = $this->user('mgr3@ex.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $exit = $this->exit($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $hrRole->id, 'name' => 'HR head'],
        ]);

        Sanctum::actingAs($hrUser);
        $this->approveVia($exit)->assertStatus(403);

        $this->assertSame(HrExitRequest::UNDER_REVIEW, $exit->fresh()->status);
    }

    /* ── 8. exit-type condition ───────────────────────────────────────── */

    public function test_a_condition_can_route_one_exit_type_differently(): void
    {
        $hrRole = $this->role(DataScope::GLOBAL, 'TypeEx');
        $hrUser = $this->user('type@ex.test', $hrRole);

        $managerUser = $this->user('mgrtype@ex.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);

        $termination = $this->exitType('Termination');

        // A termination needs the HR head as well; an ordinary resignation
        // does not.
        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $hrRole->id,
             'name' => 'HR head', 'conditions' => ['exit_type_id' => [$termination]]],
        ]);

        $resignation = $this->exit($employee);
        Sanctum::actingAs($managerUser);
        $this->approveVia($resignation)->assertOk();
        $this->assertSame(HrExitRequest::APPROVED, $resignation->fresh()->status);

        $fired = $this->exit($this->employee(['reporting_manager_id' => $manager->id]),
            HrExitRequest::UNDER_REVIEW, $termination);
        $this->approveVia($fired)->assertOk();
        $this->assertSame(HrExitRequest::UNDER_REVIEW, $fired->fresh()->status);

        Sanctum::actingAs($hrUser);
        $this->approveVia($fired)->assertOk();
        $this->assertSame(HrExitRequest::APPROVED, $fired->fresh()->status);
    }

    /* ── 9-10. rejection and blocking ─────────────────────────────────── */

    public function test_rejection_is_terminal_and_keeps_the_domain_behaviour(): void
    {
        $managerUser = $this->user('mgrrej@ex.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $exit = $this->exit($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Skip', 'levels_up' => 2],
        ]);

        Sanctum::actingAs($managerUser);
        $this->rejectVia($exit, 'counter-offer accepted')->assertOk();

        $fresh = $exit->fresh();
        $this->assertSame(HrExitRequest::REJECTED, $fresh->status);
        $this->assertSame('counter-offer accepted', $fresh->decision_remarks);

        // A rejected exit owes no clearance.
        $this->assertCount(0, app(ClearanceRepository::class)->approvedExitsNeedingClearance($this->tenant->id));
        $this->assertSame(ApprovalState::REJECTED, $this->request($exit)->state);
    }

    public function test_an_unresolvable_rung_blocks_and_never_approves(): void
    {
        $employee = $this->employee();                // no reporting manager
        $exit = $this->exit($employee);

        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER]]);

        $hr = $this->user('hrblock@ex.test', null, 'admin');
        Sanctum::actingAs($hr);

        $this->approveVia($exit)->assertStatus(409);

        $this->assertSame(HrExitRequest::UNDER_REVIEW, $exit->fresh()->status);
        $this->assertSame(ApprovalState::BLOCKED, $this->request($exit)->state);
    }

    /* ── 11. the snapshot ─────────────────────────────────────────────── */

    public function test_editing_the_ladder_does_not_reroute_an_exit_in_progress(): void
    {
        $hrRole = $this->role(DataScope::GLOBAL, 'SnapEx');
        $hrUser = $this->user('snap@ex.test', $hrRole);

        $managerUser = $this->user('mgrsnap@ex.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $exit = $this->exit($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $hrRole->id, 'name' => 'HR head'],
        ]);

        Sanctum::actingAs($managerUser);
        $this->approveVia($exit)->assertOk();

        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager only']]);

        $request = $this->request($exit);
        $this->assertCount(2, $request->steps_snapshot);
        $this->assertSame(2, $request->current_step);

        Sanctum::actingAs($hrUser);
        $this->approveVia($exit)->assertOk();
        $this->assertSame(HrExitRequest::APPROVED, $exit->fresh()->status);
    }

    /* ── 12. duplicate decisions ──────────────────────────────────────── */

    public function test_a_second_approval_cannot_decide_twice(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'DupEx');
        $approver = $this->user('dup@ex.test', $role);
        $exit = $this->exit($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($exit)->assertOk();

        // assertDecidable() refuses: "already been approved".
        $this->approveVia($exit)->assertStatus(422);

        $this->assertSame(1, $this->approvedAudits($exit));
    }

    /* ── 13. bypass ───────────────────────────────────────────────────── */

    public function test_posted_step_fields_cannot_skip_the_ladder(): void
    {
        $hrRole = $this->role(DataScope::GLOBAL, 'BypEx');
        $hrUser = $this->user('byp@ex.test', $hrRole);

        $managerUser = $this->user('mgrbyp@ex.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $exit = $this->exit($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $hrRole->id, 'name' => 'HR head'],
        ]);

        Sanctum::actingAs($hrUser);
        $this->patchJson("/api/hr/exit/approvals/{$exit->id}/approve", [
            'step' => 2, 'current_step' => 2, 'final' => true, 'status' => 'Approved',
        ])->assertStatus(403);

        $this->assertSame(HrExitRequest::UNDER_REVIEW, $exit->fresh()->status);
    }

    /* ── the review precondition, which is NOT a rung ─────────────────── */

    public function test_a_submitted_exit_cannot_be_decided_before_review_starts(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'PreEx');
        $approver = $this->user('pre@ex.test', $role);
        $exit = $this->exit($this->employee(), HrExitRequest::SUBMITTED);

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);

        // The domain precondition is unchanged: assertDecidable() insists the
        // request is under review, and the ladder does not replace that.
        $this->approveVia($exit)->assertStatus(422);

        $this->assertSame(HrExitRequest::SUBMITTED, $exit->fresh()->status);
    }

    public function test_starting_review_is_not_a_rung(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'RevEx');
        $approver = $this->user('rev@ex.test', $role);
        $exit = $this->exit($this->employee(), HrExitRequest::SUBMITTED);

        // Even with a two-rung ladder, picking the work up is not deciding it.
        $this->saveLadder([
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id, 'name' => 'HR'],
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
        ]);

        Sanctum::actingAs($approver);
        $this->patchJson("/api/hr/exit/approvals/{$exit->id}/review", ['review_remarks' => 'looking'])
            ->assertOk();

        $this->assertSame(HrExitRequest::UNDER_REVIEW, $exit->fresh()->status);
        $this->assertDatabaseCount('hr_approval_actions', 0);
    }

    public function test_a_withdrawn_exit_keeps_its_existing_refusal(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'WdEx');
        $approver = $this->user('wd@ex.test', $role);
        $exit = $this->exit($this->employee(), HrExitRequest::WITHDRAWN);

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);

        // Withdrawal is only possible while Draft or Submitted, so it can never
        // race an in-flight ladder — but a withdrawn request must still refuse
        // with the domain's own message.
        $this->approveVia($exit)->assertStatus(422);

        $this->assertSame(HrExitRequest::WITHDRAWN, $exit->fresh()->status);
    }

    /* ── the other two exit domains are untouched ─────────────────────── */

    public function test_only_exit_approval_is_a_configurable_process(): void
    {
        $admin = $this->user('admin@ex.test', null, 'admin');

        Sanctum::actingAs($admin);
        $keys = array_column($this->getJson('/api/hr/approval-workflows')->assertOk()->json('data'), 'process');

        $this->assertContains(ApprovalProcess::EXIT_REQUEST, $keys);

        // Clearance and settlement are separate domains and are NOT migrated.
        $this->assertNotContains('exit_clearance', $keys);
        $this->assertNotContains('exit_settlement', $keys);

        foreach ([
            ApprovalProcess::LEAVE, ApprovalProcess::LOAN, ApprovalProcess::VARIABLE_EARNING,
            ApprovalProcess::INVESTMENT_DECLARATION, ApprovalProcess::REIMBURSEMENT,
        ] as $p) {
            $this->assertContains($p, $keys, 'Earlier processes must stay configurable.');
        }
    }

    public function test_the_settlement_approve_is_a_different_action_entirely(): void
    {
        // ExitSettlementService also has an approve(), for the final figure.
        // It is a separate domain with its own chain and is deliberately not
        // wired, so its method signature is untouched by this phase.
        $this->assertTrue(
            method_exists(\App\Services\Hr\ExitSettlementService::class, 'approve'),
            'Settlement keeps its own approve() — this phase does not migrate it.'
        );
    }
}
