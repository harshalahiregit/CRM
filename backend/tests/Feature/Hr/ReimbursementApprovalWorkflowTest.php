<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrApprovalRequest;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrReimbursement;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\Approval\WorkflowConfigService;
use App\Services\Hr\ReimbursementService;
use App\Support\Hr\Approval\ApprovalProcess;
use App\Support\Hr\Approval\ApprovalState;
use App\Support\Hr\Approval\ApproverType;
use App\Support\Hr\DataScope;
use App\Support\Hr\ReimbursementStatus;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Expense claims on the approval workflow engine — Phase 5.
 *
 * This lifecycle is the richest of the five so far, and three parts of it are
 * unlike anything before:
 *
 *   A NEGOTIATION. An admin can hold a claim with a PROPOSED amount, and the
 *   employee accepts it from their own screen — at which point
 *   ReimbursementService::acceptProposal() approves the claim outright. With a
 *   multi-step ladder that is a bypass, so only the final rung may make a
 *   binding offer. Holding to ask a question stays available at every rung.
 *
 *   PAYABILITY. payableAmount() returns amount_approved, which is null until
 *   somebody approves. An intermediate approval leaves it null, so a
 *   half-approved claim is not payable — that is the property the multi-step
 *   tests guard.
 *
 *   SELF-APPROVAL. The service has always refused a decision on your own claim.
 *   The ladder must not become a way around that, so it is checked before a
 *   rung is consumed rather than only at the end.
 */
class ReimbursementApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'rbwf', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function role(string $scope, string $key): StaffRole
    {
        return StaffRole::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'R'.$key, 'slug' => 'r_'.strtolower($key),
            'permissions' => [
                'hr_employees'   => [StaffPermission::VIEW_GLOBAL],
                'hr_attendance'  => [StaffPermission::VIEW_GLOBAL],
            ],
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

    private function claim(
        HrEmployee $employee,
        float $amount = 5000,
        string $status = ReimbursementStatus::PENDING
    ): HrReimbursement {
        return HrReimbursement::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'title' => 'Client travel', 'category' => 'Travel',
            'expense_date' => '2026-06-10',
            'amount_claimed' => $amount, 'status' => $status,
        ]);
    }

    private function saveLadder(array $steps, bool $active = true): void
    {
        app(WorkflowConfigService::class)->save($this->tenant->id, ApprovalProcess::REIMBURSEMENT, [
            'name' => 'Expense approval', 'is_active' => $active, 'steps' => $steps,
        ]);
    }

    private function approveVia(HrReimbursement $c, array $payload = [])
    {
        return $this->postJson("/api/hr/reimbursements/{$c->id}/approve", $payload);
    }

    private function declineVia(HrReimbursement $c, string $reason = 'no receipts')
    {
        return $this->postJson("/api/hr/reimbursements/{$c->id}/decline", ['reason' => $reason]);
    }

    private function holdVia(HrReimbursement $c, array $payload)
    {
        return $this->postJson("/api/hr/reimbursements/{$c->id}/hold", $payload);
    }

    private function request(HrReimbursement $c)
    {
        return HrApprovalRequest::where('subject_type', HrReimbursement::class)
            ->where('subject_id', $c->id)->latest('id')->first();
    }

    /* ── 1-2. legacy compatibility and tenancy ────────────────────────── */

    public function test_with_no_ladder_configured_a_claim_approves_as_before(): void
    {
        $hr = $this->user('hr@rb.test', null, 'admin');
        $claim = $this->claim($this->employee());

        Sanctum::actingAs($hr);
        $this->approveVia($claim)->assertOk();

        $fresh = $claim->fresh();
        $this->assertSame(ReimbursementStatus::APPROVED, $fresh->status);
        $this->assertSame(5000.0, $fresh->payableAmount());
    }

    public function test_the_legacy_rung_still_refuses_a_non_hr_user(): void
    {
        $nobody = $this->user('nobody@rb.test');
        $claim = $this->claim($this->employee());

        Sanctum::actingAs($nobody);
        $this->approveVia($claim)->assertStatus(403);

        $this->assertSame(ReimbursementStatus::PENDING, $claim->fresh()->status);
    }

    public function test_a_ladder_does_not_leak_to_another_tenant(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'rbwf-o', 'status' => 'active']);
        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER]]);

        $shown = app(WorkflowConfigService::class)->show($other->id, ApprovalProcess::REIMBURSEMENT);

        $this->assertNull($shown['workflow']);
        $this->assertSame([], $shown['steps']);
    }

    /* ── 3-4. capability and scope ────────────────────────────────────── */

    public function test_an_approver_without_the_capability_is_refused(): void
    {
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'NoCap', 'slug' => 'nocap_rb',
            'permissions' => [], 'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);
        $approver = $this->user('nocap@rb.test', $role);
        $claim = $this->claim($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::SPECIFIC_USER, 'approver_ref' => $approver->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($claim)->assertStatus(403);

        $this->assertSame(ReimbursementStatus::PENDING, $claim->fresh()->status);
    }

    public function test_an_out_of_scope_approver_is_refused_even_when_named(): void
    {
        $deptRole = $this->role(DataScope::DEPARTMENT, 'DeptRb');
        $approver = $this->user('dept@rb.test', $deptRole);
        $this->employee(['user_id' => $approver->id, 'department' => 'Ops']);

        $outsider = $this->employee(['department' => 'Sales']);
        $claim = $this->claim($outsider);

        $this->saveLadder([['approver_type' => ApproverType::SPECIFIC_USER, 'approver_ref' => $approver->id]]);

        Sanctum::actingAs($approver);

        // Reimbursements had no data scope at all before this phase — the
        // engine's second gate is what adds it to the decision path.
        $this->approveVia($claim)->assertStatus(404);
        $this->assertSame(ReimbursementStatus::PENDING, $claim->fresh()->status);
    }

    /* ── 5-7. sequence, and payability ────────────────────────────────── */

    public function test_a_single_step_ladder_approves_on_one_decision(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'OneRb');
        $approver = $this->user('one@rb.test', $role);
        $claim = $this->claim($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($claim)->assertOk();

        $this->assertSame(ReimbursementStatus::APPROVED, $claim->fresh()->status);
    }

    public function test_an_intermediate_approval_does_not_make_the_claim_payable(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinRb');
        $this->user('fin@rb.test', $finance);

        $managerUser = $this->user('mgr@rb.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $claim = $this->claim($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($managerUser);
        $this->approveVia($claim, ['amount' => 5000])->assertOk();

        $fresh = $claim->fresh();
        $this->assertSame(ReimbursementStatus::PENDING, $fresh->status);
        $this->assertNull($fresh->amount_approved);
        $this->assertNull($fresh->payableAmount(),
            'A half-approved claim must not be payable.');

        $this->assertSame(2, $this->request($claim)->current_step);
    }

    public function test_the_terminal_approval_applies_its_figure_once(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinRb2');
        $financeUser = $this->user('fin2@rb.test', $finance);

        $managerUser = $this->user('mgr2@rb.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $claim = $this->claim($employee, 5000);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        // Manager is happy with the full amount — not applied.
        Sanctum::actingAs($managerUser);
        $this->approveVia($claim, ['amount' => 5000])->assertOk();

        // Finance allows 3,000 with a reason. Theirs is the figure that lands.
        Sanctum::actingAs($financeUser);
        $this->approveVia($claim, ['amount' => 3000, 'reason' => 'meal cap'])->assertOk();

        $fresh = $claim->fresh();
        $this->assertSame(ReimbursementStatus::APPROVED, $fresh->status);
        $this->assertSame(3000.0, $fresh->payableAmount(),
            'Only the terminal rung’s figure is applied.');
    }

    public function test_the_second_rung_cannot_act_before_the_first(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinRb3');
        $financeUser = $this->user('fin3@rb.test', $finance);

        $managerUser = $this->user('mgr3@rb.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $claim = $this->claim($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($financeUser);
        $this->approveVia($claim)->assertStatus(403);

        $this->assertSame(ReimbursementStatus::PENDING, $claim->fresh()->status);
    }

    /* ── 8. amount routes on what is CLAIMED ──────────────────────────── */

    public function test_a_large_claim_picks_up_the_extra_rung(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinAmtRb');
        $financeUser = $this->user('finamt@rb.test', $finance);

        $managerUser = $this->user('mgramt@rb.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id,
             'name' => 'Finance', 'conditions' => ['min_amount' => 20000]],
        ]);

        $small = $this->claim($employee, 5000);
        Sanctum::actingAs($managerUser);
        $this->approveVia($small)->assertOk();
        $this->assertSame(ReimbursementStatus::APPROVED, $small->fresh()->status);

        $large = $this->claim($employee, 80000);
        $this->approveVia($large)->assertOk();
        $this->assertSame(ReimbursementStatus::PENDING, $large->fresh()->status);

        Sanctum::actingAs($financeUser);
        $this->approveVia($large)->assertOk();
        $this->assertSame(ReimbursementStatus::APPROVED, $large->fresh()->status);
    }

    public function test_the_claimed_amount_is_read_from_the_database(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinAmtRb2');
        $this->user('finamt2@rb.test', $finance);
        $managerUser = $this->user('mgramt2@rb.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id,
             'name' => 'Finance', 'conditions' => ['min_amount' => 20000]],
        ]);

        $large = $this->claim($employee, 80000);

        // Approving at a small amount must not shrink the ladder: routing reads
        // amount_claimed, not the figure being granted.
        Sanctum::actingAs($managerUser);
        $this->approveVia($large, ['amount' => 100, 'reason' => 'trimmed'])->assertOk();

        $this->assertSame(ReimbursementStatus::PENDING, $large->fresh()->status);
    }

    /* ── 9-10. rejection and blocking ─────────────────────────────────── */

    public function test_declining_is_terminal_and_keeps_the_domain_behaviour(): void
    {
        $managerUser = $this->user('mgrrej@rb.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $claim = $this->claim($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Skip', 'levels_up' => 2],
        ]);

        Sanctum::actingAs($managerUser);
        $this->declineVia($claim, 'personal expense')->assertOk();

        $fresh = $claim->fresh();
        $this->assertSame(ReimbursementStatus::DECLINED, $fresh->status);
        $this->assertNotNull($fresh->decided_at);
        $this->assertNull($fresh->payableAmount());

        $this->assertSame(ApprovalState::REJECTED, $this->request($claim)->state);
    }

    public function test_an_unresolvable_rung_blocks_and_never_approves(): void
    {
        $employee = $this->employee();               // no reporting manager
        $claim = $this->claim($employee);

        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER]]);

        $hr = $this->user('hrblock@rb.test', null, 'admin');
        Sanctum::actingAs($hr);

        $this->approveVia($claim)->assertStatus(409);

        $this->assertSame(ReimbursementStatus::PENDING, $claim->fresh()->status);
        $this->assertNull($claim->fresh()->payableAmount());
        $this->assertSame(ApprovalState::BLOCKED, $this->request($claim)->state);
    }

    /* ── 11. the snapshot ─────────────────────────────────────────────── */

    public function test_editing_the_ladder_does_not_reroute_a_claim_in_progress(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinSnapRb');
        $financeUser = $this->user('finsnap@rb.test', $finance);

        $managerUser = $this->user('mgrsnap@rb.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $claim = $this->claim($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($managerUser);
        $this->approveVia($claim)->assertOk();

        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager only']]);

        $request = $this->request($claim);
        $this->assertCount(2, $request->steps_snapshot);
        $this->assertSame(2, $request->current_step);

        Sanctum::actingAs($financeUser);
        $this->approveVia($claim)->assertOk();
        $this->assertSame(ReimbursementStatus::APPROVED, $claim->fresh()->status);
    }

    /* ── 12. duplicate decisions ──────────────────────────────────────── */

    public function test_a_second_approval_cannot_run_the_final_action_twice(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'DupRb');
        $approver = $this->user('dup@rb.test', $role);
        $claim = $this->claim($this->employee(), 5000);

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($claim, ['amount' => 4000, 'reason' => 'cap'])->assertOk();
        $this->assertSame(4000.0, $claim->fresh()->payableAmount());

        // assertOpen() in the service refuses the second press.
        $this->approveVia($claim, ['amount' => 9999, 'reason' => 'again'])->assertStatus(422);

        $this->assertSame(4000.0, $claim->fresh()->payableAmount(),
            'A refused second decision must not change the payable figure.');
    }

    /* ── 13. bypass ───────────────────────────────────────────────────── */

    public function test_posted_step_fields_cannot_skip_the_ladder(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'BypRb');
        $financeUser = $this->user('byp@rb.test', $finance);

        $managerUser = $this->user('mgrbyp@rb.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $claim = $this->claim($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($financeUser);
        $this->postJson("/api/hr/reimbursements/{$claim->id}/approve", [
            'step' => 2, 'current_step' => 2, 'final' => true, 'status' => 'approved',
        ])->assertStatus(403);

        $this->assertSame(ReimbursementStatus::PENDING, $claim->fresh()->status);
    }

    /* ── the negotiation — unique to this process ─────────────────────── */

    public function test_an_intermediate_approver_cannot_make_a_binding_offer(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'PropRb');
        $this->user('prop@rb.test', $finance);

        $managerUser = $this->user('mgrprop@rb.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $claim = $this->claim($employee, 5000);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($managerUser);

        // Accepting a proposal APPROVES the claim outright, so a proposal from
        // rung 1 would let the employee settle it without finance ever seeing
        // it. Refused.
        $this->holdVia($claim, ['reason' => 'too much', 'proposed_amount' => 2000])
            ->assertStatus(409);

        $this->assertNull($claim->fresh()->proposed_amount);
        $this->assertSame(ReimbursementStatus::PENDING, $claim->fresh()->status);
    }

    public function test_an_intermediate_approver_can_still_hold_to_ask_a_question(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'AskRb');
        $this->user('ask@rb.test', $finance);

        $managerUser = $this->user('mgrask@rb.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $claim = $this->claim($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($managerUser);

        // A hold without an offer is a question, and questions are fine at any
        // rung — that is what a hold is for.
        $this->holdVia($claim, ['reason' => 'which client was this for?'])->assertOk();

        $this->assertSame(ReimbursementStatus::ON_HOLD, $claim->fresh()->status);
    }

    public function test_the_final_approver_may_propose_and_the_employee_may_accept(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'FinalRb');
        $approver = $this->user('final@rb.test', $role);

        $employeeUser = $this->user('emp@rb.test');
        $employee = $this->employee(['user_id' => $employeeUser->id]);
        $claim = $this->claim($employee, 5000);

        // One rung, so this approver IS the final one and may bind the company.
        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->holdVia($claim, ['reason' => 'cap applies', 'proposed_amount' => 3000])->assertOk();
        $this->assertSame(3000.0, (float) $claim->fresh()->proposed_amount);

        // The employee's own screen — untouched by this phase.
        app(ReimbursementService::class)->acceptProposal($claim->fresh(), $employeeUser);

        $fresh = $claim->fresh();
        $this->assertSame(ReimbursementStatus::APPROVED, $fresh->status);
        $this->assertSame(3000.0, $fresh->payableAmount());
    }

    /* ── self-approval ────────────────────────────────────────────────── */

    public function test_being_named_an_approver_does_not_let_you_decide_your_own_claim(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'SelfRb');
        $selfUser = $this->user('self@rb.test', $role);
        $selfEmployee = $this->employee(['user_id' => $selfUser->id]);
        $claim = $this->claim($selfEmployee, 5000);

        // Named as THE approver on their own claim.
        $this->saveLadder([['approver_type' => ApproverType::SPECIFIC_USER, 'approver_ref' => $selfUser->id]]);

        Sanctum::actingAs($selfUser);
        $this->approveVia($claim)->assertStatus(403);

        $this->assertSame(ReimbursementStatus::PENDING, $claim->fresh()->status);

        // And no rung was consumed by the refusal.
        $request = $this->request($claim);
        $this->assertSame(1, $request->current_step);
        $this->assertDatabaseCount('hr_approval_actions', 0);
    }

    /* ── attendance app coexistence ───────────────────────────────────── */

    public function test_the_attendance_app_service_path_still_decides_directly(): void
    {
        $hr = $this->user('appsvc@rb.test', null, 'admin');
        $claim = $this->claim($this->employee(), 5000);

        // HrmAdminController calls the service directly with its own
        // reporting-line guard. A two-rung CRM ladder must not stop the phone.
        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Skip', 'levels_up' => 2],
        ]);

        app(ReimbursementService::class)->approve($claim, $hr, null, null);

        $this->assertSame(ReimbursementStatus::APPROVED, $claim->fresh()->status);
    }

    /* ── settings surface ─────────────────────────────────────────────── */

    public function test_reimbursements_are_configurable_alongside_the_others(): void
    {
        $admin = $this->user('admin@rb.test', null, 'admin');

        Sanctum::actingAs($admin);
        $keys = array_column($this->getJson('/api/hr/approval-workflows')->assertOk()->json('data'), 'process');

        $this->assertContains(ApprovalProcess::REIMBURSEMENT, $keys);
        foreach ([
            ApprovalProcess::LEAVE, ApprovalProcess::LOAN,
            ApprovalProcess::VARIABLE_EARNING, ApprovalProcess::INVESTMENT_DECLARATION,
        ] as $p) {
            $this->assertContains($p, $keys, 'Earlier processes must stay configurable.');
        }
    }
}
