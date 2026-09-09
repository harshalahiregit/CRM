<?php

namespace Tests\Feature\Hrm;

use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The app's daily path against the CRM.
 *
 * These assert the CONTRACT the Dart models expect, key by key and type by
 * type, because a key the app does not recognise renders blank rather than
 * failing — the app would look like it was losing data, with nothing in a log.
 *
 * Two of their quirks are pinned deliberately: `attendence_id` is misspelled on
 * clock-in-out and break-toggle while `home` uses `attendance_id`, and
 * `is_clockin` is an int while `is_on_break` is a bool in the same payload.
 */
class HrmAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $t = null;

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'S', 'slug' => 'hrma-t', 'status' => 'active']);
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

    /* ── home ────────────────────────────────────────────────────────── */

    public function test_home_returns_every_key_the_model_reads_with_the_right_types(): void
    {
        $this->person();

        $r = $this->postJson('/api/Hrm/home', ['workspace_id' => $this->tenant()->id])->assertOk();

        $this->assertSame(1, $r->json('status'));

        foreach (['is_clockin', 'attendance_id', 'clock_in', 'clock_out', 'total_hours', 'is_on_break', 'announcements'] as $k) {
            $this->assertArrayHasKey($k, $r->json('data'), "data.{$k} is missing — the app renders it blank.");
        }

        // int? isClockin — not a bool.
        $this->assertIsInt($r->json('data.is_clockin'));
        // bool? isOnBreak — not an int. Theirs, in the same payload.
        $this->assertIsBool($r->json('data.is_on_break'));
        // String? — the app shows "--" for an empty one, and crashes on a number.
        $this->assertIsString($r->json('data.clock_in'));
        $this->assertIsString($r->json('data.total_hours'));
        // The app iterates this without a null guard.
        $this->assertIsArray($r->json('data.announcements'));
    }

    /**
     * The home screen shows announcements somebody wrote, not the holiday list.
     *
     * It used to return upcoming holidays, because when that was written there
     * were no announcements to return. There are now, and a section headed
     * "Announcements" showing the holiday calendar was both wrong and redundant —
     * holidays have their own screen with a month view.
     */
    public function test_announcements_carry_every_field_their_model_reads(): void
    {
        [$user, $employee] = $this->person();

        \App\Models\Notification::create([
            'tenant_id' => $this->tenant()->id,
            'user_id'   => $user->id,
            'type'      => 'announcement',
            'title'     => 'Office closed Friday',
            'message'   => 'Diwali. Back on Monday.',
        ]);

        $a = $this->postJson('/api/Hrm/home', [])->assertOk()->json('data.announcements.0');

        foreach (['id', 'title', 'start_date', 'end_date', 'description', 'workspace', 'created_by'] as $k) {
            $this->assertArrayHasKey($k, $a, "announcement.{$k} is missing.");
        }

        $this->assertSame('Office closed Friday', $a['title']);
        // Drives the "n new" count on the home screen, which used to say "New"
        // whether or not anything was.
        $this->assertArrayHasKey('is_read', $a);
        $this->assertArrayHasKey('attachments', $a);
    }

    /** An announcement for one department must not appear on everyone's home screen. */
    public function test_only_this_persons_announcements_are_shown(): void
    {
        [$user] = $this->person();

        $other = \App\Models\User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Someone Else',
            'email' => 'other'.uniqid().'@example.test', 'password' => bcrypt('x'),
            'role' => 'staff', 'status' => 'active',
        ]);

        \App\Models\Notification::create([
            'tenant_id' => $this->tenant()->id, 'user_id' => $other->id,
            'type' => 'announcement', 'title' => 'Not for you', 'message' => 'Ops only.',
        ]);

        $titles = collect($this->postJson('/api/Hrm/home', [])->assertOk()->json('data.announcements'))
            ->pluck('title');

        $this->assertNotContains('Not for you', $titles);
    }

    /* ── clocking ────────────────────────────────────────────────────── */

    public function test_clock_in_returns_their_misspelled_key(): void
    {
        Storage::fake('local');
        $this->person();

        $r = $this->postJson('/api/Hrm/clock-in-out', [
            'workspace_id' => $this->tenant()->id, 'type' => 'clockin',
            'latitude' => '18.52', 'longitude' => '73.85',
        ])->assertOk();

        $this->assertSame(1, $r->json('status'));

        // attendence_id, not attendance_id. Correcting the spelling breaks the app.
        foreach (['is_clockin', 'clock_in', 'clock_out', 'total_hours', 'attendence_id', 'attendence_clock_in'] as $k) {
            $this->assertArrayHasKey($k, $r->json('data'), "data.{$k} is missing.");
        }

        $this->assertSame(1, $r->json('data.is_clockin'));
        $this->assertNotEmpty($r->json('data.clock_in'));
    }

    public function test_the_whole_day_clock_in_break_and_out(): void
    {
        Storage::fake('local');
        [, $employee] = $this->person();

        // A real shift, not four calls in the same microsecond. recompute()
        // needs the clock-out to be strictly later than the clock-in, so an
        // instantaneous day legitimately produces no hours — which would make
        // this test assert something no real day does.
        $this->postJson('/api/Hrm/clock-in-out', ['type' => 'clockin'])->assertOk();

        $this->travel(2)->hours();
        $this->postJson('/api/Hrm/break-toggle', ['type' => 'start'])
            ->assertOk()->assertJsonPath('data.is_on_break', true);

        $this->travel(30)->minutes();
        $this->postJson('/api/Hrm/break-toggle', ['type' => 'continue'])
            ->assertOk()->assertJsonPath('data.is_on_break', false);

        $this->travel(5)->hours();
        $r = $this->postJson('/api/Hrm/clock-in-out', ['type' => 'clockout'])->assertOk();
        $this->travelBack();

        $this->assertSame(0, $r->json('data.is_clockin'));
        $this->assertNotEmpty($r->json('data.clock_out'));

        $day = HrAttendance::where('employee_id', $employee->id)->firstOrFail();
        $this->assertNotNull($day->check_in);
        $this->assertNotNull($day->check_out);
        // 7h30m elapsed, less the 30-minute break.
        $this->assertNotNull($day->working_hours, 'Hours must be computed, as they are for a CRM clock-out.');
        $this->assertEqualsWithDelta(7.0, (float) $day->working_hours, 0.05, 'The break must be deducted.');
    }

    public function test_a_selfie_is_stored_when_sent(): void
    {
        Storage::fake('local');
        $this->person();

        $this->postJson('/api/Hrm/clock-in-out', [
            'type' => 'clockin', 'selfie' => UploadedFile::fake()->image('me.jpg'),
        ])->assertOk()->assertJsonPath('status', 1);

        $this->assertNotEmpty(Storage::disk('local')->allFiles(), 'The selfie was not stored.');
    }

    /** A punch must never be lost because a camera failed. */
    public function test_clocking_works_without_a_selfie(): void
    {
        Storage::fake('local');
        $this->person();

        $this->postJson('/api/Hrm/clock-in-out', ['type' => 'clockin'])
            ->assertOk()->assertJsonPath('status', 1);
    }

    /* ── refusals are 200 with status 0 ──────────────────────────────── */

    public function test_double_clock_in_is_refused_without_an_http_error(): void
    {
        Storage::fake('local');
        $this->person();

        $this->postJson('/api/Hrm/clock-in-out', ['type' => 'clockin'])->assertOk();

        $r = $this->postJson('/api/Hrm/clock-in-out', ['type' => 'clockin'])->assertOk();

        $this->assertSame(0, $r->json('status'));
        $this->assertStringContainsString('already clocked in', (string) $r->json('message'));
    }

    /**
     * A night shift crosses midnight, and the record stays filed under the day it
     * began. Looked up strictly by today's date, clocking out at 02:00 answered
     * "You have not clocked in today" and there was no way to close the day.
     */
    public function test_a_shift_that_began_before_midnight_can_still_be_clocked_out(): void
    {
        [, $employee] = $this->person();

        $this->travelTo(now()->setTime(22, 0));
        $this->postJson('/api/Hrm/clock-in-out', ['type' => 'clockin'])->assertOk();

        // Past midnight — a different calendar day from the one that was started.
        $this->travel(4)->hours();
        $r = $this->postJson('/api/Hrm/clock-in-out', ['type' => 'clockout'])->assertOk();

        $this->assertSame(1, $r->json('status'), (string) $r->json('message'));
        $this->assertSame(0, $r->json('data.is_clockin'));
        $this->travelBack();

        $day = HrAttendance::where('employee_id', $employee->id)->firstOrFail();
        $this->assertNotNull($day->check_out, 'The overnight shift was never closed.');
        $this->assertTrue($day->check_out->gt($day->check_in));
    }

    /**
     * The same for a break, which had no such fallback.
     *
     * A night-shift worker clocking in at 22:00 and taking their break at 00:30
     * was told "You need to be clocked in to take a break" — the record exists,
     * filed under yesterday. Note the FIXED start time: the day-long test above
     * clocks in at whatever time it happens to run, so this only failed when the
     * suite ran late in the evening, which is a bug that hides for weeks.
     */
    public function test_a_break_can_be_taken_after_midnight_on_a_night_shift(): void
    {
        $this->person();

        $this->travelTo(now()->setTime(22, 0));
        $this->postJson('/api/Hrm/clock-in-out', ['type' => 'clockin'])->assertOk();

        $this->travel(150)->minutes();   // 00:30 — the next calendar day
        $this->postJson('/api/Hrm/break-toggle', ['type' => 'start'])
            ->assertOk()->assertJsonPath('data.is_on_break', true);

        $this->travel(30)->minutes();
        $this->postJson('/api/Hrm/break-toggle', ['type' => 'continue'])
            ->assertOk()->assertJsonPath('data.is_on_break', false);

        $this->travelBack();
    }

    /**
     * A day that was never clocked out has no total.
     *
     * The running "time so far" is right for TODAY — in at 09:00, it is 14:00,
     * show 5:00. Left unbounded it kept counting: an open shift from four days
     * ago reported 91:43, which on the history screen reads as ninety-one hours
     * worked that day, beside a card header saying 00:00 for the same day.
     */
    public function test_an_old_open_shift_reports_no_total_rather_than_a_running_clock(): void
    {
        [, $employee] = $this->person();

        // Clocked in four days ago and never out.
        HrAttendance::create([
            'tenant_id' => $employee->tenant_id, 'employee_id' => $employee->id,
            'date' => now()->subDays(4)->toDateString(),
            'check_in' => now()->subDays(4)->setTime(9, 0),
            'status' => 'Present',
        ]);

        $r = $this->postJson('/api/Hrm/attendence-history', [
            'month' => (int) now()->subDays(4)->format('m'),
            'year'  => (int) now()->subDays(4)->format('Y'),
        ])->assertOk();

        $day = collect($r->json('data'))
            ->firstWhere('date', now()->subDays(4)->toDateString());

        $this->assertNotNull($day, 'The day should still be listed.');
        $this->assertSame('', $day['history'][0]['total'],
            'A day nobody clocked out of has no total — a number here is a lie.');
    }

    /** Today, still clocked in, DOES show the time so far. */
    public function test_todays_open_shift_still_shows_the_time_so_far(): void
    {
        [, $employee] = $this->person();

        HrAttendance::create([
            'tenant_id' => $employee->tenant_id, 'employee_id' => $employee->id,
            'date' => now()->toDateString(),
            'check_in' => now()->subHours(3),
            'status' => 'Present',
        ]);

        $r = $this->postJson('/api/Hrm/attendence-history')->assertOk();

        $day = collect($r->json('data'))->firstWhere('date', now()->toDateString());

        $this->assertNotSame('', $day['history'][0]['total'],
            'Somebody clocked in this morning wants to see their hours so far.');
    }

    public function test_clocking_out_without_clocking_in_is_refused(): void
    {
        $this->person();

        $r = $this->postJson('/api/Hrm/clock-in-out', ['type' => 'clockout'])->assertOk();
        $this->assertSame(0, $r->json('status'));
    }

    public function test_a_break_before_clocking_in_is_refused(): void
    {
        $this->person();

        $r = $this->postJson('/api/Hrm/break-toggle', ['type' => 'start'])->assertOk();
        $this->assertSame(0, $r->json('status'));
    }

    /* ── history ─────────────────────────────────────────────────────── */

    public function test_history_returns_their_shape(): void
    {
        [, $employee] = $this->person();

        HrAttendance::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $employee->id, 'date' => '2026-03-02',
            // Stored UTC, which is 09:00-18:00 on the workspace's Asia/Kolkata
            // clock. The app prints these strings verbatim, so it must receive
            // the local wall time — sending the raw UTC column showed a 9am
            // arrival as 03:30.
            'check_in' => '2026-03-02 03:30:00', 'check_out' => '2026-03-02 12:30:00',
            'working_hours' => 9, 'status' => 'Present',
        ]);

        $r = $this->postJson('/api/Hrm/attendence-history', [
            'type' => 'monthly', 'month' => 3, 'year' => 2026,
        ])->assertOk();

        // `data` is a LIST, one entry per day — AttendanceHistory declares
        // List<AttendanceData> and iterates it, and the screen renders each entry
        // as a card with its own date and total. This test asserted a single
        // object until the app's own model threw on it.
        $this->assertIsArray($r->json('data'));

        foreach (['total_time', 'date', 'history'] as $k) {
            $this->assertArrayHasKey($k, $r->json('data.0'), "data.0.{$k} is missing.");
        }

        foreach (['id', 'status', 'clock_in', 'clock_out', 'total'] as $k) {
            $this->assertArrayHasKey($k, $r->json('data.0.history.0'), "history.{$k} is missing.");
        }

        $this->assertSame('2026-03-02', $r->json('data.0.date'));
        // 09:00 on the workspace clock, not the 03:30 that is in the column.
        $this->assertSame('09:00', $r->json('data.0.history.0.clock_in'));
        $this->assertSame('18:00', $r->json('data.0.history.0.clock_out'));
        $this->assertSame('09:00', $r->json('data.0.history.0.total'));
    }

    /** The date cast persists midnight, which silently drops month-end days. */
    public function test_history_includes_the_last_day_of_the_month(): void
    {
        [, $employee] = $this->person();

        HrAttendance::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $employee->id, 'date' => '2026-03-31',
            'check_in' => '2026-03-31 09:00:00', 'check_out' => '2026-03-31 18:00:00',
            'working_hours' => 9, 'status' => 'Present',
        ]);

        $this->postJson('/api/Hrm/attendence-history', ['month' => 3, 'year' => 2026])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(1, 'data.0.history');
    }

    public function test_history_never_shows_another_employees_days(): void
    {
        [, $mine] = $this->person();

        $otherUser = User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Raj', 'email' => 'raj@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);
        $other = HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'employee_code' => 'SNE-2', 'name' => 'Raj',
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2020-01-01',
            'status' => 'Active', 'user_id' => $otherUser->id,
        ]);

        HrAttendance::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $other->id, 'date' => '2026-03-02',
            'check_in' => '2026-03-02 09:00:00', 'status' => 'Present',
        ]);

        $this->postJson('/api/Hrm/attendence-history', ['month' => 3, 'year' => 2026])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_an_unlinked_login_gets_a_refusal_not_a_crash(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'NoEmp', 'email' => 'no@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);
        Sanctum::actingAs($user);

        $r = $this->postJson('/api/Hrm/home', [])->assertOk();
        $this->assertSame(0, $r->json('status'));
    }
}
