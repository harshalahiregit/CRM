<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\AttendanceService;
use App\Services\Settings\SettingsService;
use App\Support\Hr\HrSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The HR settings actually reaching an attendance record.
 *
 * They did not. Start time, end time, grace period and full-day hours were on
 * the settings screen, saved, persisted across a reload — and NOTHING read them.
 * Every record was stamped 09:00/18:00/15 from a constant, and overtime accrued
 * past 8 hours while the screen said 9. The hint under the grace field read
 * "Minutes after the start time before a clock-in is marked Late", which was
 * simply false.
 *
 * Found by driving the UI: change a setting, clock in, look at what was stored.
 */
class AttendanceHonoursSettingsTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $t = null;

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'S', 'slug' => 'set-att', 'status' => 'active']);
    }

    private function person(): array
    {
        $user = User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Priya', 'email' => 'priya@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);
        $employee = HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'employee_code' => 'SNE-1', 'name' => 'Priya',
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2020-01-01',
            'status' => 'Active', 'user_id' => $user->id, 'app_login_enabled' => true,
        ]);
        Sanctum::actingAs($user);

        return [$user, $employee];
    }

    private function set(array $values): void
    {
        app(SettingsService::class)->setGroup($this->tenant()->id, HrSetting::GROUP, $values);
    }

    public function test_a_clock_in_is_stamped_with_the_configured_working_day(): void
    {
        [, $e] = $this->person();

        $this->set([
            'company_start_time' => '09:30',
            'company_end_time'   => '18:30',
            'late_grace_minutes' => 20,
        ]);

        $this->postJson('/api/Hrm/clock-in-out', ['type' => 'clockin'])->assertOk();

        $day = HrAttendance::where('employee_id', $e->id)->firstOrFail();

        $this->assertSame('09:30', substr((string) $day->shift_start, 0, 5));
        $this->assertSame('18:30', substr((string) $day->shift_end, 0, 5));
        $this->assertSame(20, (int) $day->grace_period);
    }

    /** Untouched settings must behave exactly as the constants always did. */
    public function test_defaults_still_give_the_old_behaviour(): void
    {
        [, $e] = $this->person();

        $this->postJson('/api/Hrm/clock-in-out', ['type' => 'clockin'])->assertOk();

        $day = HrAttendance::where('employee_id', $e->id)->firstOrFail();

        $this->assertSame('09:00', substr((string) $day->shift_start, 0, 5));
        $this->assertSame(15, (int) $day->grace_period);
    }

    /** Overtime past the configured full day, not past a hardcoded 8. */
    public function test_overtime_uses_the_configured_full_day(): void
    {
        [, $e] = $this->person();
        $this->set(['standard_day_hours' => 9]);

        $day = HrAttendance::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $e->id,
            'date' => now()->toDateString(),
            'check_in' => now()->setTime(9, 0), 'check_out' => now()->setTime(18, 30),
            'status' => 'Present',
        ]);

        $day = app(AttendanceService::class)->restampAndSave($day);

        // 9.5 worked, 9 standard → 0.5 overtime. Against the old constant it
        // would have been 1.5, paying an hour that was never worked.
        $this->assertEqualsWithDelta(9.5, (float) $day->working_hours, 0.05);
        $this->assertEqualsWithDelta(0.5, (float) $day->overtime_hours, 0.05);
    }

    public function test_settings_are_per_tenant(): void
    {
        [, $e] = $this->person();
        $this->set(['late_grace_minutes' => 20]);

        $other = Tenant::create(['name' => 'O', 'slug' => 'set-att-o', 'status' => 'active']);
        $otherUser = User::create([
            'tenant_id' => $other->id, 'name' => 'X', 'email' => 'x@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);
        $otherEmp = HrEmployee::create([
            'tenant_id' => $other->id, 'employee_code' => 'X-1', 'name' => 'X',
            'department' => 'Ops', 'designation' => 'A', 'joining_date' => '2020-01-01',
            'status' => 'Active', 'user_id' => $otherUser->id, 'app_login_enabled' => true,
        ]);

        Sanctum::actingAs($otherUser);
        $this->postJson('/api/Hrm/clock-in-out', ['type' => 'clockin'])->assertOk();

        $day = HrAttendance::where('employee_id', $otherEmp->id)->firstOrFail();
        $this->assertSame(15, (int) $day->grace_period, 'Another tenant\'s setting must not leak.');
    }
}
