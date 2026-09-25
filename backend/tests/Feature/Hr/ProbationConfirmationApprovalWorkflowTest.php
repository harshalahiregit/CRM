<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrApprovalRequest;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeProbation;
use App\Models\Hr\HrProbationConfirmation;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
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
 * Probation confirmation on the approval workflow engine — Phase 7.
 *
 * The lifecycle is two-stage and only the first stage is a decision:
 *
 *   Pending --approve--> Approved --confirm--> Confirmed
 *
 * approve() is the decision. confirm() executes a decision already taken —
 * it writes the effective date and closes the probation — in the same way
 * disburse() does for a loan, and it already refuses anything that is not
 * Approved.
 *
 * That refusal is what makes a multi-rung ladder safe, and it is the property
 * most of this suite exists to hold: an intermediate approval leaves the
 * confirmation Pending, so the probation cannot be closed. Closing one is
 * irreversible — a confirmed employee cannot return to probation.
 */
class ProbationConfirmationApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $typeId;

    private int $policyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'pcwf', 'status' => 'active']);

        $this->typeId = DB::table('hr_probation_types')->insertGetId([
            'tenant_id' => $this->tenant->id, 'name' => 'Standard', 'code' => 'STD',
            'default_duration_days' => 180, 'confirmation_required' => true,
            'review_required' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->policyId = DB::table('hr_probation_policies')->insertGetId([
            'tenant_id' => $this->tenant->id, 'name' => 'Default',
            'probation_type_id' => $this->typeId, 'review_frequency' => 'Monthly',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
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

    /** An active probation with a pending confirmation on it. */
    private function confirmation(HrEmployee $employee): HrProbationConfirmation
    {
        $probation = HrEmployeeProbation::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'probation_policy_id' => $this->policyId, 'probation_type_id' => $this->typeId,
            'probation_start_date' => '2026-01-01', 'probation_end_date' => '2026-06-30',
            'current_status' => HrEmployeeProbation::ACTIVE,
        ]);

        return HrProbationConfirmation::create([
            'tenant_id' => $this->tenant->id, 'probation_id' => $probation->id,
            'employee_id' => $employee->id, 'status' => HrProbationConfirmation::PENDING,
            'decision' => 'Confirm',
        ]);
    }

    private function saveLadder(array $steps, bool $active = true): void
    {
        app(WorkflowConfigService::class)->save($this->tenant->id, ApprovalProcess::PROBATION_CONFIRMATION, [
            'name' => 'Confirmation approval', 'is_active' => $active, 'steps' => $steps,
        ]);
    }

    private function approveVia(HrProbationConfirmation $c, ?string $comments = null)
    {
        return $this->patchJson("/api/hr/probation/confirmations/{$c->id}/approve", ['hr_comments' => $comments]);
    }

    private function rejectVia(HrProbationConfirmation $c, ?string $comments = 'not yet')
    {
        return $this->patchJson("/api/hr/probation/confirmations/{$c->id}/reject", ['hr_comments' => $comments]);
    }

    private function confirmVia(HrProbationConfirmation $c)
    {
        return $this->patchJson("/api/hr/probation/confirmations/{$c->id}/confirm", []);
    }

    private function request(HrProbationConfirmation $c)
    {
        return HrApprovalRequest::where('subject_type', HrProbationConfirmation::class)
            ->where('subject_id', $c->id)->latest('id')->first();
    }

    private function approvedAudits(HrProbationConfirmation $c): int
    {
        return DB::table('audit_logs')
            ->where('auditable_type', HrProbationConfirmation::class)
            ->where('auditable_id', $c->id)
            ->where('action', 'Probation Confirmation Approved')->count();
    }

    /* ── 1-2. legacy compatibility and tenancy ────────────────────────── */

    public function test_with_no_ladder_configured_approval_works_as_before(): void
    {
        $hr = $this->user('hr@pc.test', null, 'admin');
        $conf = $this->confirmation($this->employee());

        Sanctum::actingAs($hr);
        $this->approveVia($conf, 'looks good')->assertOk();

        $this->assertSame(HrProbationConfirmation::APPROVED, $conf->fresh()->status);
    }

    public function test_the_legacy_rung_still_refuses_a_non_hr_user(): void
    {
        $nobody = $this->user('nobody@pc.test');
        $conf = $this->confirmation($this->employee());

        Sanctum::actingAs($nobody);
        $this->approveVia($conf)->assertStatus(403);

        $this->assertSame(HrProbationConfirmation::PENDING, $conf->fresh()->status);
    }

    public function test_a_ladder_does_not_leak_to_another_tenant(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'pcwf-o', 'status' => 'active']);
        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER]]);

        $shown = app(WorkflowConfigService::class)->show($other->id, ApprovalProcess::PROBATION_CONFIRMATION);

        $this->assertNull($shown['workflow']);
        $this->assertSame([], $shown['steps']);
    }

    /* ── 3-4. capability and scope ────────────────────────────────────── */

    public function test_an_approver_without_the_capability_is_refused(): void
    {
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'NoCap', 'slug' => 'nocap_pc',
            'permissions' => [], 'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);
        $approver = $this->user('nocap@pc.test', $role);
        $conf = $this->confirmation($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::SPECIFIC_USER, 'approver_ref' => $approver->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($conf)->assertStatus(403);

        $this->assertSame(HrProbationConfirmation::PENDING, $conf->fresh()->status);
    }

    public function test_an_out_of_scope_approver_is_refused_even_when_named(): void
    {
        $deptRole = $this->role(DataScope::DEPARTMENT, 'DeptPc');
        $approver = $this->user('dept@pc.test', $deptRole);
        $this->employee(['user_id' => $approver->id, 'department' => 'Ops']);

        $outsider = $this->employee(['department' => 'Sales']);
        $conf = $this->confirmation($outsider);

        $this->saveLadder([['approver_type' => ApproverType::SPECIFIC_USER, 'approver_ref' => $approver->id]]);

        Sanctum::actingAs($approver);

        $this->approveVia($conf)->assertStatus(404);
        $this->assertSame(HrProbationConfirmation::PENDING, $conf->fresh()->status);
    }

    /* ── 5-7. sequence, and the irreversible act it protects ──────────── */

    public function test_a_single_step_ladder_approves_on_one_decision(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'OnePc');
        $approver = $this->user('one@pc.test', $role);
        $conf = $this->confirmation($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($conf)->assertOk();

        $this->assertSame(HrProbationConfirmation::APPROVED, $conf->fresh()->status);
    }

    public function test_a_half_approved_probation_cannot_be_closed(): void
    {
        $hrRole = $this->role(DataScope::GLOBAL, 'HrPc');
        $this->user('hrstep@pc.test', $hrRole);

        $managerUser = $this->user('mgr@pc.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $conf = $this->confirmation($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $hrRole->id, 'name' => 'HR head'],
        ]);

        Sanctum::actingAs($managerUser);
        $this->approveVia($conf)->assertOk();

        // Still Pending after the first rung.
        $this->assertSame(HrProbationConfirmation::PENDING, $conf->fresh()->status);

        // THE property: confirm() refuses, so the probation cannot be closed
        // on a decision nobody has finished making. Closing it is irreversible.
        $this->confirmVia($conf)->assertStatus(422);

        $this->assertSame(
            HrEmployeeProbation::ACTIVE,
            $conf->fresh()->probation->current_status,
            'The probation must still be open.'
        );
    }

    public function test_the_terminal_approval_unlocks_confirmation_and_runs_once(): void
    {
        $hrRole = $this->role(DataScope::GLOBAL, 'HrPc2');
        $hrUser = $this->user('hrstep2@pc.test', $hrRole);

        $managerUser = $this->user('mgr2@pc.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $conf = $this->confirmation($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $hrRole->id, 'name' => 'HR head'],
        ]);

        Sanctum::actingAs($managerUser);
        $this->approveVia($conf)->assertOk();

        Sanctum::actingAs($hrUser);
        $this->approveVia($conf, 'confirmed by HR')->assertOk();

        $this->assertSame(HrProbationConfirmation::APPROVED, $conf->fresh()->status);
        $this->assertSame(1, $this->approvedAudits($conf));

        // Now — and only now — the employee can be confirmed.
        $this->confirmVia($conf)->assertOk();

        $this->assertSame(HrProbationConfirmation::CONFIRMED, $conf->fresh()->status);
        $this->assertSame(HrEmployeeProbation::CONFIRMED, $conf->fresh()->probation->current_status);
    }

    public function test_the_second_rung_cannot_act_before_the_first(): void
    {
        $hrRole = $this->role(DataScope::GLOBAL, 'HrPc3');
        $hrUser = $this->user('hrstep3@pc.test', $hrRole);

        $managerUser = $this->user('mgr3@pc.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $conf = $this->confirmation($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $hrRole->id, 'name' => 'HR head'],
        ]);

        Sanctum::actingAs($hrUser);
        $this->approveVia($conf)->assertStatus(403);

        $this->assertSame(HrProbationConfirmation::PENDING, $conf->fresh()->status);
    }

    /* ── 8-9. rejection and blocking ──────────────────────────────────── */

    public function test_rejection_is_terminal_and_keeps_the_domain_behaviour(): void
    {
        $managerUser = $this->user('mgrrej@pc.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $conf = $this->confirmation($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Skip', 'levels_up' => 2],
        ]);

        Sanctum::actingAs($managerUser);
        $this->rejectVia($conf, 'performance short')->assertOk();

        $fresh = $conf->fresh();
        $this->assertSame(HrProbationConfirmation::REJECTED, $fresh->status);

        // A rejected confirmation cannot close the probation.
        $this->confirmVia($conf)->assertStatus(422);
        $this->assertSame(HrEmployeeProbation::ACTIVE, $fresh->probation->current_status);

        $this->assertSame(ApprovalState::REJECTED, $this->request($conf)->state);
    }

    public function test_an_unresolvable_rung_blocks_and_never_approves(): void
    {
        $employee = $this->employee();               // no reporting manager
        $conf = $this->confirmation($employee);

        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER]]);

        $hr = $this->user('hrblock@pc.test', null, 'admin');
        Sanctum::actingAs($hr);

        $this->approveVia($conf)->assertStatus(409);

        $this->assertSame(HrProbationConfirmation::PENDING, $conf->fresh()->status);
        $this->assertSame(ApprovalState::BLOCKED, $this->request($conf)->state);
    }

    /* ── 10. the snapshot ─────────────────────────────────────────────── */

    public function test_editing_the_ladder_does_not_reroute_one_in_progress(): void
    {
        $hrRole = $this->role(DataScope::GLOBAL, 'SnapPc');
        $hrUser = $this->user('snap@pc.test', $hrRole);

        $managerUser = $this->user('mgrsnap@pc.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $conf = $this->confirmation($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $hrRole->id, 'name' => 'HR head'],
        ]);

        Sanctum::actingAs($managerUser);
        $this->approveVia($conf)->assertOk();

        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager only']]);

        $request = $this->request($conf);
        $this->assertCount(2, $request->steps_snapshot);
        $this->assertSame(2, $request->current_step);

        Sanctum::actingAs($hrUser);
        $this->approveVia($conf)->assertOk();
        $this->assertSame(HrProbationConfirmation::APPROVED, $conf->fresh()->status);
    }

    /* ── 11. duplicate decisions ──────────────────────────────────────── */

    public function test_a_second_approval_cannot_decide_twice(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'DupPc');
        $approver = $this->user('dup@pc.test', $role);
        $conf = $this->confirmation($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($conf)->assertOk();

        // "Only a pending confirmation can be approved."
        $this->approveVia($conf)->assertStatus(422);

        $this->assertSame(1, $this->approvedAudits($conf));
    }

    /* ── 12. bypass ───────────────────────────────────────────────────── */

    public function test_posted_step_fields_cannot_skip_the_ladder(): void
    {
        $hrRole = $this->role(DataScope::GLOBAL, 'BypPc');
        $hrUser = $this->user('byp@pc.test', $hrRole);

        $managerUser = $this->user('mgrbyp@pc.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $conf = $this->confirmation($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $hrRole->id, 'name' => 'HR head'],
        ]);

        Sanctum::actingAs($hrUser);
        $this->patchJson("/api/hr/probation/confirmations/{$conf->id}/approve", [
            'step' => 2, 'current_step' => 2, 'final' => true, 'status' => 'Approved',
        ])->assertStatus(403);

        $this->assertSame(HrProbationConfirmation::PENDING, $conf->fresh()->status);
    }

    /* ── domain rules the ladder must not take away ───────────────────── */

    public function test_confirm_is_not_a_rung_and_needs_no_ladder_of_its_own(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'ConfPc');
        $approver = $this->user('conf@pc.test', $role);
        $conf = $this->confirmation($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($conf)->assertOk();

        // Executing an approved decision is not a second approval, so it
        // consumes no rung and records no approval action beyond the one.
        $this->confirmVia($conf)->assertOk();

        $this->assertSame(HrProbationConfirmation::CONFIRMED, $conf->fresh()->status);
        $this->assertDatabaseCount('hr_approval_actions', 1);
    }

    public function test_an_approved_confirmation_can_still_be_rejected(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'RejPc');
        $approver = $this->user('rejafter@pc.test', $role);
        $conf = $this->confirmation($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($conf)->assertOk();

        // The domain allows a rethink right up until the employee is actually
        // confirmed, and the ladder does not remove that.
        $this->rejectVia($conf, 'changed our mind')->assertOk();

        $this->assertSame(HrProbationConfirmation::REJECTED, $conf->fresh()->status);
        $this->assertSame(HrEmployeeProbation::ACTIVE, $conf->fresh()->probation->current_status);
    }

    public function test_the_review_required_guard_is_currently_unreachable(): void
    {
        // A probation type that insists on a completed review.
        $strictType = DB::table('hr_probation_types')->insertGetId([
            'tenant_id' => $this->tenant->id, 'name' => 'Strict', 'code' => 'STR',
            'default_duration_days' => 180, 'confirmation_required' => true,
            'review_required' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $role = $this->role(DataScope::GLOBAL, 'RevPc');
        $approver = $this->user('rev@pc.test', $role);
        $employee = $this->employee();

        $probation = HrEmployeeProbation::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'probation_policy_id' => $this->policyId, 'probation_type_id' => $strictType,
            'probation_start_date' => '2026-01-01', 'probation_end_date' => '2026-06-30',
            'current_status' => HrEmployeeProbation::ACTIVE,
        ]);
        $conf = HrProbationConfirmation::create([
            'tenant_id' => $this->tenant->id, 'probation_id' => $probation->id,
            'employee_id' => $employee->id, 'status' => HrProbationConfirmation::PENDING,
        ]);

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);

        /*
         | PRE-EXISTING DEFECT, pinned here rather than fixed.
         |
         | ProbationConfirmationService::approve() means to refuse when the
         | probation type requires a completed review and none exists. That
         | guard never fires, because ProbationConfirmationRepository::EAGER
         | loads 'probation.probationType:id,name' — a column list that omits
         | review_required, so the property reads NULL and `?? false` swallows
         | it. The rule is dead on the normal approve() path.
         |
         | This phase migrates the approval ladder and does not change probation
         | business rules, so the test asserts what the system ACTUALLY does.
         | Adding review_required to that eager load is a one-word fix and will
         | make this test fail — which is the point: whoever fixes it should
         | flip this assertion deliberately rather than discover it later.
         */
        $this->approveVia($conf)->assertOk();

        $this->assertTrue(
            $conf->fresh()->probation->probationType?->review_required,
            'The type really does require a review — the guard simply cannot see it.'
        );
    }

    /* ── settings surface ─────────────────────────────────────────────── */

    public function test_probation_confirmation_is_configurable_alongside_the_others(): void
    {
        $admin = $this->user('admin@pc.test', null, 'admin');

        Sanctum::actingAs($admin);
        $keys = array_column($this->getJson('/api/hr/approval-workflows')->assertOk()->json('data'), 'process');

        $this->assertContains(ApprovalProcess::PROBATION_CONFIRMATION, $keys);

        // Exit clearance stayed out — it is parallel, not sequential.
        $this->assertNotContains('exit_clearance', $keys);

        foreach ([
            ApprovalProcess::LEAVE, ApprovalProcess::LOAN, ApprovalProcess::VARIABLE_EARNING,
            ApprovalProcess::INVESTMENT_DECLARATION, ApprovalProcess::REIMBURSEMENT,
            ApprovalProcess::EXIT_REQUEST,
        ] as $p) {
            $this->assertContains($p, $keys, 'Earlier processes must stay configurable.');
        }
    }
}
