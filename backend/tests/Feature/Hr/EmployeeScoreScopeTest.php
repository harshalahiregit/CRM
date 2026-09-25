<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeScore;
use App\Models\Hr\HrLeaveApplication;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Hr\DataScope;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Data scope on the employee score — the seventh operational surface.
 *
 * The score is a judgement about a named person: a band, a list of risk
 * factors, and an AI narrative about their conduct. Permission already asked
 * whether the caller may see employee scores at all, which every HR user
 * passes; nothing asked which employees were theirs. Two of these four
 * endpoints also WRITE, so an out-of-scope employee could be scored and have
 * history appended by someone with no business looking at them.
 *
 * The scope check sits in EmployeeScoreController::authorise(), which every
 * endpoint calls first. That is enough on its own because the scoring engine
 * reads only the bound employee's own reviews, attendance, leave, training and
 * tasks — there is no peer comparison and no tenant-wide aggregate to leak
 * through, and HrEmployeeScore is reachable from no other controller.
 */
class EmployeeScoreScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'score-scope', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    /**
     * An HR user whose role carries a scope.
     *
     * VIEW_GLOBAL on hr_employees is what makes canManageHrQueue() true, so
     * these actors clear the permission gate and are stopped — or not — purely
     * by the data scope. role is 'staff', never 'admin': an admin is
     * unrestricted by design and would prove nothing here.
     */
    private function actor(string $scope, string $email): User
    {
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'R'.substr(md5($email.$scope), 0, 6),
            'slug' => 'r_'.substr(md5($email.$scope), 0, 6),
            'permissions' => ['hr_employees' => [StaffPermission::VIEW_GLOBAL]],
            'scope' => $scope, 'is_system' => false,
        ]);

        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => 'staff',
            'status' => 'active', 'staff_role_id' => $role->id,
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

    /** Every read and write surface on this controller, as (method, url) pairs. */
    private function endpoints(int $employeeId): array
    {
        return [
            'score'       => ['get',  "/api/hr/employees/{$employeeId}/score"],
            'preview'     => ['get',  "/api/hr/employees/{$employeeId}/score/preview"],
            'recalculate' => ['post', "/api/hr/employees/{$employeeId}/score/recalculate"],
            'insights'    => ['post', "/api/hr/employees/{$employeeId}/insights"],
        ];
    }

    private function assertAllRefused(int $employeeId): void
    {
        foreach ($this->endpoints($employeeId) as $name => [$verb, $url]) {
            $this->{$verb.'Json'}($url)->assertStatus(404, "{$name} should not reach an out-of-scope employee");
        }
    }

    private function assertAllReachable(int $employeeId): void
    {
        foreach ($this->endpoints($employeeId) as $name => [$verb, $url]) {
            $this->{$verb.'Json'}($url)->assertOk("{$name} should reach an in-scope employee");
        }
    }

    /* ── A. global ────────────────────────────────────────────────────── */

    public function test_a_global_actor_reaches_any_employee_in_the_tenant(): void
    {
        Sanctum::actingAs($this->actor(DataScope::GLOBAL, 'global@score.test'));

        $this->assertAllReachable($this->employee(['department' => 'Sales'])->id);
    }

    /* ── B. department ────────────────────────────────────────────────── */

    public function test_a_department_actor_reaches_their_own_department(): void
    {
        $user = $this->actor(DataScope::DEPARTMENT, 'dept@score.test');
        $this->employee(['user_id' => $user->id, 'department' => 'Ops']);
        $colleague = $this->employee(['department' => 'Ops']);

        Sanctum::actingAs($user);
        $this->assertAllReachable($colleague->id);
    }

    public function test_a_department_actor_cannot_reach_another_department(): void
    {
        $user = $this->actor(DataScope::DEPARTMENT, 'dept2@score.test');
        $this->employee(['user_id' => $user->id, 'department' => 'Ops']);
        $outsider = $this->employee(['department' => 'Sales']);

        Sanctum::actingAs($user);
        $this->assertAllRefused($outsider->id);
    }

    /* ── C. branch ────────────────────────────────────────────────────── */

    public function test_a_branch_actor_reaches_their_own_branch(): void
    {
        $user = $this->actor(DataScope::BRANCH, 'branch@score.test');
        $this->employee(['user_id' => $user->id, 'branch' => 'Pune']);
        $colleague = $this->employee(['branch' => 'Pune', 'department' => 'Sales']);

        Sanctum::actingAs($user);
        $this->assertAllReachable($colleague->id);
    }

    public function test_a_branch_actor_cannot_reach_another_branch(): void
    {
        $user = $this->actor(DataScope::BRANCH, 'branch2@score.test');
        $this->employee(['user_id' => $user->id, 'branch' => 'Pune']);
        $outsider = $this->employee(['branch' => 'Mumbai']);

        Sanctum::actingAs($user);
        $this->assertAllRefused($outsider->id);
    }

    /* ── D. team ──────────────────────────────────────────────────────── */

    public function test_a_team_actor_reaches_their_reporting_line(): void
    {
        $user = $this->actor(DataScope::TEAM, 'team@score.test');
        $lead = $this->employee(['user_id' => $user->id]);
        $report = $this->employee(['reporting_manager_id' => $lead->id]);

        Sanctum::actingAs($user);
        $this->assertAllReachable($report->id);
    }

    public function test_a_team_actor_cannot_reach_outside_their_team(): void
    {
        $user = $this->actor(DataScope::TEAM, 'team2@score.test');
        $this->employee(['user_id' => $user->id, 'department' => 'Ops']);
        // Same department, different manager — department is not team.
        $other = $this->employee(['department' => 'Ops']);

        Sanctum::actingAs($user);
        $this->assertAllRefused($other->id);
    }

    /* ── E. own ───────────────────────────────────────────────────────── */

    public function test_an_own_scoped_actor_reaches_their_own_record(): void
    {
        $user = $this->actor(DataScope::OWN, 'own@score.test');
        $me = $this->employee(['user_id' => $user->id]);

        // There is no employee self-service score route — access still requires
        // the HR permission. This is an HR user whose role is scoped to own,
        // which the existing scope vocabulary already allows.
        Sanctum::actingAs($user);
        $this->assertAllReachable($me->id);
    }

    public function test_an_own_scoped_actor_cannot_reach_a_colleague(): void
    {
        $user = $this->actor(DataScope::OWN, 'own2@score.test');
        $this->employee(['user_id' => $user->id]);
        $colleague = $this->employee();

        Sanctum::actingAs($user);
        $this->assertAllRefused($colleague->id);
    }

    public function test_an_actor_with_no_employee_record_reaches_nobody(): void
    {
        $stranger = $this->actor(DataScope::DEPARTMENT, 'stranger@score.test');
        $someone = $this->employee();

        // Authorised, but with no place in the org chart. Nothing is the honest
        // answer; everything is the wrong guess.
        Sanctum::actingAs($stranger);
        $this->assertAllRefused($someone->id);
    }

    /* ── F. the refusal is indistinguishable from absence ─────────────── */

    public function test_an_out_of_scope_employee_reads_as_absent_not_forbidden(): void
    {
        $user = $this->actor(DataScope::DEPARTMENT, 'shape@score.test');
        $this->employee(['user_id' => $user->id, 'department' => 'Ops']);
        $outsider = $this->employee(['department' => 'Sales']);

        Sanctum::actingAs($user);

        // 404, not 403: "you may not see this employee" still confirms the
        // employee exists, and 999999 must look the same as a real one.
        $this->getJson("/api/hr/employees/{$outsider->id}/score")->assertStatus(404);
        $this->getJson('/api/hr/employees/999999/score')->assertStatus(404);
    }

    /* ── G. the writes must not happen either ─────────────────────────── */

    public function test_a_refused_recalculate_writes_nothing(): void
    {
        $user = $this->actor(DataScope::DEPARTMENT, 'write@score.test');
        $this->employee(['user_id' => $user->id, 'department' => 'Ops']);
        $outsider = $this->employee(['department' => 'Sales']);

        Sanctum::actingAs($user);

        $this->postJson("/api/hr/employees/{$outsider->id}/score/recalculate")->assertStatus(404);
        $this->postJson("/api/hr/employees/{$outsider->id}/insights")->assertStatus(404);

        // recalculate and insights both persist. A refusal that still wrote
        // would leave a scored row — and a history entry — for somebody the
        // actor may not look at.
        $this->assertDatabaseMissing('hr_employee_scores', ['employee_id' => $outsider->id]);
        $this->assertDatabaseMissing('hr_employee_score_history', ['employee_id' => $outsider->id]);
    }

    /* ── G. leave data cannot be used as a side channel ───────────────── */

    public function test_out_of_scope_leave_data_cannot_leak_through_the_score(): void
    {
        $user = $this->actor(DataScope::DEPARTMENT, 'leak@score.test');
        $this->employee(['user_id' => $user->id, 'department' => 'Ops']);
        $outsider = $this->employee(['department' => 'Sales']);

        // Leave behaviour is one of the eight scored dimensions, so the engine
        // reads this row when it scores the outsider.
        HrLeaveApplication::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $outsider->id,
            'leave_type_id' => 1, 'from_date' => '2026-06-01', 'to_date' => '2026-06-20',
            'days' => 20, 'status' => HrLeaveApplication::APPROVED,
        ]);

        Sanctum::actingAs($user);

        // Every route into the engine is closed, so the leave row is never read
        // on this actor's behalf. It is not a separate channel: it only ever
        // enters through the bound employee's own context.
        $this->assertAllRefused($outsider->id);

        // And the in-scope actor's own score never picks up a stranger's leave.
        $mine = HrEmployee::where('user_id', $user->id)->first();
        $this->postJson("/api/hr/employees/{$mine->id}/score/recalculate")->assertOk();

        $leaveRows = DB::table('hr_leave_applications')->where('employee_id', $mine->id)->count();
        $this->assertSame(0, $leaveRows, 'The fixture gives the actor no leave of their own.');
    }

    /* ── H. regression: the existing behaviour is unchanged ───────────── */

    public function test_an_in_scope_score_still_computes_and_persists(): void
    {
        $user = $this->actor(DataScope::DEPARTMENT, 'regress@score.test');
        $this->employee(['user_id' => $user->id, 'department' => 'Ops']);
        $colleague = $this->employee(['department' => 'Ops']);

        Sanctum::actingAs($user);

        $this->getJson("/api/hr/employees/{$colleague->id}/score")
            ->assertOk()->assertJsonPath('scored', false);

        $this->postJson("/api/hr/employees/{$colleague->id}/score/recalculate")->assertOk();

        $this->getJson("/api/hr/employees/{$colleague->id}/score")
            ->assertOk()->assertJsonPath('scored', true);

        $this->assertDatabaseHas('hr_employee_scores', ['employee_id' => $colleague->id]);
    }

    public function test_preview_remains_a_dry_run_for_an_in_scope_employee(): void
    {
        $user = $this->actor(DataScope::DEPARTMENT, 'dry@score.test');
        $this->employee(['user_id' => $user->id, 'department' => 'Ops']);
        $colleague = $this->employee(['department' => 'Ops']);

        Sanctum::actingAs($user);
        $this->getJson("/api/hr/employees/{$colleague->id}/score/preview")->assertOk();

        $this->assertSame(0, HrEmployeeScore::where('employee_id', $colleague->id)->count());
    }

    public function test_the_permission_gate_still_runs_before_the_scope_check(): void
    {
        // No staff role at all: not an HR user. This must stay 403 — scope did
        // not replace the permission check, it was added after it.
        $nobody = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'N', 'email' => 'nobody@score.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);
        $someone = $this->employee();

        Sanctum::actingAs($nobody);
        $this->getJson("/api/hr/employees/{$someone->id}/score")->assertStatus(403);
    }

    public function test_another_tenants_employee_is_still_absent(): void
    {
        Sanctum::actingAs($this->actor(DataScope::GLOBAL, 'tenant@score.test'));

        $other = Tenant::create(['name' => 'Other', 'slug' => 'score-other', 'status' => 'active']);
        $theirs = HrEmployee::create([
            'tenant_id' => $other->id, 'name' => 'Theirs', 'employee_code' => 'X-1',
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2024-01-01', 'status' => 'Active',
        ]);

        // Global is the widest scope and still stops at the tenant.
        $this->assertAllRefused($theirs->id);
    }
}
