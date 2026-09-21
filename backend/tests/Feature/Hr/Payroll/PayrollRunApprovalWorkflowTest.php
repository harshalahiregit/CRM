<?php

namespace Tests\Feature\Hr\Payroll;

use App\Models\Hr\HrApprovalRequest;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrPayrollRun;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\Approval\WorkflowConfigService;
use App\Services\Settings\SettingsService;
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
 * Payroll run approval on the approval workflow engine — Phase 8.
 *
 * Payroll is unlike the seven processes before it in three ways, and each one
 * is what most of this suite is about.
 *
 *   NO EMPLOYEE. A run covers everybody in it, so there is no single person to
 *   scope against. ScopeResolver is asked with a null employee and correctly
 *   answers "nothing to check" — the capability and the ladder do the work.
 *
 *   REJECTION IS NOT TERMINAL. A rejected run goes back to Calculate for
 *   rework and can be approved afterwards, so a later approval must open a
 *   FRESH round rather than reuse the closed one.
 *
 *   SEGREGATION OF DUTIES. require_separate_approver refuses the person who
 *   processed the run. PayrollRunWorkflow enforces it, but only on the rung
 *   that reaches it, so the controller repeats the check at every rung.
 *
 * The property underneath all of them: PayrollRunWorkflow::approve() is what
 * moves the run to Disburse, and markPayment() refuses a run that is not
 * there. An intermediate rung never calls it, so nothing is payable until the
 * ladder finishes.
 */
class PayrollRunApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'prwf', 'status' => 'active']);
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

    private function employee(): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'E'.substr(uniqid(), -5),
            'employee_code' => 'E'.substr(uniqid(), -6),
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2024-01-01', 'status' => 'Active',
        ]);
    }

    /**
     * A calculated run waiting for a signature.
     *
     * status Completed + stage Approve is exactly what PayrollService::process()
     * leaves behind: the arithmetic is done and locked, nobody has agreed to it.
     */
    private function payrollRun(float $payable = 500000, ?int $processedBy = null): HrPayrollRun
    {
        return HrPayrollRun::create([
            'tenant_id' => $this->tenant->id,
            'payroll_month' => 7, 'payroll_year' => 2026,
            'status' => HrPayrollRun::COMPLETED,
            'stage' => HrPayrollRun::STAGE_APPROVE,
            'total_employees' => 1, 'total_payable' => $payable, 'total_net' => $payable,
            'processed_by' => $processedBy,
        ]);
    }

    private function recordFor(HrPayrollRun $run): HrPayrollRecord
    {
        return HrPayrollRecord::create([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id,
            'employee_id' => $this->employee()->id,
            'gross_salary' => 50000, 'total_deductions' => 0, 'net_salary' => 50000,
            'status' => HrPayrollRecord::PROCESSED,
        ]);
    }

    private function saveLadder(array $steps, bool $active = true): void
    {
        app(WorkflowConfigService::class)->save($this->tenant->id, ApprovalProcess::PAYROLL_RUN, [
            'name' => 'Payroll approval', 'is_active' => $active, 'steps' => $steps,
        ]);
    }

    private function approveVia(HrPayrollRun $r, ?string $note = null)
    {
        return $this->postJson("/api/hr/payroll/runs/{$r->id}/approve", ['note' => $note]);
    }

    private function rejectVia(HrPayrollRun $r, string $note = 'recheck the overtime')
    {
        return $this->postJson("/api/hr/payroll/runs/{$r->id}/reject", ['note' => $note]);
    }

    private function request(HrPayrollRun $r)
    {
        return HrApprovalRequest::where('subject_type', HrPayrollRun::class)
            ->where('subject_id', $r->id)->latest('id')->first();
    }

    /* ── 1-2. legacy compatibility and tenancy ────────────────────────── */

    public function test_with_no_ladder_configured_approval_works_as_before(): void
    {
        $hr = $this->user('hr@pr.test', null, 'admin');
        $run = $this->payrollRun();

        Sanctum::actingAs($hr);
        $this->approveVia($run, 'signed')->assertOk();

        $fresh = $run->fresh();
        $this->assertSame(HrPayrollRun::STAGE_DISBURSE, $fresh->stage);
        $this->assertTrue($fresh->isApproved());
    }

    public function test_the_legacy_rung_still_refuses_a_non_hr_user(): void
    {
        $nobody = $this->user('nobody@pr.test');
        $run = $this->payrollRun();

        Sanctum::actingAs($nobody);
        $this->approveVia($run)->assertStatus(403);

        $this->assertSame(HrPayrollRun::STAGE_APPROVE, $run->fresh()->stage);
    }

    public function test_a_ladder_does_not_leak_to_another_tenant(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'prwf-o', 'status' => 'active']);
        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $this->role(DataScope::GLOBAL, 'T')->id]]);

        $shown = app(WorkflowConfigService::class)->show($other->id, ApprovalProcess::PAYROLL_RUN);

        $this->assertNull($shown['workflow']);
        $this->assertSame([], $shown['steps']);
    }

    /* ── 3. capability ────────────────────────────────────────────────── */

    public function test_an_approver_without_the_capability_is_refused(): void
    {
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'NoCap', 'slug' => 'nocap_pr',
            'permissions' => [], 'scope' => DataScope::GLOBAL, 'is_system' => false,
        ]);
        $approver = $this->user('nocap@pr.test', $role);
        $run = $this->payrollRun();

        $this->saveLadder([['approver_type' => ApproverType::SPECIFIC_USER, 'approver_ref' => $approver->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($run)->assertStatus(403);

        $this->assertSame(HrPayrollRun::STAGE_APPROVE, $run->fresh()->stage);
    }

    /* ── 4-6. sequence, and the disbursement it protects ──────────────── */

    public function test_a_single_step_ladder_approves_on_one_decision(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'OnePr');
        $approver = $this->user('one@pr.test', $role);
        $run = $this->payrollRun();

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($run)->assertOk();

        $this->assertTrue($run->fresh()->isApproved());
    }

    public function test_an_intermediate_approval_leaves_payroll_undisbursable(): void
    {
        $directorRole = $this->role(DataScope::GLOBAL, 'DirPr');
        $this->user('dir@pr.test', $directorRole);

        $financeRole = $this->role(DataScope::GLOBAL, 'FinPr');
        $financeUser = $this->user('fin@pr.test', $financeRole);

        $run = $this->payrollRun();
        $record = $this->recordFor($run);

        $this->saveLadder([
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $financeRole->id, 'name' => 'Finance'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $directorRole->id, 'name' => 'Director'],
        ]);

        Sanctum::actingAs($financeUser);
        $this->approveVia($run)->assertOk();

        $fresh = $run->fresh();
        $this->assertSame(HrPayrollRun::STAGE_APPROVE, $fresh->stage);
        $this->assertFalse($fresh->isApproved());
        $this->assertNull($fresh->approved_at);

        // THE property: markPayment() refuses a run that is not approved, so a
        // half-signed payroll cannot pay anybody.
        $this->postJson("/api/hr/payroll/records/{$record->id}/payment", [
            'payment_status' => HrPayrollRecord::PAY_PAID,
        ])->assertStatus(422);

        $this->assertSame(2, $this->request($run)->current_step);
    }

    public function test_the_terminal_approval_unlocks_disbursement(): void
    {
        $directorRole = $this->role(DataScope::GLOBAL, 'DirPr2');
        $directorUser = $this->user('dir2@pr.test', $directorRole);

        $financeRole = $this->role(DataScope::GLOBAL, 'FinPr2');
        $financeUser = $this->user('fin2@pr.test', $financeRole);

        $run = $this->payrollRun();
        $record = $this->recordFor($run);

        $this->saveLadder([
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $financeRole->id, 'name' => 'Finance'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $directorRole->id, 'name' => 'Director'],
        ]);

        Sanctum::actingAs($financeUser);
        $this->approveVia($run)->assertOk();

        Sanctum::actingAs($directorUser);
        $this->approveVia($run, 'agreed')->assertOk();

        $fresh = $run->fresh();
        $this->assertTrue($fresh->isApproved());
        $this->assertSame(HrPayrollRun::STAGE_DISBURSE, $fresh->stage);
        $this->assertNotNull($fresh->approved_at);

        // Now accounts can record a transfer.
        $this->postJson("/api/hr/payroll/records/{$record->id}/payment", [
            'payment_status' => HrPayrollRecord::PAY_PAID,
        ])->assertOk();
    }

    public function test_the_second_rung_cannot_act_before_the_first(): void
    {
        $directorRole = $this->role(DataScope::GLOBAL, 'DirPr3');
        $directorUser = $this->user('dir3@pr.test', $directorRole);
        $financeRole = $this->role(DataScope::GLOBAL, 'FinPr3');
        $this->user('fin3@pr.test', $financeRole);

        $run = $this->payrollRun();

        $this->saveLadder([
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $financeRole->id, 'name' => 'Finance'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $directorRole->id, 'name' => 'Director'],
        ]);

        Sanctum::actingAs($directorUser);
        $this->approveVia($run)->assertStatus(403);

        $this->assertFalse($run->fresh()->isApproved());
    }

    /* ── 7. the amount condition — the only one payroll has ───────────── */

    public function test_a_large_run_picks_up_the_extra_rung(): void
    {
        $directorRole = $this->role(DataScope::GLOBAL, 'DirAmt');
        $directorUser = $this->user('diramt@pr.test', $directorRole);
        $financeRole = $this->role(DataScope::GLOBAL, 'FinAmt');
        $financeUser = $this->user('finamt@pr.test', $financeRole);

        $this->saveLadder([
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $financeRole->id, 'name' => 'Finance'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $directorRole->id,
             'name' => 'Director', 'conditions' => ['min_amount' => 1000000]],
        ]);

        // A small month: finance alone.
        $small = $this->payrollRun(400000);
        Sanctum::actingAs($financeUser);
        $this->approveVia($small)->assertOk();
        $this->assertTrue($small->fresh()->isApproved());

        // A big one: the director too.
        $big = HrPayrollRun::create([
            'tenant_id' => $this->tenant->id, 'payroll_month' => 8, 'payroll_year' => 2026,
            'status' => HrPayrollRun::COMPLETED, 'stage' => HrPayrollRun::STAGE_APPROVE,
            'total_employees' => 40, 'total_payable' => 5000000, 'total_net' => 5000000,
        ]);
        $this->approveVia($big)->assertOk();
        $this->assertFalse($big->fresh()->isApproved());

        Sanctum::actingAs($directorUser);
        $this->approveVia($big)->assertOk();
        $this->assertTrue($big->fresh()->isApproved());
    }

    public function test_payroll_offers_no_employee_level_conditions(): void
    {
        $admin = $this->user('admin@pr.test', null, 'admin');

        Sanctum::actingAs($admin);
        $shown = $this->getJson('/api/hr/approval-workflows/payroll_run')->assertOk()->json();

        // A run is not a person: department, branch and grade are facts about
        // an employee and mean nothing here. Only the amount bounds remain.
        $this->assertSame([], $shown['options']['conditions']);
        $this->assertTrue($shown['options']['supports_amount']);
    }

    /* ── 8. segregation of duties ─────────────────────────────────────── */

    public function test_the_processor_cannot_sign_their_own_run_at_the_final_rung(): void
    {
        app(SettingsService::class)->set($this->tenant->id, 'payroll', 'require_separate_approver', true);

        $role = $this->role(DataScope::GLOBAL, 'SepPr');
        $processor = $this->user('proc@pr.test', $role);
        $run = $this->payrollRun(500000, processedBy: $processor->id);

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($processor);
        $this->approveVia($run)->assertStatus(422);

        $this->assertFalse($run->fresh()->isApproved());
    }

    public function test_the_processor_cannot_sign_an_intermediate_rung_of_their_own_run(): void
    {
        app(SettingsService::class)->set($this->tenant->id, 'payroll', 'require_separate_approver', true);

        $financeRole = $this->role(DataScope::GLOBAL, 'SepFin');
        $processor = $this->user('procmid@pr.test', $financeRole);
        $directorRole = $this->role(DataScope::GLOBAL, 'SepDir');
        $this->user('dirmid@pr.test', $directorRole);

        $run = $this->payrollRun(500000, processedBy: $processor->id);

        $this->saveLadder([
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $financeRole->id, 'name' => 'Finance'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $directorRole->id, 'name' => 'Director'],
        ]);

        Sanctum::actingAs($processor);

        // PayrollRunWorkflow enforces this only on the rung that reaches it.
        // Without the controller repeating the check, the processor could sign
        // the first rung of their own run — which is the thing the control
        // exists to prevent.
        $this->approveVia($run)->assertStatus(422);

        // And the refusal consumed no rung.
        $this->assertDatabaseCount('hr_approval_actions', 0);
        $this->assertSame(1, $this->request($run)->current_step);
    }

    public function test_with_the_setting_off_the_processor_may_still_approve(): void
    {
        // Default is off, and it must stay off by default: a one-admin tenant
        // that could never pay anybody is the failure this setting avoids.
        $role = $this->role(DataScope::GLOBAL, 'NoSep');
        $processor = $this->user('nosep@pr.test', $role);
        $run = $this->payrollRun(500000, processedBy: $processor->id);

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($processor);
        $this->approveVia($run)->assertOk();

        $this->assertTrue($run->fresh()->isApproved());
    }

    /* ── 9. rejection sends back, and is NOT terminal ─────────────────── */

    public function test_rejection_sends_the_run_back_for_rework(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'RejPr');
        $approver = $this->user('rej@pr.test', $role);
        $run = $this->payrollRun();

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->rejectVia($run, 'overtime looks wrong')->assertOk();

        $fresh = $run->fresh();
        $this->assertSame(HrPayrollRun::STAGE_CALCULATE, $fresh->stage);
        $this->assertFalse($fresh->isApproved());
        $this->assertSame('overtime looks wrong', $fresh->approval_note);

        $this->assertSame(ApprovalState::REJECTED, $this->request($run)->state);
    }

    public function test_a_rejected_run_can_be_approved_on_a_fresh_round(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'RoundPr');
        $approver = $this->user('round@pr.test', $role);
        $run = $this->payrollRun();

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->rejectVia($run, 'send it back')->assertOk();

        // HR reworks and recalculates — the run returns to the approval stage.
        $run->update(['stage' => HrPayrollRun::STAGE_APPROVE]);

        // Unlike every other migrated process, payroll rejection is not
        // terminal. A second round has to open, or a sent-back run could never
        // be approved again.
        $this->approveVia($run, 'now correct')->assertOk();
        $this->assertTrue($run->fresh()->isApproved());

        $rounds = HrApprovalRequest::where('subject_type', HrPayrollRun::class)
            ->where('subject_id', $run->id)->count();
        $this->assertSame(2, $rounds, 'Each decision round keeps its own history.');
    }

    /* ── 10-11. blocking and the snapshot ─────────────────────────────── */

    public function test_an_unresolvable_rung_blocks_and_never_approves(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'EmptyPr');
        // Nobody holds the role, so the rung cannot resolve.
        $run = $this->payrollRun();

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        $hr = $this->user('hrblock@pr.test', null, 'admin');
        Sanctum::actingAs($hr);

        $this->approveVia($run)->assertStatus(409);

        $this->assertFalse($run->fresh()->isApproved());
        $this->assertSame(ApprovalState::BLOCKED, $this->request($run)->state);
    }

    public function test_editing_the_ladder_does_not_reroute_a_run_in_progress(): void
    {
        $directorRole = $this->role(DataScope::GLOBAL, 'SnapDir');
        $directorUser = $this->user('snapdir@pr.test', $directorRole);
        $financeRole = $this->role(DataScope::GLOBAL, 'SnapFin');
        $financeUser = $this->user('snapfin@pr.test', $financeRole);

        $run = $this->payrollRun();

        $this->saveLadder([
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $financeRole->id, 'name' => 'Finance'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $directorRole->id, 'name' => 'Director'],
        ]);

        Sanctum::actingAs($financeUser);
        $this->approveVia($run)->assertOk();

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $financeRole->id, 'name' => 'Finance only']]);

        $request = $this->request($run);
        $this->assertCount(2, $request->steps_snapshot);
        $this->assertSame(2, $request->current_step);

        Sanctum::actingAs($directorUser);
        $this->approveVia($run)->assertOk();
        $this->assertTrue($run->fresh()->isApproved());
    }

    /* ── 12. duplicate decisions and locking ──────────────────────────── */

    public function test_a_second_approval_cannot_sign_twice(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'DupPr');
        $approver = $this->user('dup@pr.test', $role);
        $run = $this->payrollRun();

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($run)->assertOk();
        $firstApprovedAt = $run->fresh()->approved_at;

        // "This payroll run is already approved."
        $this->approveVia($run)->assertStatus(422);

        $this->assertEquals($firstApprovedAt, $run->fresh()->approved_at,
            'A refused second signature must not restamp the approval.');
    }

    public function test_an_approved_run_stays_locked_against_adjustment(): void
    {
        $role = $this->role(DataScope::GLOBAL, 'LockPr');
        $approver = $this->user('lock@pr.test', $role);
        $run = $this->payrollRun();
        $record = $this->recordFor($run);

        $this->saveLadder([['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $role->id]]);

        Sanctum::actingAs($approver);
        $this->approveVia($run)->assertOk();

        // An adjustment after sign-off changes a number somebody put their name
        // to. assertNotLocked() refuses it, and the ladder does not weaken that.
        $this->postJson("/api/hr/payroll/records/{$record->id}/adjustments", [
            'type' => 'Deduction', 'amount' => 100, 'reason' => 'late change',
        ])->assertStatus(422);
    }

    /* ── 13. bypass ───────────────────────────────────────────────────── */

    public function test_posted_step_fields_cannot_skip_the_ladder(): void
    {
        $directorRole = $this->role(DataScope::GLOBAL, 'BypDir');
        $directorUser = $this->user('byp@pr.test', $directorRole);
        $financeRole = $this->role(DataScope::GLOBAL, 'BypFin');
        $this->user('bypfin@pr.test', $financeRole);

        $run = $this->payrollRun();

        $this->saveLadder([
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $financeRole->id, 'name' => 'Finance'],
            ['approver_type' => ApproverType::STAFF_ROLE, 'approver_ref' => $directorRole->id, 'name' => 'Director'],
        ]);

        Sanctum::actingAs($directorUser);
        $this->postJson("/api/hr/payroll/runs/{$run->id}/approve", [
            'step' => 2, 'current_step' => 2, 'final' => true, 'stage' => 'Disburse',
        ])->assertStatus(403);

        $this->assertFalse($run->fresh()->isApproved());
    }

    /* ── attendance app coexistence ───────────────────────────────────── */

    public function test_payroll_still_reads_attendance_through_the_existing_provider(): void
    {
        // Payroll consumes attendance through the provider contract, which this
        // phase does not touch. Asserted structurally: the binding and the
        // method payroll depends on are both intact.
        $provider = app(\App\Contracts\Hr\AttendanceProvider::class);

        // Bound to the CRM provider, which reads hr_attendance directly. The
        // attendance app writes those rows through its own untouched path.
        $this->assertInstanceOf(\App\Services\Hr\Attendance\CrmAttendanceProvider::class, $provider);
        $this->assertTrue(method_exists($provider, 'forPeriod'));
        $this->assertIsArray($provider->forPeriod($this->employee()->id, $this->tenant->id, '2026-07'));
    }

    /* ── settings surface ─────────────────────────────────────────────── */

    public function test_payroll_run_is_configurable_alongside_the_others(): void
    {
        $admin = $this->user('admin2@pr.test', null, 'admin');

        Sanctum::actingAs($admin);
        $keys = array_column($this->getJson('/api/hr/approval-workflows')->assertOk()->json('data'), 'process');

        $this->assertContains(ApprovalProcess::PAYROLL_RUN, $keys);
        $this->assertNotContains('exit_clearance', $keys);

        foreach ([
            ApprovalProcess::LEAVE, ApprovalProcess::LOAN, ApprovalProcess::VARIABLE_EARNING,
            ApprovalProcess::INVESTMENT_DECLARATION, ApprovalProcess::REIMBURSEMENT,
            ApprovalProcess::EXIT_REQUEST, ApprovalProcess::PROBATION_CONFIRMATION,
        ] as $p) {
            $this->assertContains($p, $keys, 'Earlier processes must stay configurable.');
        }
    }
}
