<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrApprovalRequest;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLoan;
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
 * Loans on the approval workflow engine — Phase 2.
 *
 * Loans are the first process that carries money, so they are the first where
 * an amount condition means anything, and the first where approving the wrong
 * thing costs something. The rungs decide who signs; LoanService still owns the
 * status transition and its guards.
 *
 * Two behaviours specific to loans are pinned here because the ladder must not
 * quietly change either:
 *
 *   - a loan TYPE with requires_approval = false still goes straight to
 *     Approved on submit, without a ladder being demanded;
 *   - disburse, close and cancel are NOT approvals and stay off the ladder —
 *     they act on a loan that has already been approved.
 */
class LoanApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $loanTypeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'loanwf', 'status' => 'active']);
        $this->loanTypeId = $this->loanType();
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function loanType(bool $requiresApproval = true): int
    {
        return DB::table('hr_loan_types')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'name' => 'T'.substr(uniqid(), -5),
            'is_advance' => false, 'is_active' => true,
            'requires_approval' => $requiresApproval,
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

    private function loan(
        HrEmployee $employee,
        string $status = HrEmployeeLoan::SUBMITTED,
        float $principal = 10000,
        ?int $typeId = null
    ): HrEmployeeLoan {
        return HrEmployeeLoan::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'loan_type_id' => $typeId ?: $this->loanTypeId,
            'principal' => $principal, 'outstanding' => $principal,
            'total_payable' => $principal, 'total_repaid' => 0,
            'tenure_months' => 10, 'interest_rate' => 0,
            'status' => $status,
        ]);
    }

    private function saveLadder(array $steps, bool $active = true): void
    {
        app(WorkflowConfigService::class)->save($this->tenant->id, ApprovalProcess::LOAN, [
            'name' => 'Loan approval', 'is_active' => $active, 'steps' => $steps,
        ]);
    }

    private function approveVia(HrEmployeeLoan $loan)
    {
        return $this->postJson("/api/hr/loans/{$loan->id}/approve");
    }

    private function rejectVia(HrEmployeeLoan $loan, string $remarks = 'no')
    {
        return $this->postJson("/api/hr/loans/{$loan->id}/reject", ['remarks' => $remarks]);
    }

    /* ── 1. default / legacy compatibility ────────────────────────────── */

    public function test_with_no_ladder_configured_a_loan_approves_as_before(): void
    {
        $hr = $this->user('hr@loan.test', null, 'admin');
        $loan = $this->loan($this->employee());

        Sanctum::actingAs($hr);
        $this->approveVia($loan)->assertOk();

        $this->assertSame(HrEmployeeLoan::APPROVED, $loan->fresh()->status);
    }

    public function test_the_legacy_rung_still_refuses_a_non_hr_user(): void
    {
        $nobody = $this->user('nobody@loan.test');
        $loan = $this->loan($this->employee());

        Sanctum::actingAs($nobody);
        $this->approveVia($loan)->assertStatus(403);

        $this->assertSame(HrEmployeeLoan::SUBMITTED, $loan->fresh()->status);
    }

    /* ── 2. tenant isolation ──────────────────────────────────────────── */

    public function test_a_loan_ladder_does_not_leak_to_another_tenant(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'loanwf-o', 'status' => 'active']);

        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER]]);

        $shown = app(WorkflowConfigService::class)->show($other->id, ApprovalProcess::LOAN);

        $this->assertNull($shown['workflow']);
        $this->assertSame([], $shown['steps']);
    }

    /* ── 3-4. capability and scope gates ──────────────────────────────── */

    public function test_an_approver_without_the_capability_is_refused(): void
    {
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'NoCap', 'slug' => 'nocap_loan',
            'permissions' => [], 'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);
        $approver = $this->user('nocap@loan.test', $role);
        $loan = $this->loan($this->employee());

        $this->saveLadder([
            ['approver_type' => ApproverType::SPECIFIC_USER, 'approver_ref' => $approver->id],
        ]);

        Sanctum::actingAs($approver);
        $this->approveVia($loan)->assertStatus(403);

        $this->assertSame(HrEmployeeLoan::SUBMITTED, $loan->fresh()->status);
    }

    public function test_an_out_of_scope_approver_is_refused_even_when_named(): void
    {
        $deptRole = $this->role(DataScope::DEPARTMENT, 'DeptLoan');
        $approver = $this->user('dept@loan.test', $deptRole);
        $this->employee(['user_id' => $approver->id, 'department' => 'Ops']);

        $outsider = $this->employee(['department' => 'Sales']);
        $loan = $this->loan($outsider);

        $this->saveLadder([
            ['approver_type' => ApproverType::SPECIFIC_USER, 'approver_ref' => $approver->id],
        ]);

        Sanctum::actingAs($approver);

        // Named on the step, and still refused: approver membership is not a
        // grant of access to somebody else's employee record.
        $this->approveVia($loan)->assertStatus(404);
        $this->assertSame(HrEmployeeLoan::SUBMITTED, $loan->fresh()->status);
    }

    /* ── 5-7. sequence, and what an intermediate step must not do ─────── */

    public function test_an_intermediate_approval_does_not_approve_the_loan(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinLoan');
        $financeUser = $this->user('fin@loan.test', $finance);

        $managerUser = $this->user('mgr@loan.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $loan = $this->loan($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($managerUser);
        $this->approveVia($loan)->assertOk();

        // The loan has NOT been approved — no money is committed halfway up.
        $fresh = $loan->fresh();
        $this->assertSame(HrEmployeeLoan::SUBMITTED, $fresh->status);
        $this->assertNull($fresh->approved_at);

        $request = HrApprovalRequest::where('subject_id', $loan->id)
            ->where('subject_type', HrEmployeeLoan::class)->first();
        $this->assertSame(2, $request->current_step);
        $this->assertSame(ApprovalState::PENDING, $request->state);
    }

    public function test_the_final_approval_performs_the_domain_action_once(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinLoan2');
        $financeUser = $this->user('fin2@loan.test', $finance);

        $managerUser = $this->user('mgr2@loan.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $loan = $this->loan($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($managerUser);
        $this->approveVia($loan)->assertOk();

        Sanctum::actingAs($financeUser);
        $this->approveVia($loan)->assertOk();

        $fresh = $loan->fresh();
        $this->assertSame(HrEmployeeLoan::APPROVED, $fresh->status);
        $this->assertNotNull($fresh->approved_at);

        // Exactly one "Loan Approved" audit line — the domain action ran once.
        $this->assertSame(1, DB::table('audit_logs')
            ->where('auditable_type', HrEmployeeLoan::class)
            ->where('auditable_id', $loan->id)
            ->where('action', 'Loan Approved')->count());
    }

    public function test_the_second_rung_cannot_act_before_the_first(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinLoan3');
        $financeUser = $this->user('fin3@loan.test', $finance);

        $managerUser = $this->user('mgr3@loan.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $loan = $this->loan($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($financeUser);
        $this->approveVia($loan)->assertStatus(403);

        $this->assertSame(HrEmployeeLoan::SUBMITTED, $loan->fresh()->status);
    }

    /* ── 8. amount conditions — the first process that has money ──────── */

    public function test_a_small_loan_skips_the_rung_that_only_applies_above_a_limit(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinAmt');
        $financeUser = $this->user('finamt@loan.test', $finance);

        $managerUser = $this->user('mgramt@loan.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id,
             'name' => 'Finance', 'conditions' => ['min_amount' => 50000]],
        ]);

        // 10,000 — below the finance threshold, so the manager is the only rung
        // and their approval is final.
        $small = $this->loan($employee, HrEmployeeLoan::SUBMITTED, 10000);

        Sanctum::actingAs($managerUser);
        $this->approveVia($small)->assertOk();
        $this->assertSame(HrEmployeeLoan::APPROVED, $small->fresh()->status);
    }

    public function test_a_large_loan_keeps_the_rung_that_applies_above_the_limit(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinAmt2');
        $financeUser = $this->user('finamt2@loan.test', $finance);

        $managerUser = $this->user('mgramt2@loan.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id,
             'name' => 'Finance', 'conditions' => ['min_amount' => 50000]],
        ]);

        $large = $this->loan($employee, HrEmployeeLoan::SUBMITTED, 250000);

        Sanctum::actingAs($managerUser);
        $this->approveVia($large)->assertOk();

        // Still submitted: the big one needs finance too.
        $this->assertSame(HrEmployeeLoan::SUBMITTED, $large->fresh()->status);

        Sanctum::actingAs($financeUser);
        $this->approveVia($large)->assertOk();
        $this->assertSame(HrEmployeeLoan::APPROVED, $large->fresh()->status);
    }

    public function test_the_amount_is_read_from_the_database_not_the_request(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinAmt3');
        $financeUser = $this->user('finamt3@loan.test', $finance);
        $managerUser = $this->user('mgramt3@loan.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id,
             'name' => 'Finance', 'conditions' => ['min_amount' => 50000]],
        ]);

        $large = $this->loan($employee, HrEmployeeLoan::SUBMITTED, 250000);

        // Claiming a small principal must not shrink the ladder.
        Sanctum::actingAs($managerUser);
        $this->postJson("/api/hr/loans/{$large->id}/approve", ['principal' => 1, 'amount' => 1])->assertOk();

        $this->assertSame(HrEmployeeLoan::SUBMITTED, $large->fresh()->status,
            'The finance rung must survive a principal claimed in the request body.');
    }

    /* ── 9. rejection is terminal ─────────────────────────────────────── */

    public function test_rejection_at_the_first_rung_ends_the_ladder(): void
    {
        $managerUser = $this->user('mgrrej@loan.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $loan = $this->loan($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Skip', 'levels_up' => 2],
        ]);

        Sanctum::actingAs($managerUser);
        $this->rejectVia($loan, 'cannot afford it')->assertOk();

        $this->assertSame(HrEmployeeLoan::REJECTED, $loan->fresh()->status);
        $this->assertSame(ApprovalState::REJECTED, HrApprovalRequest::where('subject_id', $loan->id)
            ->where('subject_type', HrEmployeeLoan::class)->first()->state);
    }

    /* ── 10. unresolved approver blocks ───────────────────────────────── */

    public function test_an_unresolvable_rung_blocks_and_never_approves(): void
    {
        $employee = $this->employee();          // no reporting manager
        $loan = $this->loan($employee);

        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER]]);

        $hr = $this->user('hrblock@loan.test', null, 'admin');
        Sanctum::actingAs($hr);

        $this->approveVia($loan)->assertStatus(409);

        $this->assertSame(HrEmployeeLoan::SUBMITTED, $loan->fresh()->status,
            'A missing approver must never lend the money.');
        $this->assertSame(ApprovalState::BLOCKED, HrApprovalRequest::where('subject_id', $loan->id)
            ->where('subject_type', HrEmployeeLoan::class)->first()->state);
    }

    /* ── 11. the snapshot ─────────────────────────────────────────────── */

    public function test_editing_the_ladder_does_not_reroute_a_loan_in_progress(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinSnap');
        $financeUser = $this->user('finsnap@loan.test', $finance);

        $managerUser = $this->user('mgrsnap@loan.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $loan = $this->loan($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($managerUser);
        $this->approveVia($loan)->assertOk();

        // Rewritten to one rung while this loan sits on step 2.
        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager only']]);

        $request = HrApprovalRequest::where('subject_id', $loan->id)
            ->where('subject_type', HrEmployeeLoan::class)->first();

        $this->assertCount(2, $request->steps_snapshot);
        $this->assertSame(2, $request->current_step);

        Sanctum::actingAs($financeUser);
        $this->approveVia($loan)->assertOk();
        $this->assertSame(HrEmployeeLoan::APPROVED, $loan->fresh()->status);
    }

    /* ── 12. duplicate decisions ──────────────────────────────────────── */

    public function test_a_second_approval_cannot_run_the_domain_action_again(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'DupLoan');
        $approver = $this->user('dup@loan.test', $role);
        $loan = $this->loan($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($loan)->assertOk();

        // Second press: the service's own status guard refuses.
        $this->approveVia($loan)->assertStatus(422);

        $this->assertSame(1, DB::table('audit_logs')
            ->where('auditable_type', HrEmployeeLoan::class)
            ->where('auditable_id', $loan->id)
            ->where('action', 'Loan Approved')->count());
    }

    /* ── 13. bypass attempts ──────────────────────────────────────────── */

    public function test_posted_step_fields_cannot_skip_the_ladder(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'BypLoan');
        $financeUser = $this->user('byp@loan.test', $finance);

        $managerUser = $this->user('mgrbyp@loan.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $loan = $this->loan($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($financeUser);
        $this->postJson("/api/hr/loans/{$loan->id}/approve", [
            'step' => 2, 'current_step' => 2, 'final' => true, 'state' => 'approved',
        ])->assertStatus(403);

        $this->assertSame(HrEmployeeLoan::SUBMITTED, $loan->fresh()->status);
    }

    /* ── loan-specific behaviour the ladder must not change ───────────── */

    public function test_a_type_that_needs_no_approval_still_auto_approves_on_submit(): void
    {
        $autoType = $this->loanType(requiresApproval: false);
        $hr = $this->user('auto@loan.test', null, 'admin');
        $employee = $this->employee();
        $loan = $this->loan($employee, HrEmployeeLoan::DRAFT, 10000, $autoType);

        // A configured ladder must not override a loan type the company has
        // said needs no approval.
        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER]]);

        Sanctum::actingAs($hr);
        $this->postJson("/api/hr/loans/{$loan->id}/submit")->assertOk();

        $this->assertSame(HrEmployeeLoan::APPROVED, $loan->fresh()->status);
        $this->assertDatabaseMissing('hr_approval_requests', [
            'subject_type' => HrEmployeeLoan::class, 'subject_id' => $loan->id,
        ]);
    }

    public function test_disburse_is_not_an_approval_and_stays_off_the_ladder(): void
    {
        $hr = $this->user('disb@loan.test', null, 'admin');
        $employee = $this->employee();
        $loan = $this->loan($employee, HrEmployeeLoan::APPROVED);

        // A multi-rung ladder is configured, and disbursing an already-approved
        // loan is still not gated by it — that decision has been taken.
        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Skip', 'levels_up' => 2],
        ]);

        Sanctum::actingAs($hr);
        $this->postJson("/api/hr/loans/{$loan->id}/disburse", [
            'disbursed_on' => '2026-07-01', 'start_period' => '2026-08',
        ])->assertOk();

        $this->assertSame(HrEmployeeLoan::DISBURSED, $loan->fresh()->status);
    }

    /* ── settings surface ─────────────────────────────────────────────── */

    public function test_loans_appear_as_a_configurable_process(): void
    {
        $admin = $this->user('admin@loan.test', null, 'admin');

        Sanctum::actingAs($admin);
        $processes = $this->getJson('/api/hr/approval-workflows')->assertOk()->json('data');

        $keys = array_column($processes, 'process');
        $this->assertContains(ApprovalProcess::LOAN, $keys);
        $this->assertContains(ApprovalProcess::LEAVE, $keys, 'Leave must still be configurable.');
    }

    public function test_the_loan_process_offers_an_amount_condition(): void
    {
        $admin = $this->user('admin2@loan.test', null, 'admin');

        Sanctum::actingAs($admin);
        $shown = $this->getJson('/api/hr/approval-workflows/loan')->assertOk()->json();

        $this->assertTrue($shown['options']['supports_amount']);
        $this->assertContains('loan_type_id', $shown['options']['conditions']);
    }
}
