<?php

namespace Tests\Feature\Auth;

use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Staff Management creates the PERSON — both rows, together.
 *
 * The old CRM's tblstaff simply is the person: payroll, contracts and timesheets
 * hang off staff_id and the HR module creates nobody. Sangoe keeps two tables
 * because hr_employees carries probation, shift and salary that a login has no
 * business holding — but they have to behave as one record.
 *
 * Before this, creating a staff member produced a login that no HR screen could
 * see and that could not clock in, which is the state every admin account is in.
 */
class StaffCreatesEmployeeTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $t = null;

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'T', 'slug' => 'sce-t', 'status' => 'active']);
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Admin', 'email' => 'admin@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'admin', 'status' => 'active',
            'internal_role' => 'general',
        ]);
    }

    private function create(array $overrides = [])
    {
        return $this->postJson('/api/admin/staff', array_merge([
            'name' => 'Priya Sharma', 'email' => 'priya@example.test',
            'password' => 'Password123!', 'internal_role' => 'general',
            'department' => 'Finance', 'designation' => 'Analyst',
            'phone' => '9999999999', 'status' => 'active',
        ], $overrides));
    }

    public function test_creating_a_staff_member_creates_their_employee_record(): void
    {
        Sanctum::actingAs($this->admin());

        $res = $this->create()->assertCreated();

        $user = User::where('email', 'priya@example.test')->firstOrFail();
        $employee = HrEmployee::where('user_id', $user->id)->first();

        $this->assertNotNull($employee, 'A login must not exist without an employee record.');
        $this->assertSame($user->tenant_id, $employee->tenant_id);
        $this->assertSame('Priya Sharma', $employee->name);
        $this->assertSame('Finance', $employee->department);
        $this->assertSame('Analyst', $employee->designation);
        $this->assertNotEmpty($employee->employee_code);

        $res->assertJsonPath('employee.employee_code', $employee->employee_code);
    }

    /** An admin is an employee too — the whole point of the change. */
    public function test_an_admin_created_here_also_gets_an_employee_record(): void
    {
        Sanctum::actingAs($this->admin());

        $this->create(['email' => 'newadmin@example.test', 'administrator' => true])->assertCreated();

        $user = User::where('email', 'newadmin@example.test')->firstOrFail();
        $this->assertSame('admin', $user->role);
        $this->assertNotNull(HrEmployee::where('user_id', $user->id)->first(),
            'An admin must be an employee too, or they cannot clock in.');
    }

    /** An existing employee is LINKED, never duplicated. */
    public function test_it_links_an_existing_employee_rather_than_duplicating(): void
    {
        $t = $this->tenant();
        $existing = HrEmployee::create([
            'tenant_id' => $t->id, 'employee_code' => 'SNE-EXISTING',
            'name' => 'Priya Sharma', 'email' => 'priya@example.test',
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => now()->subYears(2)->toDateString(), 'status' => 'Active',
        ]);

        Sanctum::actingAs($this->admin());
        $this->create()->assertCreated();

        $user = User::where('email', 'priya@example.test')->firstOrFail();

        $this->assertSame(1, HrEmployee::where('email', 'priya@example.test')->count(),
            'A second employee record must never be created for the same person.');
        $this->assertSame($user->id, $existing->fresh()->user_id);
        $this->assertSame('SNE-EXISTING', $existing->fresh()->employee_code, 'The original record is kept.');
    }

    /** Codes come from the shared allocator and do not collide. */
    public function test_each_new_person_gets_their_own_code(): void
    {
        Sanctum::actingAs($this->admin());

        $this->create(['email' => 'a@example.test'])->assertCreated();
        $this->create(['email' => 'b@example.test'])->assertCreated();

        $codes = HrEmployee::pluck('employee_code');
        $this->assertSame($codes->count(), $codes->unique()->count(), 'Employee codes must be unique.');
    }

    /** Missing department and designation must not block creation. */
    public function test_it_copes_without_a_department_or_designation(): void
    {
        Sanctum::actingAs($this->admin());

        $this->create(['email' => 'bare@example.test', 'department' => null, 'designation' => null])
            ->assertCreated();

        $user = User::where('email', 'bare@example.test')->firstOrFail();
        $employee = HrEmployee::where('user_id', $user->id)->first();

        $this->assertNotNull($employee);
        $this->assertSame('Unassigned', $employee->department);
        $this->assertSame('Unassigned', $employee->designation);
    }

    /** Both rows or neither: a failed employee write must not leave a stray login. */
    public function test_the_two_rows_are_written_together(): void
    {
        Sanctum::actingAs($this->admin());
        $this->create()->assertCreated();

        $users = User::where('role', '!=', 'admin')->count();
        $employees = HrEmployee::whereNotNull('user_id')->count();

        $this->assertSame($users, $employees, 'Every non-admin login created here has an employee record.');
    }
}
