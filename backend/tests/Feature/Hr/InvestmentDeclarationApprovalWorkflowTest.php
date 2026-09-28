<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrApprovalRequest;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrInvestmentDeclaration;
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
 * Investment declarations on the approval workflow engine — Phase 4.
 *
 * This process is NOT shaped like the three before it, and the differences are
 * what these tests are mostly about:
 *
 *   VERIFICATION, NOT APPROVAL. The terminal state is Verified, and verifying
 *   carries a payload — the per-item verified_amount that overrides what the
 *   employee claimed, and the totals recalculated from it. So on a multi-step
 *   ladder only the LAST rung's figures are applied.
 *
 *   TAX. HrInvestmentDeclaration::countsForTax() is exactly
 *   `status === VERIFIED`, and TdsEngine and Form16Service both read it. A
 *   declaration halfway up a ladder is still Submitted, so it reduces nobody's
 *   tax. That is the property the multi-step tests guard, and it is why no
 *   statutory code had to change.
 *
 *   REOPEN. A verified declaration can be reopened to Draft and resubmitted,
 *   which is an un-decide. The repeat-round behaviour added for variable
 *   earnings covers it — a resubmitted declaration opens a fresh round rather
 *   than reusing the closed one.
 */
class InvestmentDeclarationApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'idwf', 'status' => 'active']);
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

    /** A submitted declaration with one 80C item. */
    private function declaration(
        HrEmployee $employee,
        string $status = HrInvestmentDeclaration::SUBMITTED,
        float $declared = 50000
    ): HrInvestmentDeclaration {
        $d = HrInvestmentDeclaration::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'financial_year' => '2026-27', 'regime' => HrInvestmentDeclaration::OLD,
            'status' => $status, 'declared_total' => $declared, 'verified_total' => 0,
        ]);

        DB::table('hr_investment_declaration_items')->insert([
            'tenant_id' => $this->tenant->id, 'declaration_id' => $d->id,
            'section' => '80C', 'particulars' => 'PPF',
            'declared_amount' => $declared, 'verified_amount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $d->fresh('items');
    }

    private function saveLadder(array $steps, bool $active = true): void
    {
        app(WorkflowConfigService::class)->save($this->tenant->id, ApprovalProcess::INVESTMENT_DECLARATION, [
            'name' => 'Declaration verification', 'is_active' => $active, 'steps' => $steps,
        ]);
    }

    private function verifyVia(HrInvestmentDeclaration $d, array $payload = [])
    {
        return $this->postJson("/api/hr/payroll/declarations/{$d->id}/verify", $payload);
    }

    private function rejectVia(HrInvestmentDeclaration $d, string $remarks = 'proofs missing')
    {
        return $this->postJson("/api/hr/payroll/declarations/{$d->id}/reject", ['remarks' => $remarks]);
    }

    private function verifiedAudits(HrInvestmentDeclaration $d): int
    {
        return DB::table('audit_logs')
            ->where('auditable_type', HrInvestmentDeclaration::class)
            ->where('auditable_id', $d->id)
            ->where('action', 'Declaration Verified')->count();
    }

    private function request(HrInvestmentDeclaration $d)
    {
        return HrApprovalRequest::where('subject_type', HrInvestmentDeclaration::class)
            ->where('subject_id', $d->id)->latest('id')->first();
    }

    /* ── 1-2. legacy compatibility and tenancy ────────────────────────── */

    public function test_with_no_ladder_configured_verification_works_as_before(): void
    {
        $hr = $this->user('hr@id.test', null, 'admin');
        $d = $this->declaration($this->employee());

        Sanctum::actingAs($hr);
        $this->verifyVia($d)->assertOk();

        $this->assertSame(HrInvestmentDeclaration::VERIFIED, $d->fresh()->status);
    }

    public function test_the_legacy_rung_still_refuses_a_non_hr_user(): void
    {
        $nobody = $this->user('nobody@id.test');
        $d = $this->declaration($this->employee());

        Sanctum::actingAs($nobody);
        $this->verifyVia($d)->assertStatus(403);

        $this->assertSame(HrInvestmentDeclaration::SUBMITTED, $d->fresh()->status);
    }

    public function test_a_ladder_does_not_leak_to_another_tenant(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'idwf-o', 'status' => 'active']);
        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER]]);

        $shown = app(WorkflowConfigService::class)->show($other->id, ApprovalProcess::INVESTMENT_DECLARATION);

        $this->assertNull($shown['workflow']);
        $this->assertSame([], $shown['steps']);
    }

    /* ── 3-4. capability and scope ────────────────────────────────────── */

    public function test_an_approver_without_the_capability_is_refused(): void
    {
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'NoCap', 'slug' => 'nocap_id',
            'permissions' => [], 'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);
        $approver = $this->user('nocap@id.test', $role);
        $d = $this->declaration($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::SPECIFIC_USER, 'approver_ref' => $approver->id]]);

        Sanctum::actingAs($approver);
        $this->verifyVia($d)->assertStatus(403);

        $this->assertSame(HrInvestmentDeclaration::SUBMITTED, $d->fresh()->status);
    }

    public function test_an_out_of_scope_approver_is_refused_even_when_named(): void
    {
        $deptRole = $this->role(DataScope::DEPARTMENT, 'DeptId');
        $approver = $this->user('dept@id.test', $deptRole);
        $this->employee(['user_id' => $approver->id, 'department' => 'Ops']);

        $outsider = $this->employee(['department' => 'Sales']);
        $d = $this->declaration($outsider);

        $this->saveLadder([['approver_type' => ApproverType::SPECIFIC_USER, 'approver_ref' => $approver->id]]);

        Sanctum::actingAs($approver);

        // Rent, landlord PAN and medical claims are among the most personal
        // records HR holds. Being named on a step grants no access to them.
        $this->verifyVia($d)->assertStatus(404);
        $this->assertSame(HrInvestmentDeclaration::SUBMITTED, $d->fresh()->status);
    }

    /* ── 5-7. sequence, and the tax property it protects ──────────────── */

    public function test_a_single_step_ladder_verifies_on_one_decision(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'OneId');
        $approver = $this->user('one@id.test', $role);
        $d = $this->declaration($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->verifyVia($d)->assertOk();

        $this->assertSame(HrInvestmentDeclaration::VERIFIED, $d->fresh()->status);
    }

    public function test_an_intermediate_approval_does_not_reduce_anybodys_tax(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinId');
        $financeUser = $this->user('fin@id.test', $finance);

        $managerUser = $this->user('mgr@id.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $d = $this->declaration($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($managerUser);
        $this->verifyVia($d, ['items' => [['id' => $d->items->first()->id, 'verified_amount' => 50000]]])
            ->assertOk();

        $fresh = $d->fresh();
        $this->assertSame(HrInvestmentDeclaration::SUBMITTED, $fresh->status);
        $this->assertNull($fresh->verified_at);

        // The property that matters: countsForTax() is exactly
        // status === VERIFIED, and TdsEngine reads it. Half-verified reduces
        // nobody's tax.
        $this->assertFalse($fresh->countsForTax(),
            'A mid-ladder declaration must not count for tax.');

        // And the intermediate approver's figures were NOT applied.
        $this->assertSame(0.0, (float) $fresh->verified_total);

        $this->assertSame(2, $this->request($d)->current_step);
    }

    public function test_the_terminal_verification_applies_figures_once_and_counts_for_tax(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinId2');
        $financeUser = $this->user('fin2@id.test', $finance);

        $managerUser = $this->user('mgr2@id.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $d = $this->declaration($employee, HrInvestmentDeclaration::SUBMITTED, 50000);
        $itemId = $d->items->first()->id;

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        // Manager waves it through claiming the full 50,000 — ignored.
        Sanctum::actingAs($managerUser);
        $this->verifyVia($d, ['items' => [['id' => $itemId, 'verified_amount' => 50000]]])->assertOk();

        // Finance only has proof for 30,000. Theirs is the figure that lands.
        Sanctum::actingAs($financeUser);
        $this->verifyVia($d, ['items' => [['id' => $itemId, 'verified_amount' => 30000]]])->assertOk();

        $fresh = $d->fresh();
        $this->assertSame(HrInvestmentDeclaration::VERIFIED, $fresh->status);
        $this->assertTrue($fresh->countsForTax());
        $this->assertSame(30000.0, (float) $fresh->verified_total,
            'Only the terminal rung’s figures are applied.');
        $this->assertSame(1, $this->verifiedAudits($d));
    }

    public function test_the_second_rung_cannot_act_before_the_first(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinId3');
        $financeUser = $this->user('fin3@id.test', $finance);

        $managerUser = $this->user('mgr3@id.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $d = $this->declaration($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($financeUser);
        $this->verifyVia($d)->assertStatus(403);

        $this->assertSame(HrInvestmentDeclaration::SUBMITTED, $d->fresh()->status);
    }

    /* ── 8. amount conditions route on what is CLAIMED ────────────────── */

    public function test_a_large_claim_picks_up_the_extra_rung(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinAmtId');
        $financeUser = $this->user('finamt@id.test', $finance);

        $managerUser = $this->user('mgramt@id.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);

        // A declaration is unique per (tenant, employee, financial year), so
        // the two claims belong to two people on the same reporting line
        // rather than to one person twice.
        $modest = $this->employee(['reporting_manager_id' => $manager->id]);
        $big    = $this->employee(['reporting_manager_id' => $manager->id]);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id,
             'name' => 'Finance', 'conditions' => ['min_amount' => 100000]],
        ]);

        $small = $this->declaration($modest, HrInvestmentDeclaration::SUBMITTED, 40000);
        Sanctum::actingAs($managerUser);
        $this->verifyVia($small)->assertOk();
        $this->assertSame(HrInvestmentDeclaration::VERIFIED, $small->fresh()->status);

        $large = $this->declaration($big, HrInvestmentDeclaration::SUBMITTED, 400000);
        $this->verifyVia($large)->assertOk();
        $this->assertSame(HrInvestmentDeclaration::SUBMITTED, $large->fresh()->status);

        Sanctum::actingAs($financeUser);
        $this->verifyVia($large)->assertOk();
        $this->assertSame(HrInvestmentDeclaration::VERIFIED, $large->fresh()->status);
    }

    /* ── 9-10. rejection and blocking ─────────────────────────────────── */

    public function test_rejection_is_terminal_and_keeps_the_domain_behaviour(): void
    {
        $managerUser = $this->user('mgrrej@id.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $d = $this->declaration($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Skip', 'levels_up' => 2],
        ]);

        Sanctum::actingAs($managerUser);
        $this->rejectVia($d, 'no rent receipts')->assertOk();

        $fresh = $d->fresh();
        $this->assertSame(HrInvestmentDeclaration::REJECTED, $fresh->status);
        $this->assertSame('no rent receipts', $fresh->remarks);
        $this->assertFalse($fresh->countsForTax());

        $this->assertSame(ApprovalState::REJECTED, $this->request($d)->state);
    }

    public function test_an_unresolvable_rung_blocks_and_never_verifies(): void
    {
        $employee = $this->employee();                 // no reporting manager
        $d = $this->declaration($employee);

        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER]]);

        $hr = $this->user('hrblock@id.test', null, 'admin');
        Sanctum::actingAs($hr);

        $this->verifyVia($d)->assertStatus(409);

        $this->assertSame(HrInvestmentDeclaration::SUBMITTED, $d->fresh()->status);
        $this->assertFalse($d->fresh()->countsForTax());
        $this->assertSame(ApprovalState::BLOCKED, $this->request($d)->state);
    }

    /* ── 11. the snapshot ─────────────────────────────────────────────── */

    public function test_editing_the_ladder_does_not_reroute_a_declaration_in_progress(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'FinSnapId');
        $financeUser = $this->user('finsnap@id.test', $finance);

        $managerUser = $this->user('mgrsnap@id.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $d = $this->declaration($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($managerUser);
        $this->verifyVia($d)->assertOk();

        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager only']]);

        $request = $this->request($d);
        $this->assertCount(2, $request->steps_snapshot);
        $this->assertSame(2, $request->current_step);

        Sanctum::actingAs($financeUser);
        $this->verifyVia($d)->assertOk();
        $this->assertSame(HrInvestmentDeclaration::VERIFIED, $d->fresh()->status);
    }

    /* ── 12. duplicate decisions ──────────────────────────────────────── */

    public function test_a_second_verification_cannot_run_the_final_action_twice(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'DupId');
        $approver = $this->user('dup@id.test', $role);
        $d = $this->declaration($this->employee());

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->verifyVia($d)->assertOk();

        // The service's own guard — "only a submitted declaration can be
        // verified" — is what refuses the second press.
        $this->verifyVia($d)->assertStatus(422);

        $this->assertSame(1, $this->verifiedAudits($d));
    }

    /* ── 13. bypass ───────────────────────────────────────────────────── */

    public function test_posted_step_fields_cannot_skip_the_ladder(): void
    {
        $finance = $this->role(DataScope::GLOBAL, 'BypId');
        $financeUser = $this->user('byp@id.test', $finance);

        $managerUser = $this->user('mgrbyp@id.test', null, 'admin');
        $manager = $this->employee(['user_id' => $managerUser->id]);
        $employee = $this->employee(['reporting_manager_id' => $manager->id]);
        $d = $this->declaration($employee);

        $this->saveLadder([
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $finance->id, 'name' => 'Finance'],
        ]);

        Sanctum::actingAs($financeUser);
        $this->postJson("/api/hr/payroll/declarations/{$d->id}/verify", [
            'step' => 2, 'current_step' => 2, 'final' => true, 'status' => 'Verified',
        ])->assertStatus(403);

        $this->assertSame(HrInvestmentDeclaration::SUBMITTED, $d->fresh()->status);
    }

    /* ── reopen — the un-decide path unique to this process ───────────── */

    public function test_reopening_is_not_a_rung_and_stays_available(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'ReopenId');
        $approver = $this->user('reopen@id.test', $role);
        $d = $this->declaration($this->employee());

        // Even with a two-rung ladder, reopening is an administrative
        // correction rather than an approval step.
        $this->saveLadder([
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id, 'name' => 'Finance'],
            ['approver_type' => ApproverType::REPORTING_MANAGER, 'name' => 'Manager'],
        ]);

        Sanctum::actingAs($approver);
        $this->postJson("/api/hr/payroll/declarations/{$d->id}/reopen")->assertOk();

        $fresh = $d->fresh();
        $this->assertSame(HrInvestmentDeclaration::DRAFT, $fresh->status);
        $this->assertFalse($fresh->countsForTax());
    }

    public function test_a_reopened_declaration_can_be_verified_again_on_a_fresh_round(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'RoundId');
        $approver = $this->user('round@id.test', $role);
        $employee = $this->employee();
        $d = $this->declaration($employee, HrInvestmentDeclaration::SUBMITTED, 50000);

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->verifyVia($d)->assertOk();
        $this->assertSame(HrInvestmentDeclaration::VERIFIED, $d->fresh()->status);

        // Reopen to Draft, then submit again — the declaration comes back for a
        // second decision, exactly as an edited variable earning does.
        $this->postJson("/api/hr/payroll/declarations/{$d->id}/reopen")->assertOk();
        $this->postJson("/api/hr/payroll/declarations/{$d->id}/submit")->assertOk();
        $this->assertSame(HrInvestmentDeclaration::SUBMITTED, $d->fresh()->status);

        // A fresh round, not the closed one from the first pass.
        $this->verifyVia($d)->assertOk();
        $this->assertSame(HrInvestmentDeclaration::VERIFIED, $d->fresh()->status);

        $rounds = HrApprovalRequest::where('subject_type', HrInvestmentDeclaration::class)
            ->where('subject_id', $d->id)->count();
        $this->assertSame(2, $rounds, 'Each decision round keeps its own history.');
    }

    /* ── tax safety at the boundary ───────────────────────────────────── */

    public function test_submitting_does_not_go_through_the_ladder(): void
    {
        $hr = $this->user('sub@id.test', null, 'admin');
        $employee = $this->employee();
        $d = $this->declaration($employee, HrInvestmentDeclaration::DRAFT);

        // A ladder is configured, and submitting is still the employee raising
        // the claim — not a decision about it.
        $this->saveLadder([['approver_type' => ApproverType::REPORTING_MANAGER]]);

        Sanctum::actingAs($hr);
        $this->postJson("/api/hr/payroll/declarations/{$d->id}/submit")->assertOk();

        $this->assertSame(HrInvestmentDeclaration::SUBMITTED, $d->fresh()->status);
        $this->assertFalse($d->fresh()->countsForTax());
    }

    /* ── settings surface ─────────────────────────────────────────────── */

    public function test_declarations_are_configurable_alongside_the_others(): void
    {
        $admin = $this->user('admin@id.test', null, 'admin');

        Sanctum::actingAs($admin);
        $keys = array_column($this->getJson('/api/hr/approval-workflows')->assertOk()->json('data'), 'process');

        $this->assertContains(ApprovalProcess::INVESTMENT_DECLARATION, $keys);
        foreach ([ApprovalProcess::LEAVE, ApprovalProcess::LOAN, ApprovalProcess::VARIABLE_EARNING] as $p) {
            $this->assertContains($p, $keys, 'Earlier processes must stay configurable.');
        }
    }

    public function test_the_process_routes_on_the_claimed_amount(): void
    {
        $admin = $this->user('admin2@id.test', null, 'admin');

        Sanctum::actingAs($admin);
        $shown = $this->getJson('/api/hr/approval-workflows/investment_declaration')->assertOk()->json();

        $this->assertTrue($shown['options']['supports_amount']);
    }
}
