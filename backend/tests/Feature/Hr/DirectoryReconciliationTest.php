<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Keeping the staff and employee directories in step.
 *
 * The instruction was one directory, not two. They are not merged here, and the
 * reason is in DirectoryReconciliationService: they are not duplicates. `users`
 * is a login account, `hr_employees` is an employment record, neither contains
 * the other, and Tasks, Helpdesk and ticket threads all resolve their
 * assignable-people lists from the staff side.
 *
 * The complaint was never that two tables exist — it was "पता चला किसी को ऐड
 * करना था, एम्प्लई ने ऐड कर दिया, स्टाफ ने नहीं": somebody added in one place
 * and missing from the other, discovered when they are left off a payroll run.
 * That is what these tests cover.
 */
class DirectoryReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'directory', 'status' => 'active']);
        $this->tenantId = $tenant->id;

        Sanctum::actingAs($this->user('admin@dir.test', 'admin'));
    }

    private function user(string $email, string $role = 'staff'): User
    {
        return User::create([
            'tenant_id' => $this->tenantId, 'name' => explode('@', $email)[0], 'email' => $email,
            'password' => Hash::make('x'), 'role' => $role, 'status' => 'active',
        ]);
    }

    private function employee(string $code, ?User $user = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenantId, 'employee_code' => $code, 'name' => "Person {$code}",
            'department' => 'Ops', 'designation' => 'Staff',
            'joining_date' => '2020-01-01', 'status' => 'Active',
            'user_id' => $user?->id,
        ]);
    }

    private function report(): array
    {
        return $this->getJson('/api/hr/directory/reconciliation')->assertOk()->json('data');
    }

    public function test_it_reports_who_is_on_one_side_and_not_the_other(): void
    {
        $linked = $this->user('linked@dir.test');
        $this->employee('SD01', $linked);          // both sides
        $this->employee('SD02');                    // employed, no login
        $this->user('nologin@dir.test');            // login, not employed

        $r = $this->report();

        // The acting admin is also a user with no employee record.
        $this->assertSame(2, $r['summary']['employees']);
        $this->assertSame(1, $r['summary']['linked']);
        $this->assertSame(1, $r['summary']['without_login']);
        $this->assertSame(2, $r['summary']['without_employee'], 'the admin and the unlinked user');
    }

    /** The gap is named, not just counted — a count cannot be acted on. */
    public function test_the_people_in_each_gap_are_named(): void
    {
        $this->employee('SD10');
        $this->user('orphan@dir.test');

        $r = $this->report();

        $this->assertSame('Person SD10', $r['without_login'][0]['name']);
        $this->assertSame('SD10', $r['without_login'][0]['employee_code']);
        $this->assertContains('orphan@dir.test', array_column($r['without_employee'], 'email'));
    }

    public function test_an_employee_can_be_linked_to_an_existing_login(): void
    {
        $e = $this->employee('SD20');
        $u = $this->user('joiner@dir.test');

        $this->postJson("/api/hr/employees/{$e->id}/link-login", ['user_id' => $u->id])->assertOk();

        $this->assertSame($u->id, $e->fresh()->user_id);
        $this->assertSame(0, $this->report()['summary']['without_login']);
    }

    /**
     * One login cannot belong to two employees.
     *
     * Without this, two employment records point at the same account and every
     * "who is this?" lookup — payslip, leave balance, attendance — gets whichever
     * row the query happened to return first.
     */
    public function test_a_login_cannot_be_linked_to_two_employees(): void
    {
        $u = $this->user('shared@dir.test');
        $first = $this->employee('SD30', $u);
        $second = $this->employee('SD31');

        $this->postJson("/api/hr/employees/{$second->id}/link-login", ['user_id' => $u->id])
            ->assertStatus(422)
            ->assertSee('already linked to '.$first->name, escape: false);

        $this->assertNull($second->fresh()->user_id);
    }

    public function test_another_tenants_records_cannot_be_linked(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'directory-2', 'status' => 'active']);
        $theirUser = User::create([
            'tenant_id' => $other->id, 'name' => 'Theirs', 'email' => 't@other.test',
            'password' => Hash::make('x'), 'role' => 'staff', 'status' => 'active',
        ]);

        $mine = $this->employee('SD40');

        $this->postJson("/api/hr/employees/{$mine->id}/link-login", ['user_id' => $theirUser->id])
            ->assertStatus(404);
    }

    public function test_a_staff_user_cannot_rewire_the_directory(): void
    {
        $e = $this->employee('SD50');
        $u = $this->user('someone@dir.test');

        Sanctum::actingAs($this->user('plain@dir.test', 'staff'));

        $this->postJson("/api/hr/employees/{$e->id}/link-login", ['user_id' => $u->id])->assertStatus(403);
    }
}
