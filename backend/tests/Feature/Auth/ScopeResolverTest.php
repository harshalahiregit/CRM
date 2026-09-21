<?php

namespace Tests\Feature\Auth;

use App\Models\Hr\HrDepartment;
use App\Models\Hr\HrEmployee;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ScopeResolver;
use App\Support\Hr\DataScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Which records an already-authorised person may see.
 *
 * Scope existed before this and was computed correctly — and was read in one
 * place, UserResource, where it decided which menu items rendered. No query in
 * the application filtered on it, so every authorised person saw the whole
 * tenant regardless of what their role said.
 *
 * The resolver answers once, as a list of employee ids, so a module does not
 * restate the hierarchy walk in its own query builder. null means no
 * restriction; [] means nothing.
 *
 * The [] case is the one that matters most and is tested hardest: an actor
 * whose scope needs an employee record and has none must see NOTHING, never
 * everything. AdvanceTierService made that choice first ("Nothing is the safe
 * answer, not everything"); this generalises it.
 */
class ScopeResolverTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ScopeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = Tenant::create(['name' => 'Scope', 'slug' => 'scope-resolver', 'status' => 'active']);
        $this->resolver = app(ScopeResolver::class);
    }

    private function role(string $name, string $scope): StaffRole
    {
        return StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => $name,
            'slug' => strtolower(str_replace(' ', '_', $name)), 'permissions' => [],
            'scope' => $scope, 'is_system' => false,
        ]);
    }

    private function user(string $email, ?StaffRole $role = null, string $accountType = 'staff', ?Tenant $tenant = null): User
    {
        return User::create([
            'tenant_id' => ($tenant ?? $this->tenant)->id, 'name' => 'U', 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $accountType, 'status' => 'active',
            'staff_role_id' => $role?->id,
        ]);
    }

    /**
     * HrEmployee::saving runs OrgLink, which keeps department_id in step with
     * the department NAME and creates the record if the name is new. So a
     * fixture that wants no department must supply no name either — passing a
     * name and a null id produces an id anyway, which is correct behaviour and
     * was the first thing this test got wrong.
     */
    private function employee(
        string $code,
        ?User $user = null,
        ?int $deptId = null,
        ?int $managerId = null,
        ?string $branch = null,
        ?Tenant $tenant = null,
        ?string $departmentName = 'Ops',
    ): HrEmployee {
        return HrEmployee::create([
            'tenant_id' => ($tenant ?? $this->tenant)->id, 'employee_code' => $code, 'name' => 'E'.$code,
            'department' => $departmentName, 'designation' => 'Analyst', 'joining_date' => '2024-01-01',
            'status' => 'Active', 'user_id' => $user?->id, 'department_id' => $deptId,
            'reporting_manager_id' => $managerId, 'branch' => $branch,
        ]);
    }

    private function department(string $name): HrDepartment
    {
        return HrDepartment::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'is_active' => true]);
    }

    /* ── default and global ───────────────────────────────────────────── */

    public function test_a_role_defaults_to_global(): void
    {
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Defaulted', 'slug' => 'defaulted',
            'permissions' => [], 'is_system' => false,
        ]);

        $this->assertSame(DataScope::GLOBAL, $role->fresh()->scope,
            'The column exists so that global is stated, not so that it is guessed.');
    }

    public function test_global_places_no_restriction(): void
    {
        $user = $this->user('global@scope.test', $this->role('Global Role', DataScope::GLOBAL));
        $this->employee('G-1', $user);

        $this->assertNull($this->resolver->visibleEmployeeIds($user),
            'null means "do not touch the query" — that is what keeps today\'s behaviour identical.');
    }

    public function test_a_user_with_no_role_is_global(): void
    {
        $this->assertNull($this->resolver->visibleEmployeeIds($this->user('norole@scope.test')),
            'Everybody who predates roles has none, and their access must not change.');
    }

    /**
     * An admin bypasses the permission grid; a scope that could lock them out of
     * the screen that fixes scopes would be a trap.
     */
    public function test_an_admin_is_global_whatever_their_role_says(): void
    {
        $admin = $this->user('admin@scope.test', $this->role('Narrow', DataScope::OWN), 'admin');
        $this->employee('A-1', $admin);

        $this->assertSame(DataScope::GLOBAL, $this->resolver->scopeFor($admin));
        $this->assertNull($this->resolver->visibleEmployeeIds($admin));
    }

    /* ── the failure direction ────────────────────────────────────────── */

    /**
     * The single most important case in this file.
     *
     * @dataProvider narrowingScopes
     */
    public function test_an_actor_with_no_employee_record_sees_nothing(string $scope): void
    {
        $user = $this->user("noemp-{$scope}@scope.test", $this->role("R {$scope}", $scope));
        $this->employee('OTHER-1');   // somebody else exists

        $this->assertSame([], $this->resolver->visibleEmployeeIds($user),
            "Scope '{$scope}' needs an employee record. Without one the honest answer is nothing — "
            .'never null, which would hand them the whole tenant.');
    }

    public static function narrowingScopes(): array
    {
        return [
            'own'        => [DataScope::OWN],
            'department' => [DataScope::DEPARTMENT],
            'branch'     => [DataScope::BRANCH],
            'team'       => [DataScope::TEAM],
        ];
    }

    /** And an empty list must reach the query as "no rows", not as "all rows". */
    public function test_an_empty_list_becomes_an_impossible_condition(): void
    {
        $user = $this->user('empty@scope.test', $this->role('Own Only', DataScope::OWN));
        $this->employee('E-1');

        $query = $this->resolver->applyToQuery(HrEmployee::query(), $user, 'id');

        $this->assertSame(0, $query->count(), 'whereRaw(1=0), not an untouched query.');
    }

    /* ── own ──────────────────────────────────────────────────────────── */

    public function test_own_returns_only_the_actors_own_record(): void
    {
        $user = $this->user('own@scope.test', $this->role('Own', DataScope::OWN));
        $me   = $this->employee('O-1', $user);
        $this->employee('O-2');

        $this->assertSame([(int) $me->id], $this->resolver->visibleEmployeeIds($user));
    }

    /* ── department ───────────────────────────────────────────────────── */

    public function test_department_returns_everybody_in_the_same_department(): void
    {
        $ops   = $this->department('Ops');
        $sales = $this->department('Sales');

        $user = $this->user('dept@scope.test', $this->role('Dept', DataScope::DEPARTMENT));
        $me   = $this->employee('D-1', $user, $ops->id);
        $mate = $this->employee('D-2', null, $ops->id);
        $away = $this->employee('D-3', null, $sales->id);

        $ids = $this->resolver->visibleEmployeeIds($user);

        $this->assertContains((int) $me->id, $ids);
        $this->assertContains((int) $mate->id, $ids);
        $this->assertNotContains((int) $away->id, $ids);
    }

    /**
     * "No department" is not a department two people share.
     *
     * This cannot happen through the application: hr_employees.department is NOT
     * NULL and HrEmployee::saving runs OrgLink, which turns the name into an id
     * (creating the record if it is new). So the only way to hold a null
     * department_id is to predate OrgLink or to have been written around the
     * model — which is exactly what the guard in departmentIds() defends, and
     * why the fixture nulls the column directly rather than pretending the
     * normal path can produce it.
     */
    public function test_an_actor_with_no_department_does_not_inherit_everyone_unassigned(): void
    {
        $user = $this->user('nodept@scope.test', $this->role('Dept2', DataScope::DEPARTMENT));
        $me   = $this->employee('N-1', $user);
        $mate = $this->employee('N-2');

        HrEmployee::whereIn('id', [$me->id, $mate->id])->update(['department_id' => null]);

        $this->assertSame([(int) $me->id], $this->resolver->visibleEmployeeIds($user),
            'Two unassigned people are not colleagues.');
    }

    /* ── branch ───────────────────────────────────────────────────────── */

    public function test_branch_returns_everybody_at_the_same_branch(): void
    {
        $user = $this->user('branch@scope.test', $this->role('Branch', DataScope::BRANCH));
        $me   = $this->employee('B-1', $user, null, null, 'Pune');
        $mate = $this->employee('B-2', null, null, null, 'Pune');
        $away = $this->employee('B-3', null, null, null, 'Mumbai');

        $ids = $this->resolver->visibleEmployeeIds($user);

        $this->assertContains((int) $me->id, $ids);
        $this->assertContains((int) $mate->id, $ids);
        $this->assertNotContains((int) $away->id, $ids);
    }

    public function test_an_actor_with_no_branch_does_not_inherit_everyone_unassigned(): void
    {
        $user = $this->user('nobranch@scope.test', $this->role('Branch2', DataScope::BRANCH));
        $me   = $this->employee('NB-1', $user, null, null, null);
        $this->employee('NB-2', null, null, null, null);

        $this->assertSame([(int) $me->id], $this->resolver->visibleEmployeeIds($user));
    }

    /* ── team ─────────────────────────────────────────────────────────── */

    public function test_team_includes_the_actor_and_reports_to_any_depth(): void
    {
        $user   = $this->user('team@scope.test', $this->role('Team', DataScope::TEAM));
        $me     = $this->employee('T-1', $user);
        $direct = $this->employee('T-2', null, null, $me->id);
        $skip   = $this->employee('T-3', null, null, $direct->id);   // reports to my report
        $away   = $this->employee('T-4');

        $ids = $this->resolver->visibleEmployeeIds($user);

        $this->assertContains((int) $me->id, $ids, 'A manager is part of their own team.');
        $this->assertContains((int) $direct->id, $ids);
        $this->assertContains((int) $skip->id, $ids, 'The hierarchy is walked to depth, not one level.');
        $this->assertNotContains((int) $away->id, $ids);
    }

    public function test_a_manager_with_no_reports_sees_only_themselves(): void
    {
        $user = $this->user('lonely@scope.test', $this->role('Team2', DataScope::TEAM));
        $me   = $this->employee('L-1', $user);
        $this->employee('L-2');

        $this->assertSame([(int) $me->id], $this->resolver->visibleEmployeeIds($user));
    }

    /**
     * A reporting line that loops must not hang the request. EmployeeService
     * rejects cycles on the way in, but the movement flow and pre-existing data
     * can still hold one.
     */
    public function test_a_circular_reporting_line_terminates(): void
    {
        $user = $this->user('cycle@scope.test', $this->role('Team3', DataScope::TEAM));
        $a    = $this->employee('C-1', $user);
        $b    = $this->employee('C-2', null, null, $a->id);
        $a->forceFill(['reporting_manager_id' => $b->id])->save();   // a → b → a

        $ids = $this->resolver->visibleEmployeeIds($user->fresh());

        $this->assertContains((int) $a->id, $ids);
        $this->assertContains((int) $b->id, $ids);
        $this->assertLessThanOrEqual(2, count($ids), 'And it must not loop forever building the list.');
    }

    /* ── tenant isolation ─────────────────────────────────────────────── */

    /**
     * Scope narrows WITHIN a tenant. It must never reach across one, and an
     * identical department id or branch name in another tenant is the obvious
     * way that could happen.
     */
    public function test_scope_never_crosses_a_tenant_boundary(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'scope-other', 'status' => 'active']);

        $ops  = $this->department('Ops');
        $user = $this->user('tenant@scope.test', $this->role('Dept3', DataScope::DEPARTMENT));
        $me   = $this->employee('X-1', $user, $ops->id, null, 'Pune');

        // Same department id, same branch name, different tenant.
        $foreign = $this->employee('X-2', null, $ops->id, null, 'Pune', $other);

        $ids = $this->resolver->visibleEmployeeIds($user);

        $this->assertContains((int) $me->id, $ids);
        $this->assertNotContains((int) $foreign->id, $ids,
            'Another tenant\'s employee must never appear, whatever their columns say.');
    }

    public function test_the_actors_own_employee_lookup_is_tenant_scoped(): void
    {
        $other = Tenant::create(['name' => 'Other2', 'slug' => 'scope-other2', 'status' => 'active']);
        $user  = $this->user('lookup@scope.test', $this->role('Own2', DataScope::OWN));

        // An employee row in ANOTHER tenant pointing at this user.
        $this->employee('Y-1', $user, null, null, null, $other);

        $this->assertNull($this->resolver->employeeFor($user),
            'A foreign-tenant employee row must not become this actor\'s identity.');
        $this->assertSame([], $this->resolver->visibleEmployeeIds($user));
    }

    /* ── unknown values and unsupported scopes ────────────────────────── */

    /**
     * A stored value this code does not recognise reads as global rather than
     * locking somebody out over a typo. The permission check has already run.
     */
    public function test_an_unrecognised_stored_scope_reads_as_global(): void
    {
        $role = $this->role('Odd', DataScope::GLOBAL);
        $role->forceFill(['scope' => 'whatever'])->save();

        $user = $this->user('odd@scope.test', $role);
        $this->employee('U-1', $user);

        $this->assertSame(DataScope::GLOBAL, $this->resolver->scopeFor($user->fresh()));
        $this->assertNull($this->resolver->visibleEmployeeIds($user->fresh()));
    }

    /**
     * A module that cannot honour a scope falls back to global for THAT module.
     * Approved for this phase and logged; the alternative is locking people out
     * of their job because a column has no data in it yet.
     */
    public function test_a_scope_the_module_cannot_honour_falls_back_to_global(): void
    {
        $user = $this->user('unsupported@scope.test', $this->role('Branch4', DataScope::BRANCH));
        $this->employee('S-1', $user, null, null, 'Pune');

        $this->assertNull(
            $this->resolver->visibleEmployeeIds($user, [DataScope::OWN, DataScope::DEPARTMENT, DataScope::TEAM]),
            'Branch is not in the supported list, so this module stays global.'
        );

        // …while a module that DOES declare it still narrows.
        $this->assertNotNull($this->resolver->visibleEmployeeIds($user, DataScope::ALL));
    }

    /** No actor at all — console commands, jobs — leaves the query untouched. */
    public function test_a_null_actor_leaves_the_query_alone(): void
    {
        $this->employee('Z-1');
        $this->employee('Z-2');

        $this->assertSame(2, $this->resolver->applyToQuery(HrEmployee::query(), null, 'id')->count());
    }
}
