<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrExitRequest;
use App\Models\Hr\HrGrade;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\ExitRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * How long somebody's notice is, and which level decided it.
 *
 * FOUR LEVELS, NARROWEST FIRST. Three of them already existed and are
 * unchanged; only the employee level is new:
 *
 *   request   a number typed onto this exit — a negotiated separation
 *   employee  the person's own standing notice period            ← NEW
 *   policy    the exit policy matched to their grade / designation /
 *             department. THIS is where grade-level notice is configured;
 *             it was never missing, and hr_grades deliberately gains no
 *             notice column that would disagree with it
 *   type      the exit type's default
 *
 * NULL IS NOT ZERO at the employee level, and most of this file exists to hold
 * that apart. Null inherits. Zero means this person serves no notice, which is
 * a real arrangement for a contractor or a negotiated exit — and a resolver
 * that tested truthiness would silently turn it back into "inherit" and give
 * them their grade's two months.
 */
class NoticePeriodResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;
    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Tenant::create(['name' => 'Alpha', 'slug' => 'np-a', 'status' => 'active']);
        $this->b = Tenant::create(['name' => 'Beta', 'slug' => 'np-b', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function hr(?Tenant $t = null): User
    {
        return User::create([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => 'HR',
            'email' => uniqid().'@np.test', 'password' => Hash::make('Password123!'),
            'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function outsider(): User
    {
        return User::create([
            'tenant_id' => $this->a->id, 'name' => 'Nobody',
            'email' => uniqid().'@np.test', 'password' => Hash::make('Password123!'),
            'role' => 'staff', 'status' => 'active',
        ]);
    }

    private function grade(string $name = 'Manager', ?Tenant $t = null, bool $active = true): HrGrade
    {
        return HrGrade::create([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => $name,
            'code' => strtoupper(substr($name, 0, 3)).substr(uniqid(), -3),
            'level' => 5, 'is_active' => $active,
        ]);
    }

    private function employee(array $attrs = [], ?Tenant $t = null): HrEmployee
    {
        return HrEmployee::create(array_merge([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => 'E'.substr(uniqid(), -5),
            'employee_code' => 'E'.substr(uniqid(), -6),
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2024-01-01', 'status' => 'Active',
        ], $attrs));
    }

    /** An exit type carrying the last-resort default. */
    private function exitType(int $defaultDays = 30, ?Tenant $t = null): int
    {
        return DB::table('hr_exit_types')->insertGetId([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => 'Resignation '.substr(uniqid(), -4),
            'code' => 'RES'.substr(uniqid(), -4),
            'notice_required' => true, 'default_notice_days' => $defaultDays,
            'clearance_required' => true, 'fnf_required' => true,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** An exit policy scoped to a grade — this is grade-level notice. */
    private function gradePolicy(int $gradeId, int $noticeDays, ?Tenant $t = null, bool $active = true): int
    {
        return DB::table('hr_exit_policies')->insertGetId([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => 'Grade policy '.substr(uniqid(), -5),
            'grade_id' => $gradeId, 'notice_days' => $noticeDays,
            'buyout_allowed' => false, 'recovery_allowed' => false,
            'leave_encashment' => false, 'gratuity_applicable' => false,
            'clearance_required' => true, 'exit_interview_required' => false,
            'is_active' => $active, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Raise an exit through the real service and return the stored notice. */
    private function noticeFor(HrEmployee $employee, int $exitTypeId, array $over = []): int
    {
        $result = app(ExitRequestService::class)->create(array_merge([
            'employee_id' => $employee->id,
            'exit_type_id' => $exitTypeId,
            'request_date' => '2026-03-01',
        ], $over), (int) $employee->tenant_id);

        return (int) HrExitRequest::findOrFail($result['id'])->notice_days;
    }

    /* ── 1. the exit-type default ─────────────────────────────────────── */

    public function test_the_exit_type_default_is_used_when_nothing_else_is_configured(): void
    {
        $employee = $this->employee();
        $type = $this->exitType(30);

        $this->assertSame(30, $this->noticeFor($employee, $type));
    }

    /* ── 2. grade, via the exit policy that already supports it ───────── */

    public function test_a_grade_policy_supersedes_the_exit_type_default(): void
    {
        $grade = $this->grade('Manager');
        $this->gradePolicy($grade->id, 60);

        $employee = $this->employee(['grade_id' => $grade->id]);
        $type = $this->exitType(30);

        // Grade-level notice is configured by giving the grade an exit policy.
        // It was never missing, which is why hr_grades gains no notice column.
        $this->assertSame(60, $this->noticeFor($employee, $type));
    }

    /* ── 3. the employee's own standing period ────────────────────────── */

    public function test_an_employee_override_supersedes_the_grade_policy(): void
    {
        $grade = $this->grade('Manager');
        $this->gradePolicy($grade->id, 60);

        $employee = $this->employee(['grade_id' => $grade->id, 'notice_days' => 90]);
        $type = $this->exitType(30);

        $this->assertSame(90, $this->noticeFor($employee, $type));
    }

    public function test_an_employee_override_supersedes_the_exit_type_default(): void
    {
        $employee = $this->employee(['notice_days' => 15]);
        $type = $this->exitType(30);

        $this->assertSame(15, $this->noticeFor($employee, $type));
    }

    /* ── the one-off still wins over everything ───────────────────────── */

    public function test_a_number_typed_onto_the_exit_still_wins(): void
    {
        $grade = $this->grade('Manager');
        $this->gradePolicy($grade->id, 60);

        $employee = $this->employee(['grade_id' => $grade->id, 'notice_days' => 90]);
        $type = $this->exitType(30);

        // A negotiated separation. Unchanged behaviour: somebody decided this
        // for this exit, and the standing configuration does not override it.
        $this->assertSame(7, $this->noticeFor($employee, $type, ['notice_days' => 7]));
    }

    /* ── 4. zero is a value, not an absence ───────────────────────────── */

    public function test_an_employee_override_of_zero_means_no_notice(): void
    {
        $grade = $this->grade('Manager');
        $this->gradePolicy($grade->id, 60);

        // A contractor who genuinely serves none. A resolver testing
        // truthiness would hand them the grade's sixty days instead.
        $employee = $this->employee(['grade_id' => $grade->id, 'notice_days' => 0]);
        $type = $this->exitType(30);

        $this->assertSame(0, $this->noticeFor($employee, $type));
    }

    public function test_a_null_override_inherits_rather_than_meaning_zero(): void
    {
        $grade = $this->grade('Manager');
        $this->gradePolicy($grade->id, 60);

        $employee = $this->employee(['grade_id' => $grade->id, 'notice_days' => null]);
        $type = $this->exitType(30);

        $this->assertNull($employee->fresh()->notice_days);
        $this->assertSame(60, $this->noticeFor($employee, $type));
    }

    /* ── 5. rejected values ───────────────────────────────────────────── */

    public function test_a_negative_or_absurd_override_is_rejected(): void
    {
        $dept = \App\Models\Hr\HrDepartment::create(['tenant_id' => $this->a->id, 'name' => 'Ops', 'is_active' => true]);
        $desig = \App\Models\Hr\HrDesignation::create(['tenant_id' => $this->a->id, 'name' => 'Analyst', 'is_active' => true]);
        $employee = $this->employee();

        Sanctum::actingAs($this->hr());

        foreach ([-1, 400] as $bad) {
            $this->putJson("/api/hr/employees/{$employee->id}", ['notice_days' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors('notice_days');
        }

        $this->postJson('/api/hr/employees', [
            'name' => 'New', 'joining_date' => '2026-01-01', 'status' => 'Active',
            'department_id' => $dept->id, 'designation_id' => $desig->id,
            'skip_probation' => true, 'probation_skip_reason' => 'n/a',
            'notice_days' => -5,
        ])->assertStatus(422)->assertJsonValidationErrors('notice_days');

        $this->assertNull($employee->fresh()->notice_days);
    }

    /* ── 6 & 7. tenant isolation ──────────────────────────────────────── */

    public function test_another_workspaces_employee_cannot_be_given_an_override(): void
    {
        $theirs = $this->employee([], $this->b);

        Sanctum::actingAs($this->hr());
        $this->putJson("/api/hr/employees/{$theirs->id}", ['notice_days' => 90])->assertStatus(404);

        $this->assertNull($theirs->fresh()->notice_days);
    }

    public function test_a_grade_policy_never_reaches_across_workspaces(): void
    {
        // Beta configures sixty days for its Manager grade.
        $theirGrade = $this->grade('Manager', $this->b);
        $this->gradePolicy($theirGrade->id, 60, $this->b);

        // Alpha has a grade with the same name and no policy of its own.
        $myGrade = $this->grade('Manager');
        $employee = $this->employee(['grade_id' => $myGrade->id]);
        $type = $this->exitType(30);

        // Alpha falls through to its own exit type, never Beta's policy.
        $this->assertSame(30, $this->noticeFor($employee, $type));
    }

    /**
     * A policy row pointing at another workspace's grade must not match.
     *
     * The test above uses two different grades with the same NAME, which an
     * unscoped query would not match either — so it proves nothing about the
     * tenant filter. This plants the row that does: Beta's policy pointing at
     * Alpha's grade_id. Without ExitRepository::policyForEmployee()'s
     * tenant_id clause, Alpha's employee would inherit Beta's notice period.
     */
    public function test_a_policy_row_pointing_at_another_workspaces_grade_is_ignored(): void
    {
        $myGrade = $this->grade('Manager');
        $employee = $this->employee(['grade_id' => $myGrade->id]);
        $type = $this->exitType(30);

        // Beta's policy, aimed at Alpha's grade.
        $this->gradePolicy($myGrade->id, 120, $this->b);

        $this->assertSame(30, $this->noticeFor($employee, $type),
            'a policy from another workspace governed this exit');
    }

    /**
     * The service refuses a negative notice even though no caller can send one.
     *
     * Every HTTP path validates min:0 and all three stored levels are unsigned,
     * so the guard inside computeNotice() is unreachable from outside. It is
     * still the last thing standing between a bad internal call and an exit
     * whose notice window runs backwards, and this calls the service directly
     * to prove it holds.
     */
    public function test_the_service_refuses_a_negative_notice_period(): void
    {
        $employee = $this->employee();
        $type = $this->exitType(30);

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->expectExceptionMessage('Notice period cannot be negative.');

        app(ExitRequestService::class)->create([
            'employee_id' => $employee->id, 'exit_type_id' => $type,
            'request_date' => '2026-03-01', 'notice_days' => -5,
        ], $this->a->id);
    }

    public function test_one_workspaces_employee_override_does_not_affect_another(): void
    {
        $mine = $this->employee(['notice_days' => 90]);
        $theirs = $this->employee([], $this->b);

        $myType = $this->exitType(30);
        $theirType = $this->exitType(30, $this->b);

        $this->assertSame(90, $this->noticeFor($mine, $myType));
        $this->assertSame(30, $this->noticeFor($theirs, $theirType));
    }

    /* ── 8 & 9. employees missing the middle levels ───────────────────── */

    public function test_an_employee_with_no_grade_resolves_safely(): void
    {
        $employee = $this->employee(['grade_id' => null]);
        $type = $this->exitType(45);

        $this->assertNull($employee->fresh()->grade_id);
        $this->assertSame(45, $this->noticeFor($employee, $type));
    }

    public function test_an_employee_with_no_override_and_no_policy_resolves_safely(): void
    {
        $grade = $this->grade('Manager');
        $employee = $this->employee(['grade_id' => $grade->id]);
        $type = $this->exitType(45);

        // A grade with no policy attached falls through, rather than resolving
        // to zero or throwing.
        $this->assertSame(45, $this->noticeFor($employee, $type));
    }

    /* ── 10. a retired grade policy ───────────────────────────────────── */

    public function test_an_inactive_grade_policy_is_not_matched(): void
    {
        $grade = $this->grade('Manager');
        $this->gradePolicy($grade->id, 60, null, false);

        $employee = $this->employee(['grade_id' => $grade->id]);
        $type = $this->exitType(30);

        // policyForEmployee() filters on is_active, which is existing
        // behaviour — a retired policy stops governing new exits.
        $this->assertSame(30, $this->noticeFor($employee, $type));
    }

    /* ── 11. the snapshot on an existing exit is authoritative ────────── */

    public function test_changing_an_override_does_not_rewrite_an_existing_exit(): void
    {
        $employee = $this->employee(['notice_days' => 30]);
        $type = $this->exitType(30);

        $result = app(ExitRequestService::class)->create([
            'employee_id' => $employee->id, 'exit_type_id' => $type,
            'request_date' => '2026-03-01',
        ], $this->a->id);

        $request = HrExitRequest::findOrFail($result['id']);
        $this->assertSame(30, (int) $request->notice_days);
        $end = $request->notice_end_date;

        // HR later changes the person's standing notice period.
        Sanctum::actingAs($this->hr());
        $this->putJson("/api/hr/employees/{$employee->id}", ['notice_days' => 90])->assertOk();

        // The exit already raised keeps the number it was raised with — the
        // request row is the snapshot and nothing recomputes it behind anyone.
        $fresh = $request->fresh();
        $this->assertSame(30, (int) $fresh->notice_days);
        $this->assertEquals($end, $fresh->notice_end_date);
    }

    /* ── 12. authorization ────────────────────────────────────────────── */

    public function test_only_an_hr_manager_may_set_an_override(): void
    {
        $employee = $this->employee();

        Sanctum::actingAs($this->outsider());
        $this->putJson("/api/hr/employees/{$employee->id}", ['notice_days' => 90])->assertStatus(403);

        $this->assertNull($employee->fresh()->notice_days);
    }

    public function test_an_unauthenticated_caller_cannot_set_an_override(): void
    {
        $employee = $this->employee();

        $this->putJson("/api/hr/employees/{$employee->id}", ['notice_days' => 90])->assertStatus(401);

        $this->assertNull($employee->fresh()->notice_days);
    }

    /* ── the override is settable and clearable through the API ───────── */

    public function test_an_override_can_be_set_and_cleared_back_to_inheriting(): void
    {
        $grade = $this->grade('Manager');
        $this->gradePolicy($grade->id, 60);
        $employee = $this->employee(['grade_id' => $grade->id]);
        $type = $this->exitType(30);

        Sanctum::actingAs($this->hr());

        $this->putJson("/api/hr/employees/{$employee->id}", ['notice_days' => 90])->assertOk();
        $this->assertSame(90, (int) $employee->fresh()->notice_days);
        $this->assertSame(90, $this->noticeFor($employee->fresh(), $type));

        // Null puts them back on the grade policy rather than on zero.
        $this->putJson("/api/hr/employees/{$employee->id}", ['notice_days' => null])->assertOk();
        $this->assertNull($employee->fresh()->notice_days);
        $this->assertSame(60, $this->noticeFor($employee->fresh(), $type));
    }

    public function test_the_override_is_returned_by_the_employee_api(): void
    {
        $employee = $this->employee(['notice_days' => 45]);

        Sanctum::actingAs($this->hr());
        $this->getJson("/api/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('notice_days', 45);
    }

    /* ── 14. existing exit flows still compute ────────────────────────── */

    public function test_the_notice_window_is_computed_from_the_resolved_days(): void
    {
        $employee = $this->employee(['notice_days' => 10]);
        $type = $this->exitType(30);

        $result = app(ExitRequestService::class)->create([
            'employee_id' => $employee->id, 'exit_type_id' => $type,
            'request_date' => '2026-03-01',
        ], $this->a->id);

        $request = HrExitRequest::findOrFail($result['id']);

        $this->assertSame(10, (int) $request->notice_days);
        $this->assertSame('2026-03-01', $request->notice_start_date->toDateString());
        $this->assertSame('2026-03-11', $request->notice_end_date->toDateString());
    }

    /* ── grade concept integrity ──────────────────────────────────────── */

    public function test_the_grade_master_gains_no_competing_notice_column(): void
    {
        // Grade-level notice lives on the exit policy. A column here would be a
        // second answer to the same question, and the two would disagree the
        // first time somebody edited one of them.
        $this->assertNotContains('notice_days', \Schema::getColumnListing('hr_grades'));
        $this->assertNotContains('default_notice_days', \Schema::getColumnListing('hr_grades'));
    }
}
