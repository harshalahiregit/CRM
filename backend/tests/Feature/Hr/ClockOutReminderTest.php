<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrEmployee;
use App\Models\Notifications\HrNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Settings\SettingsService;
use App\Support\Hr\HrSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * "You are still clocked in" — the reminder the app has always offered a switch
 * for and nothing ever sent.
 *
 * A forgotten clock-out leaves the shift open, and the day then reads as absent
 * in payroll until somebody raises a correction to undo it. So the failure this
 * guards is not a missing nicety; it is a wrong payslip.
 */
class ClockOutReminderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 'clk-t', 'status' => 'active']);
    }

    private function person(string $code = 'E1', array $prefs = []): HrEmployee
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Emp '.$code, 'email' => strtolower($code).'@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
            'meta' => $prefs ? ['notification_prefs' => $prefs] : null,
        ]);

        return HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Emp '.$code, 'employee_code' => $code,
            'department' => 'Ops', 'designation' => 'Executive', 'status' => 'Active',
            'joining_date' => '2020-01-01', 'user_id' => $user->id,
        ]);
    }

    private function clockedInFor(HrEmployee $e, float $hours, bool $closed = false): HrAttendance
    {
        return HrAttendance::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
            'date' => now()->toDateString(),
            'check_in' => now()->subMinutes((int) round($hours * 60)),
            'check_out' => $closed ? now() : null,
            'status' => 'Present',
        ]);
    }

    private function reminders(): int
    {
        return HrNotification::where('module', 'Attendance')->where('event', 'Clock-out reminder')->count();
    }

    public function test_someone_past_the_threshold_is_reminded(): void
    {
        $this->clockedInFor($this->person(), 11);

        $this->artisan('hr:clock-out-reminders')->assertSuccessful();

        $this->assertSame(1, $this->reminders());
    }

    public function test_someone_still_inside_a_normal_day_is_left_alone(): void
    {
        $this->clockedInFor($this->person(), 6);

        $this->artisan('hr:clock-out-reminders');

        $this->assertSame(0, $this->reminders());
    }

    public function test_someone_who_already_clocked_out_is_left_alone(): void
    {
        $this->clockedInFor($this->person(), 11, closed: true);

        $this->artisan('hr:clock-out-reminders');

        $this->assertSame(0, $this->reminders());
    }

    public function test_nobody_is_reminded_twice_in_a_day(): void
    {
        $this->clockedInFor($this->person(), 11);

        // Runs hourly; a twelve-hour day would otherwise be three messages.
        $this->artisan('hr:clock-out-reminders');
        $this->artisan('hr:clock-out-reminders');
        $this->artisan('hr:clock-out-reminders');

        $this->assertSame(1, $this->reminders());
    }

    public function test_a_person_who_switched_it_off_is_not_reminded(): void
    {
        $this->clockedInFor($this->person('E1', ['notify_clock_reminder' => false]), 11);

        // Their own switch wins over the workspace setting.
        $this->artisan('hr:clock-out-reminders');

        $this->assertSame(0, $this->reminders());
    }

    public function test_the_workspace_can_turn_it_off_entirely(): void
    {
        $this->clockedInFor($this->person(), 11);
        app(SettingsService::class)->set($this->tenant->id, HrSetting::GROUP, 'clock_out_reminder_enabled', false);

        $this->artisan('hr:clock-out-reminders');

        $this->assertSame(0, $this->reminders());
    }

    public function test_the_threshold_is_a_setting_not_a_constant(): void
    {
        $this->clockedInFor($this->person(), 7);
        app(SettingsService::class)->set($this->tenant->id, HrSetting::GROUP, 'clock_out_reminder_after_hours', 6);

        // A factory running 12-hour shifts and an office running 8 need
        // different numbers, and neither should be a code change.
        $this->artisan('hr:clock-out-reminders');

        $this->assertSame(1, $this->reminders());
    }

    public function test_an_unclosed_shift_from_a_previous_day_is_not_nagged_about(): void
    {
        $e = $this->person();
        HrAttendance::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
            'date' => now()->subDays(4)->toDateString(),
            'check_in' => now()->subDays(4), 'check_out' => null, 'status' => 'Present',
        ]);

        // That is a forgotten row, not somebody at their desk. Reminding them
        // hourly forever is how people mute the app.
        $this->artisan('hr:clock-out-reminders');

        $this->assertSame(0, $this->reminders());
    }

    public function test_the_message_says_how_long_they_have_been_in(): void
    {
        $this->clockedInFor($this->person(), 11);

        $this->artisan('hr:clock-out-reminders');

        $this->assertStringContainsString('11 hours', HrNotification::first()->message);
    }
}
