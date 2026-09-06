<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\EmployeeIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Clocking yourself in.
 *
 * The existing attendance endpoints require canManageHrQueue and an employee_id —
 * they exist to record attendance FOR somebody. So a person could not clock
 * themselves in without HR-admin rights over the whole company.
 *
 * The security property of the self-service endpoints is structural: there is no
 * employee_id to send. The tests below try to send one anyway.
 */
class SelfAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $t = null;

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'T', 'slug' => 'self-t', 'status' => 'active']);
    }

    /** A person with a login AND a linked employee record. */
    private function person(string $code, string $email): array
    {
        $employee = HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'employee_code' => $code,
            'name' => 'Person ' . $code, 'email' => $email,
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => now()->subYear()->toDateString(), 'status' => 'Active',
        ]);

        $user = app(EmployeeIdentityService::class)->provision($employee)['user'];

        return [$user, $employee->fresh()];
    }

    public function test_an_employee_can_clock_themselves_in_and_out(): void
    {
        [$user, $employee] = $this->person('SNE-1', 'priya@example.test');
        Sanctum::actingAs($user);

        $this->postJson('/api/hr/me/attendance/check-in')->assertOk();

        $record = HrAttendance::where('employee_id', $employee->id)->first();
        $this->assertNotNull($record, 'Check-in must create a record for the caller.');
        $this->assertNotNull($record->check_in);

        $this->postJson('/api/hr/me/attendance/check-out')->assertOk();
        $this->assertNotNull($record->fresh()->check_out);
    }

    /** No HR rights involved — that is the whole point. */
    public function test_it_does_not_require_hr_admin_rights(): void
    {
        [$user] = $this->person('SNE-1', 'priya@example.test');

        $this->assertFalse(
            $user->canManageHrQueue(),
            'This fixture must NOT have HR rights, or the test proves nothing.'
        );

        Sanctum::actingAs($user);
        $this->postJson('/api/hr/me/attendance/check-in')->assertOk();
    }

    /**
     * The one that matters: sending somebody else's employee_id must not move
     * their record. There is no such parameter, so it is ignored.
     */
    public function test_it_cannot_be_pointed_at_another_employee(): void
    {
        [$mineUser, $mine] = $this->person('SNE-1', 'priya@example.test');
        [, $someoneElse]   = $this->person('SNE-2', 'raj@example.test');

        Sanctum::actingAs($mineUser);
        $this->postJson('/api/hr/me/attendance/check-in', [
            'employee_id' => $someoneElse->id,
        ])->assertOk();

        $this->assertSame(0, HrAttendance::where('employee_id', $someoneElse->id)->count(),
            'A forged employee_id must never touch another person\'s attendance.');
        $this->assertSame(1, HrAttendance::where('employee_id', $mine->id)->count(),
            'The caller\'s own record is the one that moved.');
    }

    public function test_a_login_with_no_employee_record_is_told_why(): void
    {
        $lone = User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Admin', 'email' => 'admin@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'admin', 'status' => 'active',
        ]);

        Sanctum::actingAs($lone);

        $this->postJson('/api/hr/me/attendance/check-in')
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Your login is not linked to an employee record, so attendance cannot be recorded against it. Contact HR.']);
    }

    public function test_today_reports_what_the_caller_can_do_next(): void
    {
        [$user] = $this->person('SNE-1', 'priya@example.test');
        Sanctum::actingAs($user);

        $before = $this->getJson('/api/hr/me/attendance/today')->assertOk()->json('data.can');
        $this->assertTrue($before['check_in']);
        $this->assertFalse($before['check_out']);

        $this->postJson('/api/hr/me/attendance/check-in')->assertOk();

        $after = $this->getJson('/api/hr/me/attendance/today')->assertOk()->json('data.can');
        $this->assertFalse($after['check_in'], 'Already clocked in.');
        $this->assertTrue($after['check_out']);
        $this->assertTrue($after['break_start']);
    }

    public function test_breaks_go_through_the_same_service(): void
    {
        [$user, $employee] = $this->person('SNE-1', 'priya@example.test');
        Sanctum::actingAs($user);

        $this->postJson('/api/hr/me/attendance/check-in')->assertOk();
        $this->postJson('/api/hr/me/attendance/break-start')->assertOk();
        $this->assertNotNull(HrAttendance::where('employee_id', $employee->id)->first()->break_start);

        $this->postJson('/api/hr/me/attendance/break-end')->assertOk();
        $this->assertNotNull(HrAttendance::where('employee_id', $employee->id)->first()->break_end);
    }
}
