<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrAttendanceCorrection;
use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fixing a punch that was wrong, or was never made.
 *
 * The CRM had no native corrections at all — only a proxy to SangoeTrack's — so
 * this is the last everyday thing an employee could do in the app and not here.
 *
 * The test that matters most is that APPROVING ACTUALLY WRITES THE DAY and
 * recomputes the hours. A correction that is marked approved while the timesheet
 * still reads wrong is worse than one that was never made, because everybody
 * now believes it is fixed.
 */
class AttendanceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $t = null;

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'T', 'slug' => 'corr-t', 'status' => 'active']);
    }

    private function person(string $code, string $email, string $role = 'staff'): array
    {
        $user = User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'U'.$code, 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $role, 'status' => 'active',
        ]);

        $employee = HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'name' => "Emp {$code}", 'employee_code' => $code,
            'department' => 'Ops', 'designation' => 'Executive', 'status' => 'Active',
            'joining_date' => '2020-01-01', 'user_id' => $user->id,
        ]);

        return [$user, $employee];
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => $this->tenant()->id, 'name' => 'Admin', 'email' => 'admin@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    /**
     * A stored instant read back on the tenant's own clock.
     *
     * Every assertion about "what the employee asked for" goes through this,
     * because that is the only question the correction flow answers. Comparing
     * raw stored strings is what let the timezone bug pass: the local clock
     * face written into a UTC column matched the request exactly.
     */
    private function localTime(?\Carbon\Carbon $at): ?string
    {
        if (! $at) {
            return null;
        }

        $zone = app(\App\Services\Settings\SettingsFormatter::class)->timezone($this->tenant()->id);

        return $at->copy()->setTimezone($zone)->format('H:i');
    }

    private function ask(User $as, array $over = []): HrAttendanceCorrection
    {
        Sanctum::actingAs($as);
        $this->postJson('/api/hr/me/corrections', array_merge([
            'attendance_date'     => '2026-03-02',
            'requested_check_in'  => '09:00',
            'requested_check_out' => '18:00',
            'reason'              => 'Forgot to clock out.',
        ], $over))->assertCreated();

        return HrAttendanceCorrection::orderByDesc('id')->firstOrFail();
    }

    /* ── the point of the whole thing ────────────────────────────────── */

    public function test_approving_writes_the_day_and_recomputes_hours(): void
    {
        [$user, $me] = $this->person('SNE-1', 'priya@example.test');
        $admin = $this->admin();

        // The day exists but the clock-out is missing — the common case.
        // 03:30 UTC IS 09:00 in the tenant's zone: the column holds an instant,
        // so the fixture states one rather than a clock face.
        HrAttendance::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $me->id, 'date' => '2026-03-02',
            'check_in' => '2026-03-02 03:30:00', 'status' => 'Present',
        ]);

        $c = $this->ask($user);

        Sanctum::actingAs($admin);
        $this->postJson("/api/hr/corrections/{$c->id}/approve")->assertOk();

        $day = HrAttendance::where('employee_id', $me->id)->whereDate('date', '2026-03-02')->firstOrFail();

        // Asserted as the employee's own clock, which is what they asked for.
        // This used to assert the STORED string equalled '18:00:00', which is
        // how the bug survived: writing the local clock face into a UTC column
        // satisfied it exactly, and moved the punch five and a half hours.
        $this->assertSame('18:00', $this->localTime($day->check_out), 'The day must actually be corrected.');
        $this->assertSame('12:30:00', $day->check_out->format('H:i:s'), 'And be STORED as the matching UTC instant.');
        $this->assertSame(9.0, (float) $day->working_hours, 'Hours must be recomputed, not left stale.');
        $this->assertTrue($c->fresh()->applied, 'The row must record that the write happened.');
    }

    /** The most common request is for a day with no record at all. */
    public function test_approving_creates_the_day_when_none_exists(): void
    {
        [$user, $me] = $this->person('SNE-1', 'priya@example.test');
        $admin = $this->admin();

        $this->assertSame(0, HrAttendance::count());

        $c = $this->ask($user);

        Sanctum::actingAs($admin);
        $this->postJson("/api/hr/corrections/{$c->id}/approve")->assertOk();

        $day = HrAttendance::where('employee_id', $me->id)->firstOrFail();
        $this->assertSame('09:00', $this->localTime($day->check_in));
        $this->assertSame('03:30:00', $day->check_in->format('H:i:s'), 'Stored as the UTC instant, not the clock face.');
        $this->assertSame(9.0, (float) $day->working_hours);
    }

    /** A null means "leave this alone", never "clear it". */
    public function test_an_omitted_time_is_left_untouched(): void
    {
        [$user, $me] = $this->person('SNE-1', 'priya@example.test');
        $admin = $this->admin();

        HrAttendance::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $me->id, 'date' => '2026-03-02',
            'check_in' => '2026-03-02 09:15:00', 'check_out' => '2026-03-02 17:00:00', 'status' => 'Present',
        ]);

        $c = $this->ask($user, ['requested_check_in' => null, 'requested_check_out' => '18:30']);

        Sanctum::actingAs($admin);
        $this->postJson("/api/hr/corrections/{$c->id}/approve")->assertOk();

        $day = HrAttendance::where('employee_id', $me->id)->firstOrFail();
        $this->assertSame('09:15:00', $day->check_in->format('H:i:s'), 'The clock-in was not asked about.');
        $this->assertSame('18:30', $this->localTime($day->check_out));
        $this->assertSame('13:00:00', $day->check_out->format('H:i:s'), 'Stored as the UTC instant.');
    }

    public function test_rejecting_leaves_the_day_alone(): void
    {
        [$user, $me] = $this->person('SNE-1', 'priya@example.test');
        $admin = $this->admin();

        HrAttendance::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $me->id, 'date' => '2026-03-02',
            'check_in' => '2026-03-02 09:00:00', 'status' => 'Present',
        ]);

        $c = $this->ask($user);

        Sanctum::actingAs($admin);
        $this->postJson("/api/hr/corrections/{$c->id}/reject", ['remarks' => 'No supporting evidence.'])->assertOk();

        $day = HrAttendance::where('employee_id', $me->id)->firstOrFail();
        $this->assertNull($day->check_out, 'A rejected correction must change nothing.');
        $this->assertFalse($c->fresh()->applied);
    }

    public function test_rejecting_without_a_reason_is_refused(): void
    {
        [$user] = $this->person('SNE-1', 'priya@example.test');
        $admin = $this->admin();
        $c = $this->ask($user);

        Sanctum::actingAs($admin);
        $this->postJson("/api/hr/corrections/{$c->id}/reject", [])->assertStatus(422);
    }

    public function test_a_decided_correction_cannot_be_decided_again(): void
    {
        [$user] = $this->person('SNE-1', 'priya@example.test');
        $admin = $this->admin();
        $c = $this->ask($user);

        Sanctum::actingAs($admin);
        $this->postJson("/api/hr/corrections/{$c->id}/approve")->assertOk();
        $this->postJson("/api/hr/corrections/{$c->id}/approve")->assertStatus(422);
    }

    /* ── the request rules ───────────────────────────────────────────── */

    public function test_a_correction_with_no_times_asks_for_nothing(): void
    {
        [$user] = $this->person('SNE-1', 'priya@example.test');

        Sanctum::actingAs($user);
        $this->postJson('/api/hr/me/corrections', [
            'attendance_date' => '2026-03-02', 'reason' => 'Something was wrong.',
        ])->assertStatus(422);
    }

    public function test_a_future_day_cannot_be_corrected(): void
    {
        [$user] = $this->person('SNE-1', 'priya@example.test');

        Sanctum::actingAs($user);
        $this->postJson('/api/hr/me/corrections', [
            'attendance_date' => now()->addDays(3)->toDateString(),
            'requested_check_in' => '09:00', 'reason' => 'Planning ahead.',
        ])->assertStatus(422);
    }

    public function test_clock_out_before_clock_in_is_refused(): void
    {
        [$user] = $this->person('SNE-1', 'priya@example.test');

        Sanctum::actingAs($user);
        $this->postJson('/api/hr/me/corrections', [
            'attendance_date' => '2026-03-02',
            'requested_check_in' => '18:00', 'requested_check_out' => '09:00',
            'reason' => 'Wrong way round.',
        ])->assertStatus(422);
    }

    /** Two open rows for one day is how a day gets corrected twice. */
    public function test_only_one_open_request_per_day(): void
    {
        [$user] = $this->person('SNE-1', 'priya@example.test');
        $this->ask($user);

        Sanctum::actingAs($user);
        $this->postJson('/api/hr/me/corrections', [
            'attendance_date' => '2026-03-02', 'requested_check_in' => '10:00', 'reason' => 'Again.',
        ])->assertStatus(422);
    }

    /* ── boundaries ──────────────────────────────────────────────────── */

    public function test_an_employee_cannot_see_or_withdraw_somebody_elses(): void
    {
        [$mine] = $this->person('SNE-1', 'priya@example.test');
        [$other] = $this->person('SNE-2', 'raj@example.test');

        $theirs = $this->ask($other);

        Sanctum::actingAs($mine);
        $this->getJson("/api/hr/me/corrections/{$theirs->id}")->assertStatus(404);
        $this->patchJson("/api/hr/me/corrections/{$theirs->id}/withdraw")->assertStatus(404);
    }

    public function test_a_plain_employee_cannot_reach_the_queue_or_approve(): void
    {
        [$user] = $this->person('SNE-1', 'priya@example.test');
        $c = $this->ask($user);

        Sanctum::actingAs($user);
        $this->getJson('/api/hr/corrections')->assertStatus(403);
        $this->postJson("/api/hr/corrections/{$c->id}/approve")->assertStatus(403);
    }

    public function test_the_day_lookup_is_not_matched_as_a_record_id(): void
    {
        [$user] = $this->person('SNE-1', 'priya@example.test');

        Sanctum::actingAs($user);
        $this->getJson('/api/hr/me/corrections/day?date=2026-03-02')
            ->assertOk()
            ->assertJsonPath('data.date', '2026-03-02');
    }

    /* ── the back-and-forth ──────────────────────────────────────────── */

    public function test_a_hold_is_cleared_by_the_employee_replying(): void
    {
        [$user] = $this->person('SNE-1', 'priya@example.test');
        $admin = $this->admin();
        $c = $this->ask($user);

        Sanctum::actingAs($admin);
        $this->postJson("/api/hr/corrections/{$c->id}/hold", ['reason' => 'Which project were you on?'])->assertOk();
        $this->assertSame(HrAttendanceCorrection::ON_HOLD, $c->fresh()->status);

        Sanctum::actingAs($user);
        $this->postJson("/api/hr/me/corrections/{$c->id}/reply", ['body' => 'Pune site.'])->assertOk();

        $this->assertSame(HrAttendanceCorrection::PENDING, $c->fresh()->status);
    }

    public function test_an_internal_note_never_reaches_the_employee(): void
    {
        [$user] = $this->person('SNE-1', 'priya@example.test');
        $admin = $this->admin();
        $c = $this->ask($user);

        Sanctum::actingAs($admin);
        $this->postJson("/api/hr/corrections/{$c->id}/note", ['body' => 'Third time this month.'])->assertOk();

        Sanctum::actingAs($user);
        $body = json_encode($this->getJson("/api/hr/me/corrections/{$c->id}")->json('data.thread'));

        $this->assertStringNotContainsString('Third time this month', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | Timezone: a correction carries a clock face, the column holds an instant
    |--------------------------------------------------------------------------
    |
    | requested_check_in is a `time` column — "09:28", no date, no zone, because
    | that is what the employee read off their watch. hr_attendance.check_in is a
    | cast datetime stored in the app timezone, which config/app.php keeps at UTC.
    | Joining them with string concatenation wrote the clock face into the UTC
    | column and moved the punch by the whole offset, silently: it changed working
    | hours and late marks, and payroll reads those.
    |
    | The zone is the tenant's own localization.timezone, so these assert against
    | the configured value rather than a hardcoded offset.
    */

    /** The reported case: 09:28 local must be stored as the matching instant. */
    public function test_a_requested_local_time_is_stored_as_the_matching_utc_instant(): void
    {
        [$user, $me] = $this->person('SNE-1', 'priya@example.test');
        $admin = $this->admin();

        $c = $this->ask($user, ['requested_check_in' => '09:28', 'requested_check_out' => null]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/hr/corrections/{$c->id}/approve")->assertOk();

        $day = HrAttendance::where('employee_id', $me->id)->firstOrFail();

        $this->assertSame('09:28', $this->localTime($day->check_in),
            'The employee asked for 09:28 on their own clock and must get it back.');
        $this->assertSame('03:58:00', $day->check_in->format('H:i:s'),
            'Stored as 03:58 UTC — the instant, not the clock face.');
    }

    public function test_a_requested_clock_out_converts_the_same_way(): void
    {
        [$user, $me] = $this->person('SNE-1', 'priya@example.test');
        $admin = $this->admin();

        $c = $this->ask($user, ['requested_check_in' => null, 'requested_check_out' => '18:45']);

        Sanctum::actingAs($admin);
        $this->postJson("/api/hr/corrections/{$c->id}/approve")->assertOk();

        $day = HrAttendance::where('employee_id', $me->id)->firstOrFail();
        $this->assertSame('18:45', $this->localTime($day->check_out));
        $this->assertSame('13:15:00', $day->check_out->format('H:i:s'));
    }

    /**
     * The offset is applied once.
     *
     * The obvious wrong fix is to convert a value that was already an instant,
     * which moves it again. Approving twice is not possible, so this asserts the
     * single approval lands exactly on the requested clock — a double conversion
     * would read 14:58 or 03:58 back on the employee's clock, never 09:28.
     */
    public function test_the_offset_is_not_applied_twice(): void
    {
        [$user, $me] = $this->person('SNE-1', 'priya@example.test');
        $admin = $this->admin();

        $c = $this->ask($user, ['requested_check_in' => '09:28', 'requested_check_out' => '18:00']);

        Sanctum::actingAs($admin);
        $this->postJson("/api/hr/corrections/{$c->id}/approve")->assertOk();

        $day = HrAttendance::where('employee_id', $me->id)->firstOrFail();
        $this->assertSame('09:28', $this->localTime($day->check_in));
        $this->assertSame('18:00', $this->localTime($day->check_out));
    }

    /**
     * A late-night local time belongs to the PREVIOUS UTC date.
     *
     * 00:30 on the 2nd, in a zone ahead of UTC, is 19:00 on the 1st. The
     * attendance row keeps the date the employee is correcting; only the instant
     * moves. Concatenation could never produce this, which is the clearest proof
     * the two values are different kinds of thing.
     */
    public function test_a_local_time_after_midnight_crosses_the_utc_date(): void
    {
        [$user, $me] = $this->person('SNE-1', 'priya@example.test');
        $admin = $this->admin();

        $c = $this->ask($user, ['requested_check_in' => '00:30', 'requested_check_out' => null]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/hr/corrections/{$c->id}/approve")->assertOk();

        $day = HrAttendance::where('employee_id', $me->id)->firstOrFail();

        $this->assertSame('00:30', $this->localTime($day->check_in));
        $this->assertSame('2026-03-01 19:00:00', $day->check_in->format('Y-m-d H:i:s'),
            'The instant falls on the previous UTC day.');
        $this->assertSame('2026-03-02', $day->date->format('Y-m-d'),
            'The attendance date is the day being corrected and does not move.');
    }

    /** Working hours are derived from the corrected instants, so they stay right. */
    public function test_working_hours_are_computed_from_the_corrected_instants(): void
    {
        [$user, $me] = $this->person('SNE-1', 'priya@example.test');
        $admin = $this->admin();

        $c = $this->ask($user, ['requested_check_in' => '09:00', 'requested_check_out' => '17:30']);

        Sanctum::actingAs($admin);
        $this->postJson("/api/hr/corrections/{$c->id}/approve")->assertOk();

        $day = HrAttendance::where('employee_id', $me->id)->firstOrFail();
        $this->assertSame(8.5, (float) $day->working_hours);
    }

    /**
     * A normal punch is untouched.
     *
     * AttendanceService stores now(), which is already an instant — it never had
     * this bug, and the fix must not reach into it. If both paths are right, a
     * punched day and a corrected day are the same kind of value.
     */
    public function test_a_normal_punch_still_stores_the_current_instant(): void
    {
        [$user, $me] = $this->person('SNE-1', 'priya@example.test');

        Sanctum::actingAs($user);
        $this->postJson('/api/hr/me/attendance/check-in')->assertSuccessful();

        $day = HrAttendance::where('employee_id', $me->id)->firstOrFail();

        $this->assertNotNull($day->check_in);
        $this->assertLessThan(120, abs($day->check_in->diffInSeconds(now())),
            'A punch records the moment it happened, in UTC, as it always did.');
    }

    /** The API shape the Attendance App parses must not move. */
    public function test_the_correction_response_shape_is_unchanged(): void
    {
        [$user] = $this->person('SNE-1', 'priya@example.test');
        $admin = $this->admin();
        $c = $this->ask($user, ['requested_check_in' => '09:28']);

        Sanctum::actingAs($admin);
        $body = $this->postJson("/api/hr/corrections/{$c->id}/approve")->assertOk()->json();

        // The envelope the app parses: status/message/data, with the correction
        // under data.correction and its thread beside it.
        $this->assertSame('success', data_get($body, 'status'));
        $this->assertArrayHasKey('correction', $body['data']);
        $this->assertArrayHasKey('thread', $body['data']);
        $this->assertSame('approved', data_get($body, 'data.correction.status'));
        // requested_* are what the employee typed and are echoed back unchanged —
        // the conversion happens on the way into hr_attendance, not to the request.
        $this->assertSame('09:28', substr((string) data_get($body, 'data.correction.requested_check_in'), 0, 5));
    }
}
