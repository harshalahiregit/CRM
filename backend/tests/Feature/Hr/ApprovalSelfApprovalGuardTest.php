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
use App\Services\Settings\SettingsService;
use App\Support\Hr\Approval\ApprovalProcess;
use App\Support\Hr\Approval\ApproverType;
use App\Support\Hr\DataScope;
use App\Support\Hr\HrSetting;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nobody decides their own request — once the workspace asks for it.
 *
 * The reachable failure this exists for is not exotic. A workspace that has
 * configured no ladder falls back to a single HR-queue step, and an HR
 * executive raising their own expense claim is a member of that queue. The
 * same holds when they hold the approving staff role, when they are named on
 * the step by id, and when they are somehow their own reporting manager.
 *
 * TWO THINGS ARE BEING HELD APART THROUGHOUT:
 *
 *   the guard removes permission and never grants it. With the setting off,
 *   every test here must behave exactly as it did before the guard existed;
 *
 *   it is a FOURTH gate, not a replacement. A scope failure has to stay a
 *   scope failure and a capability failure a capability failure, or the guard
 *   has quietly become the only thing being tested.
 */
class ApprovalSelfApprovalGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'sag', 'status' => 'active']);
        $this->other  = Tenant::create(['name' => 'Two', 'slug' => 'sag2', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function role(string $scope, string $key, ?Tenant $t = null): StaffRole
    {
        return StaffRole::create([
            'tenant_id' => ($t ?: $this->tenant)->id,
            'name' => 'R'.$key, 'slug' => 'r_'.strtolower($key).substr(uniqid(), -4),
            'permissions' => ['hr_employees' => [StaffPermission::VIEW_GLOBAL]],
            'scope' => $scope, 'is_system' => false,
        ]);
    }

    private function user(?StaffRole $role = null, string $userRole = 'staff', ?Tenant $t = null): User
    {
        return User::create([
            'tenant_id' => ($t ?: $this->tenant)->id, 'name' => 'U'.substr(uniqid(), -4),
            'email' => uniqid().'@sag.test', 'password' => Hash::make('Password123!'),
            'role' => $userRole, 'status' => 'active', 'staff_role_id' => $role?->id,
        ]);
    }

    private function employee(array $attrs = [], ?Tenant $t = null): HrEmployee
    {
        return HrEmployee::create(array_merge([
            'tenant_id' => ($t ?: $this->tenant)->id, 'name' => 'E'.substr(uniqid(), -5),
            'employee_code' => 'E'.substr(uniqid(), -6),
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2024-01-01', 'status' => 'Active',
        ], $attrs), );
    }

    /** A submitted leave application, with the balance its approval will deduct. */
    private function leave(HrEmployee $employee, ?User $createdBy = null, ?Tenant $t = null): HrLeaveApplication
    {
        $t = $t ?: $this->tenant;

        DB::table('hr_employee_leave_balances')->insert([
            'tenant_id' => $t->id, 'employee_id' => $employee->id,
            'leave_type_id' => 1, 'allocated' => 20, 'opening_balance' => 20,
            'used' => 0, 'adjusted' => 0, 'carried_forward' => 0,
            'available_balance' => 20, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return HrLeaveApplication::create([
            'tenant_id' => $t->id, 'employee_id' => $employee->id,
            'leave_type_id' => 1, 'from_date' => '2026-06-01', 'to_date' => '2026-06-02',
            'days' => 2, 'status' => HrLeaveApplication::SUBMITTED,
            'created_by' => $createdBy?->id,
        ]);
    }

    private function engine(): ApprovalEngine
    {
        return app(ApprovalEngine::class);
    }

    private function requireDistinct(bool $on, ?Tenant $t = null): void
    {
        app(SettingsService::class)->setGroup(
            ($t ?: $this->tenant)->id, HrSetting::GROUP, ['require_distinct_approver' => $on]
        );
    }

    private function saveLadder(array $steps, ?Tenant $t = null): void
    {
        app(WorkflowConfigService::class)->save(
            ($t ?: $this->tenant)->id, ApprovalProcess::LEAVE,
            ['name' => 'Leave approval', 'is_active' => true, 'steps' => $steps]
        );
    }

    /** The engine's own answer, which is what every controller ends up asking. */
    private function mayAct(HrLeaveApplication $leave, User $actor): bool
    {
        return $this->engine()->mayAct($this->requestFor($leave), $actor);
    }

    private function requestFor(HrLeaveApplication $leave): HrApprovalRequest
    {
        return $this->engine()->requestFor(
            $leave, ApprovalProcess::LEAVE, (int) $leave->tenant_id, $leave->employee_id
        );
    }

    /* ── 1 & 11. the default preserves today's behaviour ──────────────── */

    public function test_the_setting_is_off_for_a_workspace_that_has_never_set_it(): void
    {
        $this->assertFalse((bool) app(SettingsService::class)->get(
            $this->tenant->id, HrSetting::GROUP, 'require_distinct_approver'
        ));
    }

    public function test_with_the_setting_off_the_requester_still_acts_exactly_as_before(): void
    {
        $hrUser = $this->user(null, 'admin');
        $employee = $this->employee(['user_id' => $hrUser->id]);
        $leave = $this->leave($employee, $hrUser);

        // Untouched behaviour: this is what the engine did before the guard
        // existed, and the default must not move it.
        $this->assertTrue($this->mayAct($leave, $hrUser));

        Sanctum::actingAs($hrUser);
        $this->patchJson("/api/hr/leave/approvals/{$leave->id}/approve", ['remarks' => 'fine'])->assertOk();
        $this->assertSame(HrLeaveApplication::APPROVED, $leave->fresh()->status);
    }

    /* ── 2 & 6. enabled: the HR-queue fallback ────────────────────────── */

    public function test_with_the_setting_on_the_requester_cannot_act(): void
    {
        $this->requireDistinct(true);

        $hrUser = $this->user(null, 'admin');
        $employee = $this->employee(['user_id' => $hrUser->id]);
        $leave = $this->leave($employee, $hrUser);

        // No ladder configured, so this is the HR-queue fallback rung — and
        // an administrator is a member of it. Being trusted with everybody
        // else's records is not a reason to be trusted with your own.
        $this->assertFalse($this->mayAct($leave, $hrUser));

        Sanctum::actingAs($hrUser);
        $this->patchJson("/api/hr/leave/approvals/{$leave->id}/approve", ['remarks' => 'fine'])
            ->assertStatus(403);

        $this->assertSame(HrLeaveApplication::SUBMITTED, $leave->fresh()->status);
    }

    public function test_the_hr_queue_rung_is_not_a_way_round_it(): void
    {
        $this->requireDistinct(true);

        $requester = $this->user(null, 'admin');
        $employee = $this->employee(['user_id' => $requester->id]);
        $leave = $this->leave($employee, $requester);

        // No ladder is saved, because legacy_hr_queue is deliberately not a
        // configurable type — it exists only as the fallback rung a workspace
        // gets when it has configured nothing. That fallback is the whole
        // reason this guard matters, so the step is asserted rather than
        // assumed.
        $step = $this->requestFor($leave)->currentStepDefinition();
        $this->assertSame(ApproverType::LEGACY_HR_QUEUE, $step['approver_type']);

        $this->assertTrue($requester->canManageHrQueue(), 'fixture: should be a queue member');
        $this->assertFalse($this->mayAct($leave, $requester));

        // And the rung still works for anybody else in the queue.
        $this->assertTrue($this->mayAct($leave, $this->user(null, 'admin')));
    }

    /* ── 3. specific_user ─────────────────────────────────────────────── */

    public function test_being_named_on_the_step_by_id_is_not_a_way_round_it(): void
    {
        $this->requireDistinct(true);

        $requester = $this->user($this->role(DataScope::GLOBAL, 'A'), 'admin');
        $employee = $this->employee(['user_id' => $requester->id]);
        $leave = $this->leave($employee, $requester);

        $this->saveLadder([[
            'name' => 'Named', 'approver_type' => ApproverType::SPECIFIC_USER,
            'approver_ref' => $requester->id,
        ]]);

        // Explicitly configured as the approver, and still refused. A
        // misconfigured ladder must not become the exception.
        $this->assertFalse($this->mayAct($leave, $requester));
    }

    /* ── 4. staff_role ────────────────────────────────────────────────── */

    public function test_holding_the_approving_role_is_not_a_way_round_it(): void
    {
        $this->requireDistinct(true);

        $approverRole = $this->role(DataScope::GLOBAL, 'Approver');
        $requester = $this->user($approverRole, 'admin');
        $colleague = $this->user($approverRole, 'admin');

        $employee = $this->employee(['user_id' => $requester->id]);
        $leave = $this->leave($employee, $requester);

        $this->saveLadder([[
            'name' => 'Role', 'approver_type' => ApproverType::STAFF_ROLE,
            'approver_ref' => $approverRole->id,
        ]]);

        $this->assertFalse($this->mayAct($leave, $requester));

        // 7. Somebody else holding the same role is unaffected.
        $this->assertTrue($this->mayAct($leave, $colleague));
    }

    /* ── 5. reporting_manager ─────────────────────────────────────────── */

    public function test_raising_a_request_for_someone_who_reports_to_you_blocks_you(): void
    {
        $this->requireDistinct(true);

        $manager = $this->user($this->role(DataScope::GLOBAL, 'Mgr'), 'admin');
        $managerEmployee = $this->employee(['user_id' => $manager->id]);

        // The manager raises the request on their report's behalf, so they are
        // the requester by created_by while resolving as the approver by
        // reporting line. This is the realistic shape — an employee being
        // their own manager is a loop the resolver already refuses.
        $report = $this->employee(['reporting_manager_id' => $managerEmployee->id]);
        $leave = $this->leave($report, $manager);

        $this->saveLadder([[
            'name' => 'Manager', 'approver_type' => ApproverType::REPORTING_MANAGER,
            'levels_up' => 1,
        ]]);

        $this->assertFalse($this->mayAct($leave, $manager));
    }

    public function test_the_manager_still_approves_what_the_employee_raised(): void
    {
        $this->requireDistinct(true);

        $manager = $this->user($this->role(DataScope::GLOBAL, 'Mgr'), 'admin');
        $managerEmployee = $this->employee(['user_id' => $manager->id]);

        $reportUser = $this->user();
        $report = $this->employee([
            'user_id' => $reportUser->id, 'reporting_manager_id' => $managerEmployee->id,
        ]);
        $leave = $this->leave($report, $reportUser);

        $this->saveLadder([[
            'name' => 'Manager', 'approver_type' => ApproverType::REPORTING_MANAGER,
            'levels_up' => 1,
        ]]);

        // The ordinary case, and the one that must not break: somebody else's
        // request, decided by their manager.
        $this->assertTrue($this->mayAct($leave, $manager));
        $this->assertFalse($this->mayAct($leave, $reportUser));
    }

    /* ── the employee identity, where there is no created_by ──────────── */

    public function test_the_employee_the_request_is_about_is_also_the_requester(): void
    {
        $this->requireDistinct(true);

        $employeeUser = $this->user($this->role(DataScope::GLOBAL, 'Self'), 'admin');
        $employee = $this->employee(['user_id' => $employeeUser->id]);

        // created_by deliberately null — the shape reimbursements have, where
        // the table carries employee_id and nothing else.
        $leave = $this->leave($employee, null);

        $this->assertNull($leave->created_by);
        $this->assertFalse($this->mayAct($leave, $employeeUser));
    }

    /* ── 7. somebody else can still act ───────────────────────────────── */

    public function test_another_eligible_approver_is_unaffected(): void
    {
        $this->requireDistinct(true);

        $requester = $this->user(null, 'admin');
        $employee = $this->employee(['user_id' => $requester->id]);
        $leave = $this->leave($employee, $requester);

        $otherHr = $this->user(null, 'admin');

        $this->assertFalse($this->mayAct($leave, $requester));
        $this->assertTrue($this->mayAct($leave, $otherHr));

        // And the request goes through normally in their hands.
        Sanctum::actingAs($otherHr);
        $this->patchJson("/api/hr/leave/approvals/{$leave->id}/approve", ['remarks' => 'ok'])->assertOk();
        $this->assertSame(HrLeaveApplication::APPROVED, $leave->fresh()->status);
    }

    public function test_no_fallback_approver_is_invented(): void
    {
        $this->requireDistinct(true);

        $requester = $this->user($this->role(DataScope::GLOBAL, 'Only'), 'admin');
        $employee = $this->employee(['user_id' => $requester->id]);
        $leave = $this->leave($employee, $requester);

        $soleRole = $this->role(DataScope::GLOBAL, 'Sole');
        $requester->update(['staff_role_id' => $soleRole->id]);

        $this->saveLadder([[
            'name' => 'Sole', 'approver_type' => ApproverType::STAFF_ROLE,
            'approver_ref' => $soleRole->id,
        ]]);

        // The only configured approver is the requester. The request simply
        // stays open — the guard does not reroute it, promote anybody, or
        // quietly let it through.
        $this->assertFalse($this->mayAct($leave, $requester->fresh()));
        $this->assertTrue($this->requestFor($leave)->isOpen());
        $this->assertSame(HrLeaveApplication::SUBMITTED, $leave->fresh()->status);
    }

    /* ── 8 & 9. the other gates keep their own meaning ────────────────── */

    public function test_a_scope_failure_is_still_a_scope_failure(): void
    {
        $this->requireDistinct(true);

        // Department scope, and an employee in another department. Nothing to
        // do with who raised it — the actor is not the requester at all.
        $limited = $this->user($this->role(DataScope::DEPARTMENT, 'Dept'));
        $limitedEmployee = $this->employee(['user_id' => $limited->id, 'department' => 'Finance']);

        $outsiderUser = $this->user();
        $outsider = $this->employee(['user_id' => $outsiderUser->id, 'department' => 'Ops']);
        $leave = $this->leave($outsider, $outsiderUser);

        Sanctum::actingAs($limited);
        $response = $this->patchJson("/api/hr/leave/approvals/{$leave->id}/approve", ['remarks' => 'x']);

        // ScopeResolver aborts 404, not 403 — the guard must not have turned a
        // scope refusal into a requester refusal.
        $this->assertSame(404, $response->status());
    }

    public function test_a_capability_failure_is_still_a_capability_failure(): void
    {
        $this->requireDistinct(true);

        // No HR capability at all, and not the requester either.
        $stranger = $this->user();
        $employeeUser = $this->user();
        $employee = $this->employee(['user_id' => $employeeUser->id]);
        $leave = $this->leave($employee, $employeeUser);

        Sanctum::actingAs($stranger);
        $response = $this->patchJson("/api/hr/leave/approvals/{$leave->id}/approve", ['remarks' => 'x']);

        $this->assertContains($response->status(), [403, 404]);
        $this->assertSame(HrLeaveApplication::SUBMITTED, $leave->fresh()->status);
    }

    /* ── 10. tenant isolation ─────────────────────────────────────────── */

    public function test_one_workspaces_setting_does_not_reach_another(): void
    {
        // On here, off there.
        $this->requireDistinct(true, $this->tenant);
        $this->requireDistinct(false, $this->other);

        $hereUser = $this->user(null, 'admin', $this->tenant);
        $hereEmployee = $this->employee(['user_id' => $hereUser->id], $this->tenant);
        $hereLeave = $this->leave($hereEmployee, $hereUser, $this->tenant);

        $thereUser = $this->user(null, 'admin', $this->other);
        $thereEmployee = $this->employee(['user_id' => $thereUser->id], $this->other);
        $thereLeave = $this->leave($thereEmployee, $thereUser, $this->other);

        $this->assertFalse($this->mayAct($hereLeave, $hereUser), 'the strict workspace leaked');
        $this->assertTrue($this->mayAct($thereLeave, $thereUser), 'the permissive workspace was made strict');
    }

    /**
     * The employee identity does not cross a workspace boundary.
     *
     * employee_id is read off the approval request and looked up in a table
     * every workspace shares. This plants the malformed row that makes the
     * tenant filter matter — a request in one workspace pointing at an
     * employee in another — and asserts the lookup declines to follow it
     * rather than importing a stranger's identity into the guard.
     */
    public function test_the_employee_lookup_stays_inside_the_workspace(): void
    {
        $this->requireDistinct(true, $this->tenant);

        $strangerUser = $this->user(null, 'staff', $this->other);
        $strangerEmployee = $this->employee(['user_id' => $strangerUser->id], $this->other);

        $localUser = $this->user(null, 'admin', $this->tenant);
        $localEmployee = $this->employee(['user_id' => $localUser->id], $this->tenant);
        $leave = $this->leave($localEmployee, $localUser, $this->tenant);

        // Point this workspace's request at the other workspace's employee.
        $request = $this->requestFor($leave);
        $request->forceFill(['employee_id' => $strangerEmployee->id])->saveQuietly();

        $resolved = app(\App\Services\Hr\Approval\RequesterResolver::class)
            ->userIdsFor($request->fresh());

        // The stranger's account must not have been pulled in.
        $this->assertNotContains($strangerUser->id, $resolved);
        // The local submitter is still recognised.
        $this->assertContains($localUser->id, $resolved);
    }

    /* ── 12. every migrated process goes through the same gate ────────── */

    /**
     * The guard is in the engine, not copied into nine controllers.
     *
     * Asserted structurally rather than by building nine fixtures: every
     * migrated process reaches a decision through ApprovalEngine::mayAct(),
     * so a guard there covers all of them, and no process may carry its own
     * copy of the rule that could drift from it.
     */
    public function test_the_guard_lives_only_in_the_engine(): void
    {
        $engine = file_get_contents(base_path('app/Services/Hr/Approval/ApprovalEngine.php'));

        $this->assertStringContainsString('isOwnRequest', $engine);
        $this->assertStringContainsString('require_distinct_approver', $engine);

        // Nowhere else. A second copy is how two answers to one question
        // start disagreeing.
        $copies = [];
        foreach (glob(base_path('app/Services/Hr/*.php'))
            + glob(base_path('app/Services/Hr/**/*.php'))
            + glob(base_path('app/Http/Controllers/Api/Hr/*.php')) as $file) {
            if (basename($file) === 'ApprovalEngine.php') {
                continue;
            }
            // The quoted key exactly. A bare substring also matches
            // `advance_require_distinct_approvers`, which is the advance
            // ladder's own older setting and a different rule entirely.
            if (str_contains((string) file_get_contents($file), "'require_distinct_approver'")) {
                $copies[] = basename($file);
            }
        }

        $this->assertSame([], $copies, 'the rule has been copied outside the engine: '.implode(', ', $copies));
    }

    public function test_every_migrated_process_decides_through_the_engine(): void
    {
        // If a process reached a decision without the engine, the central
        // guard would not cover it. These are the controllers that own each
        // migrated process's decision.
        $owners = [
            ApprovalProcess::LEAVE                  => 'LeaveApprovalController',
            ApprovalProcess::LOAN                   => 'LoanController',
            ApprovalProcess::VARIABLE_EARNING       => 'VariableEarningController',
            ApprovalProcess::INVESTMENT_DECLARATION => 'InvestmentDeclarationController',
            ApprovalProcess::REIMBURSEMENT          => 'ReimbursementController',
            ApprovalProcess::EXIT_REQUEST           => 'ExitApprovalController',
            ApprovalProcess::PROBATION_CONFIRMATION => 'ProbationConfirmationController',
            ApprovalProcess::PAYROLL_RUN            => 'PayrollWorkflowController',
        ];

        foreach ($owners as $process => $controller) {
            $path = base_path("app/Http/Controllers/Api/Hr/{$controller}.php");
            $this->assertFileExists($path, "{$process}: {$controller} has moved");

            $src = (string) file_get_contents($path);
            $this->assertStringContainsString('ApprovalEngine', $src,
                "{$process} does not decide through the engine, so the guard would not cover it");
        }

        // Advances are registered for their snapshot and history only: their
        // rungs are resolved by AdvanceTierService and never reach mayAct().
        // They carry their own advance_require_distinct_approvers and are
        // deliberately untouched here.
        $this->assertFalse(
            str_contains((string) file_get_contents(base_path('app/Http/Controllers/Api/Hr/AdvanceController.php')), 'ApprovalEngine'),
            'Advances now route through the engine — the guard interaction needs deciding'
        );
    }
}
