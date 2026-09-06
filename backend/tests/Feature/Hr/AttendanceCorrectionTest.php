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
        HrAttendance::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $me->id, 'date' => '2026-03-02',
            'check_in' => '2026-03-02 09:00:00', 'status' => 'Present',
        ]);

        $c = $this->ask($user);

        Sanctum::actingAs($admin);
        $this->postJson("/api/hr/corrections/{$c->id}/approve")->assertOk();

        $day = HrAttendance::where('employee_id', $me->id)->whereDate('date', '2026-03-02')->firstOrFail();

        $this->assertSame('18:00:00', $day->check_out->format('H:i:s'), 'The day must actually be corrected.');
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
        $this->assertSame('09:00:00', $day->check_in->format('H:i:s'));
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
        $this->assertSame('18:30:00', $day->check_out->format('H:i:s'));
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
}
