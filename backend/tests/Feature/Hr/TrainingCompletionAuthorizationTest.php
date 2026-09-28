<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
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
 * /hr/learning/completion — permission AND scope (Phase 7, Part A).
 *
 * This endpoint was the last ungated door in Learning. Its twelve siblings —
 * programs, sessions, assignments, attendance, assessments, certificates,
 * quizzes, categories, types, providers and the reports — all gate on
 * canManageHrQueue(); this one asked only that the caller be authenticated. Any
 * account in the tenant, portal logins included, could read who had completed,
 * failed or been certified on every training, and name any employee id in the
 * URL to single one out.
 *
 * It is administrative, not self-service: its consumers are the L&D module and
 * the HR Employee Profile's training tab, both of which read OTHER people's
 * records. There is no "my trainings" screen behind it, so gating it takes
 * nothing away from an employee.
 *
 * The two boundaries are asserted SEPARATELY and in both directions, because
 * the failure worth catching is one being mistaken for the other:
 *
 *   - a GLOBAL-scoped actor without the permission must still be refused;
 *   - a PERMITTED actor with a narrow scope must see only their own people.
 */
class TrainingCompletionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $programId;

    private int $sessionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'TC', 'slug' => 'training-completion-authz', 'status' => 'active']);

        $now = now()->toDateTimeString();

        $catId = DB::table('hr_training_categories')->insertGetId([
            'tenant_id' => $this->tenant->id, 'name' => 'Technical', 'code' => 'TECH',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $typeId = DB::table('hr_training_types')->insertGetId([
            'tenant_id' => $this->tenant->id, 'name' => 'Workshop', 'code' => 'WS',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $provId = DB::table('hr_training_providers')->insertGetId([
            'tenant_id' => $this->tenant->id, 'name' => 'In House',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->programId = DB::table('hr_training_programs')->insertGetId([
            'tenant_id' => $this->tenant->id, 'category_id' => $catId, 'training_type_id' => $typeId,
            'provider_id' => $provId, 'program_code' => 'PRG1', 'program_name' => 'Safety',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->sessionId = DB::table('hr_training_sessions')->insertGetId([
            'tenant_id' => $this->tenant->id, 'training_program_id' => $this->programId,
            'trainer_name' => 'Trainer A', 'title' => 'Safety S1',
            'start_at' => now()->startOfMonth()->toDateTimeString(),
            'end_at' => now()->startOfMonth()->addHours(2)->toDateTimeString(),
            'status' => 'Completed', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function hrUser(string $email, string $scope, string $accountType = 'staff'): User
    {
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'R '.substr(md5($email), 0, 6),
            'slug' => 'r_'.substr(md5($email), 0, 6),
            'permissions' => ['hr_employees' => [StaffPermission::VIEW_GLOBAL]],
            'scope' => $scope, 'is_system' => false,
        ]);

        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $accountType,
            'status' => 'active', 'staff_role_id' => $role->id,
        ]);
    }

    /** A staff account with NO HR authority at all. */
    private function plainStaff(string $email): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Plain', 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);
    }

    private function employee(string $code, ?User $user = null, string $dept = 'Ops', ?int $managerId = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'employee_code' => $code, 'name' => 'E'.$code,
            'department' => $dept, 'designation' => 'Analyst', 'joining_date' => '2024-01-01',
            'status' => 'Active', 'user_id' => $user?->id, 'reporting_manager_id' => $managerId,
        ]);
    }

    private function assigned(HrEmployee $e, string $status = 'Completed'): int
    {
        $now = now()->toDateTimeString();

        return DB::table('hr_employee_trainings')->insertGetId([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
            'training_program_id' => $this->programId, 'training_session_id' => $this->sessionId,
            'status' => $status, 'completion_percentage' => 100,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function cast(string $scope): array
    {
        $user = $this->hrUser("tc-{$scope}@tc.test", $scope);
        $me   = $this->employee('T-1', $user, 'Ops');
        $mate = $this->employee('T-2', null, 'Ops');
        $out  = $this->employee('T-3', null, 'Sales');

        $this->assigned($me);
        $this->assigned($mate);
        $this->assigned($out);

        return [$user, $me, $mate, $out];
    }

    /* ── permission ───────────────────────────────────────────────────── */

    public function test_an_authenticated_account_without_hr_authority_is_refused(): void
    {
        $this->cast(DataScope::GLOBAL);

        Sanctum::actingAs($this->plainStaff('plain@tc.test'));

        $this->getJson('/api/hr/learning/completion')->assertForbidden();
        $this->getJson('/api/hr/learning/completion/employee/1')->assertForbidden();
    }

    public function test_global_scope_does_not_substitute_for_permission(): void
    {
        [, $me] = $this->cast(DataScope::GLOBAL);

        // The failure this whole phase is guarding against: an account whose
        // scope resolves to global (no staff role at all does exactly that)
        // must not thereby be treated as permitted.
        $plain = $this->plainStaff('globalnoperm@tc.test');
        $this->employee('T-9', $plain, 'Ops');

        Sanctum::actingAs($plain);

        $this->getJson('/api/hr/learning/completion')->assertForbidden();
        $this->getJson("/api/hr/learning/completion/employee/{$me->id}")->assertForbidden();
    }

    public function test_portal_account_types_are_refused(): void
    {
        $this->cast(DataScope::GLOBAL);

        foreach (['client', 'contact', 'doctor', 'company', 'patient'] as $i => $type) {
            $user = $this->hrUser("tcportal{$i}@tc.test", DataScope::GLOBAL, $type);
            Sanctum::actingAs($user);

            $this->getJson('/api/hr/learning/completion')->assertForbidden();
        }
    }

    public function test_a_permitted_user_may_open_the_completion_view(): void
    {
        [$user] = $this->cast(DataScope::GLOBAL);
        Sanctum::actingAs($user);

        $this->getJson('/api/hr/learning/completion')->assertOk();
    }

    /* ── scope ────────────────────────────────────────────────────────── */

    public function test_department_scope_limits_the_rows_and_the_stats(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $body = $this->getJson('/api/hr/learning/completion')->assertOk()->json();

        $names = collect($body['data'])->pluck('employee_name')->all();
        $this->assertEqualsCanonicalizing(['ET-1', 'ET-2'], $names);

        // stats is counted from the scoped rows, not the tenant's.
        $this->assertSame(2, $body['stats']['completed']);
    }

    public function test_own_scope_sees_one_row(): void
    {
        [$user] = $this->cast(DataScope::OWN);
        Sanctum::actingAs($user);

        $body = $this->getJson('/api/hr/learning/completion')->assertOk()->json();

        $this->assertSame(['ET-1'], collect($body['data'])->pluck('employee_name')->all());
        $this->assertSame(1, $body['stats']['completed']);
    }

    public function test_team_scope_follows_the_reporting_line(): void
    {
        $user = $this->hrUser('tcteam@tc.test', DataScope::TEAM);
        $lead = $this->employee('TT-1', $user, 'Ops');
        $mine = $this->employee('TT-2', null, 'Ops', $lead->id);
        $other = $this->employee('TT-3', null, 'Ops');

        $this->assigned($lead);
        $this->assigned($mine);
        $this->assigned($other);

        Sanctum::actingAs($user);
        $names = collect($this->getJson('/api/hr/learning/completion')->assertOk()->json('data'))
            ->pluck('employee_name')->all();

        $this->assertContains('ETT-1', $names);
        $this->assertContains('ETT-2', $names);
        $this->assertNotContains('ETT-3', $names);
    }

    public function test_a_permitted_actor_with_no_employee_record_sees_nothing(): void
    {
        $this->cast(DataScope::DEPARTMENT);
        $stranger = $this->hrUser('tcstranger@tc.test', DataScope::DEPARTMENT);

        Sanctum::actingAs($stranger);
        $body = $this->getJson('/api/hr/learning/completion')->assertOk()->json();

        $this->assertSame([], $body['data']);
        $this->assertSame(0, $body['stats']['completed']);
    }

    public function test_global_scope_still_sees_everyone(): void
    {
        [$user] = $this->cast(DataScope::GLOBAL);
        Sanctum::actingAs($user);

        $this->assertCount(3, $this->getJson('/api/hr/learning/completion')->assertOk()->json('data'));
    }

    /* ── neither boundary can be bypassed by an id or a filter ────────── */

    public function test_an_employee_id_filter_cannot_reach_outside_the_scope(): void
    {
        [$user, , , $out] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $body = $this->getJson("/api/hr/learning/completion?employee_id={$out->id}")->assertOk()->json();

        $this->assertSame([], $body['data']);
    }

    public function test_a_department_filter_cannot_reach_another_department(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $body = $this->getJson('/api/hr/learning/completion?department=Sales')->assertOk()->json();

        $this->assertSame([], $body['data']);
    }

    public function test_a_direct_employee_id_in_the_url_answers_404_when_out_of_scope(): void
    {
        [$user, $me, $mate, $out] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        // In scope: readable.
        $this->getJson("/api/hr/learning/completion/employee/{$me->id}")->assertOk();
        $this->getJson("/api/hr/learning/completion/employee/{$mate->id}")->assertOk();

        // Out of scope: 404, not 403 — a 403 would confirm the employee exists
        // and sits in another department, which is the fact being withheld.
        $this->getJson("/api/hr/learning/completion/employee/{$out->id}")->assertNotFound();
    }

    public function test_own_scope_cannot_read_a_colleague_by_url(): void
    {
        [$user, , $mate] = $this->cast(DataScope::OWN);
        Sanctum::actingAs($user);

        $this->getJson("/api/hr/learning/completion/employee/{$mate->id}")->assertNotFound();
    }

    public function test_an_admin_is_never_restricted(): void
    {
        $this->cast(DataScope::DEPARTMENT);

        $admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'tcadmin@tc.test',
            'password' => Hash::make('Password123!'), 'role' => 'admin', 'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        $this->assertCount(3, $this->getJson('/api/hr/learning/completion')->assertOk()->json('data'));
    }
}
