<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrAttendanceCorrection;
use App\Models\Hr\HrEmployee;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Hr\DataScope;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The eighth and last operational scope surface.
 *
 * An attendance correction says somebody's punch was wrong and what they claim
 * the real times were. The route group has always required the attendance
 * permission, and a permission is not a scope: it says whether you may work on
 * corrections at all, never whose. A department-scoped HR user held it and
 * therefore listed — and could approve — every correction in the workspace,
 * because index() filtered on tenant_id alone and find() did the same.
 *
 * Both halves are pinned here. Narrowing the list while leaving the id-based
 * actions open holds only until somebody types an id into the URL, and all five
 * of those actions (show, approve, reject, hold, note) go through one private
 * find(), so the assertion sits there and each of them is tested through it.
 *
 * The refusal is 404, not 403, and that is asserted rather than assumed. A 403
 * would confirm the record exists and that it belongs to somebody outside your
 * department — the thing the boundary is there to withhold.
 *
 * Two things must NOT change, and are pinned as such: an employee's own
 * self-service path, and the attendance app. The app decides corrections
 * through AttendanceCorrectionService directly and scopes itself by reporting
 * line; the boundary is on this controller precisely so the phone is untouched.
 */
class ScopedAttendanceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private HrEmployee $mate;       // same department as the actor
    private HrEmployee $outsider;   // another department

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'scoped-corr', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function actor(string $scope, string $email = 'actor@corr.test'): User
    {
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'R'.substr(md5($email.$scope), 0, 6),
            'slug' => 'r_'.substr(md5($email.$scope), 0, 6),
            // The permission the route group demands. Holding it is exactly the
            // situation this test is about: permitted, but not for everybody.
            'permissions' => [
                'hr_attendance' => [StaffPermission::VIEW_GLOBAL],
                'hr_employees'  => [StaffPermission::VIEW_GLOBAL],
            ],
            'scope' => $scope, 'is_system' => false,
        ]);

        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => 'staff',
            'status' => 'active', 'staff_role_id' => $role->id,
        ]);
    }

    private function employee(string $code, ?User $user = null, string $dept = 'Ops'): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'employee_code' => $code, 'name' => 'E'.$code,
            'department' => $dept, 'designation' => 'Analyst', 'joining_date' => '2024-01-01',
            'status' => 'Active', 'user_id' => $user?->id,
        ]);
    }

    /** A department-scoped approver, with a colleague and an outsider to see. */
    private function cast(string $scope = DataScope::DEPARTMENT): User
    {
        $user = $this->actor($scope);
        $this->employee('C-1', $user, 'Ops');            // the actor themselves
        $this->mate     = $this->employee('C-2', null, 'Ops');
        $this->outsider = $this->employee('C-3', null, 'Sales');

        return $user;
    }

    private function correction(HrEmployee $employee, string $status = HrAttendanceCorrection::PENDING): HrAttendanceCorrection
    {
        return HrAttendanceCorrection::create([
            'tenant_id'          => $this->tenant->id,
            'employee_id'        => $employee->id,
            'attendance_date'    => '2026-09-01',
            // Clock faces, as the column holds — the service converts them to
            // the matching instant in the tenant's zone on approval.
            'requested_check_in'  => '09:30',
            'requested_check_out' => '18:30',
            'reason'              => 'Badge reader was down.',
            'status'              => $status,
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'admin@corr.test',
            'password' => Hash::make('Password123!'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    /* ── 1. the list ──────────────────────────────────────────────────── */

    public function test_the_queue_shows_only_the_departments_own_corrections(): void
    {
        $user = $this->cast();
        $mine  = $this->correction($this->mate);
        $theirs = $this->correction($this->outsider);

        Sanctum::actingAs($user);

        $ids = collect($this->getJson('/api/hr/corrections')->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids, 'another department\'s correction must not be listed');
    }

    public function test_a_global_actor_still_sees_everything(): void
    {
        $this->cast();
        $mine   = $this->correction($this->mate);
        $theirs = $this->correction($this->outsider);

        // Unscoped behaviour must be byte-identical to before — the resolver
        // returns null for a global actor and the query is left alone.
        Sanctum::actingAs($this->admin());

        $ids = collect($this->getJson('/api/hr/corrections')->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertContains($theirs->id, $ids);
    }

    public function test_filtering_by_an_out_of_scope_employee_returns_nothing(): void
    {
        $user = $this->cast();
        $this->correction($this->outsider);

        Sanctum::actingAs($user);

        // The filter intersects with the scope rather than escaping it — asking
        // for somebody by id is not a way round the boundary.
        $rows = $this->getJson('/api/hr/corrections?employee_id='.$this->outsider->id)
            ->assertOk()->json('data');

        $this->assertSame([], $rows);
    }

    /* ── 1b. the BRANCH asymmetry, pinned on purpose ──────────────────── */

    public function test_a_branch_scoped_actor_is_not_narrowed_on_the_list(): void
    {
        // hr_employees.branch is free text with no master behind it, so BRANCH
        // is excluded from COLLECTION scoping on every one of the eight
        // surfaces — an unsupported scope resolves to global rather than
        // guessing. This is deliberate and long-standing, and it is asserted
        // here so that changing it is a decision somebody makes rather than a
        // side effect. It is not a licence to widen: the id-based half below
        // still honours branch.
        $user = $this->actor(DataScope::BRANCH, 'branch@corr.test');
        $me = $this->employee('B-1', $user, 'Ops');
        $me->update(['branch' => 'North']);

        $elsewhere = $this->employee('B-2', null, 'Sales');
        $elsewhere->update(['branch' => 'South']);
        $c = $this->correction($elsewhere);

        Sanctum::actingAs($user);

        $ids = collect($this->getJson('/api/hr/corrections')->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertContains($c->id, $ids, 'BRANCH is not honoured for collections — by design');
    }

    public function test_a_branch_scoped_actor_is_still_refused_on_a_single_record(): void
    {
        // The other half of the asymmetry: canActOnEmployee() consults every
        // scope, branch included, so the id-based actions DO narrow even though
        // the list does not.
        $user = $this->actor(DataScope::BRANCH, 'branch2@corr.test');
        $me = $this->employee('B-3', $user, 'Ops');
        $me->update(['branch' => 'North']);

        $elsewhere = $this->employee('B-4', null, 'Sales');
        $elsewhere->update(['branch' => 'South']);
        $c = $this->correction($elsewhere);

        Sanctum::actingAs($user);

        $this->postJson("/api/hr/corrections/{$c->id}/approve")->assertStatus(404);
        $this->assertSame(HrAttendanceCorrection::PENDING, $c->fresh()->status);
    }

    /* ── 2. every id-based action, through find() ─────────────────────── */

    public function test_an_out_of_scope_correction_is_not_readable(): void
    {
        $user = $this->cast();
        $c = $this->correction($this->outsider);

        Sanctum::actingAs($user);

        $this->getJson("/api/hr/corrections/{$c->id}")->assertStatus(404);
    }

    public function test_an_out_of_scope_correction_cannot_be_approved(): void
    {
        $user = $this->cast();
        $c = $this->correction($this->outsider);

        Sanctum::actingAs($user);

        $this->postJson("/api/hr/corrections/{$c->id}/approve")->assertStatus(404);
        $this->assertSame(HrAttendanceCorrection::PENDING, $c->fresh()->status);
    }

    public function test_an_out_of_scope_correction_cannot_be_rejected(): void
    {
        $user = $this->cast();
        $c = $this->correction($this->outsider);

        Sanctum::actingAs($user);

        $this->postJson("/api/hr/corrections/{$c->id}/reject", ['remarks' => 'No.'])->assertStatus(404);
        $this->assertSame(HrAttendanceCorrection::PENDING, $c->fresh()->status);
    }

    public function test_an_out_of_scope_correction_cannot_be_held(): void
    {
        $user = $this->cast();
        $c = $this->correction($this->outsider);

        Sanctum::actingAs($user);

        $this->postJson("/api/hr/corrections/{$c->id}/hold", ['reason' => 'Which site?'])->assertStatus(404);
        $this->assertSame(HrAttendanceCorrection::PENDING, $c->fresh()->status);
    }

    public function test_an_out_of_scope_correction_cannot_be_noted_on(): void
    {
        $user = $this->cast();
        $c = $this->correction($this->outsider);

        Sanctum::actingAs($user);

        // A note is a write too, and it is the action most easily forgotten —
        // which is why the assertion lives in find() rather than in each method.
        $this->postJson("/api/hr/corrections/{$c->id}/note", ['body' => 'Looks off.'])->assertStatus(404);
    }

    public function test_the_refusal_is_a_404_and_not_a_403(): void
    {
        $user = $this->cast();
        $c = $this->correction($this->outsider);

        Sanctum::actingAs($user);

        // The privacy property itself: out of scope has to read the same as not
        // there. A 403 confirms the record exists and sits in another
        // department, which is the thing being withheld.
        $response = $this->getJson("/api/hr/corrections/{$c->id}");

        $response->assertStatus(404);
        $this->assertNotSame(403, $response->status());
    }

    /* ── 3. the feature still works inside the boundary ───────────────── */

    public function test_an_in_scope_correction_is_readable(): void
    {
        $user = $this->cast();
        $c = $this->correction($this->mate);

        Sanctum::actingAs($user);

        $this->getJson("/api/hr/corrections/{$c->id}")
            ->assertOk()
            ->assertJsonPath('data.correction.id', $c->id);
    }

    public function test_an_in_scope_correction_still_approves_and_writes_the_day(): void
    {
        $user = $this->cast();
        $c = $this->correction($this->mate);

        Sanctum::actingAs($user);

        // Proves the 404s above come from the boundary and not from a status
        // check or a broken route — the same call on an in-scope record works.
        $this->postJson("/api/hr/corrections/{$c->id}/approve")->assertOk();

        $this->assertSame(HrAttendanceCorrection::APPROVED, $c->fresh()->status);
        $this->assertDatabaseHas('hr_attendance', [
            'tenant_id'   => $this->tenant->id,
            'employee_id' => $this->mate->id,
        ]);
    }

    public function test_an_in_scope_correction_can_be_held_and_noted(): void
    {
        $user = $this->cast();
        $c = $this->correction($this->mate);

        Sanctum::actingAs($user);

        $this->postJson("/api/hr/corrections/{$c->id}/hold", ['reason' => 'Which site?'])->assertOk();
        $this->postJson("/api/hr/corrections/{$c->id}/note", ['body' => 'Chased.'])->assertOk();
    }

    /* ── 4. tenant isolation is unchanged ─────────────────────────────── */

    public function test_another_tenants_correction_is_still_not_found(): void
    {
        $user = $this->cast();

        $other = Tenant::create(['name' => 'Other', 'slug' => 'corr-other', 'status' => 'active']);
        $theirEmployee = HrEmployee::create([
            'tenant_id' => $other->id, 'employee_code' => 'X-1', 'name' => 'X',
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2024-01-01',
            'status' => 'Active',
        ]);
        $c = HrAttendanceCorrection::create([
            'tenant_id' => $other->id, 'employee_id' => $theirEmployee->id,
            'attendance_date' => '2026-09-01', 'requested_check_in' => '09:30',
            'reason' => 'x', 'status' => HrAttendanceCorrection::PENDING,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/hr/corrections/{$c->id}")->assertStatus(404);
    }

    /* ── 5. what must NOT have changed ────────────────────────────────── */

    public function test_an_employee_still_reaches_their_own_corrections(): void
    {
        // Self-service pins employee_id to the caller's own record and is not
        // scoped by the resolver. An employee whose scope is 'own' — or who has
        // no staff role at all — must still see their own request.
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Emp', 'email' => 'emp@corr.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);
        $employee = $this->employee('C-9', $user, 'Sales');
        $c = $this->correction($employee);

        Sanctum::actingAs($user);

        $this->getJson('/api/hr/me/corrections')
            ->assertOk()
            ->assertJsonPath('data.0.id', $c->id);
    }

    public function test_a_plain_employee_still_cannot_reach_the_approver_queue(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Emp2', 'email' => 'emp2@corr.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);
        $this->employee('C-8', $user, 'Sales');

        Sanctum::actingAs($user);

        // Still the PERMISSION gate, refusing before scope is ever consulted.
        // Adding a scope must not have softened it into a 404.
        $this->getJson('/api/hr/corrections')->assertStatus(403);
    }
}
