<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Creating employee records for logins that have none.
 *
 * hr:reconcile-logins used to only pair by email, which needs an employee row to
 * already exist. Every account that predates HR — the founding admin above all —
 * has none, so nothing paired and "even the admin is an employee" quietly stayed
 * false. That is what this second pass fixes, and it is the reason a real admin
 * account could not clock in.
 */
class ReconcileLoginsProvisionTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $t = null;

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'T', 'slug' => 'rec-t', 'status' => 'active']);
    }

    private function user(string $role, string $email): User
    {
        return User::create([
            'tenant_id' => $this->tenant()->id, 'name' => ucfirst($role), 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $role, 'status' => 'active',
        ]);
    }

    public function test_it_reports_missing_records_without_writing_anything(): void
    {
        $this->user('admin', 'admin@example.test');

        $this->artisan('hr:reconcile-logins')
            ->expectsOutputToContain('1 login(s) have no employee record.')
            ->assertSuccessful();

        $this->assertSame(0, HrEmployee::count(), 'A dry run must not create anything.');
    }

    public function test_provision_and_commit_creates_them(): void
    {
        $admin = $this->user('admin', 'admin@example.test');
        $staff = $this->user('staff', 'priya@example.test');

        $this->artisan('hr:reconcile-logins --provision --commit')->assertSuccessful();

        $this->assertSame(2, HrEmployee::count());
        // The requirement this exists for: the admin is an employee too.
        $this->assertNotNull(HrEmployee::where('user_id', $admin->id)->first());
        $this->assertNotNull(HrEmployee::where('user_id', $staff->id)->first());
    }

    /** Both columns are NOT NULL, so a placeholder beats a record that cannot save. */
    public function test_department_and_designation_fall_back_rather_than_block(): void
    {
        $admin = $this->user('admin', 'admin@example.test');

        $this->artisan('hr:reconcile-logins --provision --commit')->assertSuccessful();

        $e = HrEmployee::where('user_id', $admin->id)->firstOrFail();
        $this->assertSame('Unassigned', $e->department);
        $this->assertSame('Unassigned', $e->designation);
    }

    public function test_commit_without_provision_creates_nothing(): void
    {
        $this->user('admin', 'admin@example.test');

        $this->artisan('hr:reconcile-logins --commit')->assertSuccessful();

        $this->assertSame(0, HrEmployee::count(), 'Creating records needs its own flag.');
    }

    public function test_running_it_twice_does_not_duplicate(): void
    {
        $this->user('admin', 'admin@example.test');

        $this->artisan('hr:reconcile-logins --provision --commit')->assertSuccessful();
        $this->artisan('hr:reconcile-logins --provision --commit')->assertSuccessful();

        $this->assertSame(1, HrEmployee::count());
    }

    public function test_clients_and_vendors_are_not_given_employee_records(): void
    {
        $this->user('client', 'client@example.test');
        $this->user('vendor', 'vendor@example.test');

        $this->artisan('hr:reconcile-logins --provision --commit')->assertSuccessful();

        $this->assertSame(0, HrEmployee::count(), 'A portal identity is not a member of staff.');
    }

    public function test_an_existing_employee_is_linked_rather_than_duplicated(): void
    {
        $user = $this->user('staff', 'priya@example.test');

        HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'employee_code' => 'SNE-9',
            'name' => 'Priya', 'email' => 'priya@example.test',
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => now()->toDateString(), 'status' => 'Active',
        ]);

        $this->artisan('hr:reconcile-logins --provision --commit')->assertSuccessful();

        $this->assertSame(1, HrEmployee::count(), 'Pairing must win over creating a second record.');
        $this->assertSame($user->id, HrEmployee::first()->user_id);
    }

    public function test_it_can_be_restricted_to_one_tenant(): void
    {
        $this->user('admin', 'admin@example.test');

        $other = Tenant::create(['name' => 'O', 'slug' => 'rec-o', 'status' => 'active']);
        User::create([
            'tenant_id' => $other->id, 'name' => 'Other', 'email' => 'other@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'admin', 'status' => 'active',
        ]);

        $this->artisan("hr:reconcile-logins --provision --commit --tenant={$this->tenant()->id}")->assertSuccessful();

        $this->assertSame(1, HrEmployee::count());
        $this->assertSame($this->tenant()->id, HrEmployee::first()->tenant_id);
    }
}
