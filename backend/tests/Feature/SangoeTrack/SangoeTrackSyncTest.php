<?php

namespace Tests\Feature\SangoeTrack;

use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrEmployee;
use App\Services\SangoeTrack\AttendanceSyncService;
use App\Services\SangoeTrack\SangoeTrackClient;
use App\Services\SangoeTrack\SangoeTrackException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The sync's job is to normalise an unspecified remote payload and then defer
 * every rule (status, hours, overtime, eligibility) to AttendanceService. These
 * tests pin that split: the mapping is ours to get right, the derivation must
 * come out identical to a manual entry.
 */
class SangoeTrackSyncTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sangoetrack.enabled'      => true,
            'sangoetrack.base_url'     => 'https://track.test/api',
            'sangoetrack.email'        => 'sync@test.com',
            'sangoetrack.password'     => 'secret',
            'sangoetrack.workspace_id' => 7,
        ]);

        Cache::flush();
    }

    private function employee(array $attrs = []): HrEmployee
    {
        return HrEmployee::create(array_merge([
            'tenant_id'           => self::TENANT,
            'name'                => 'Asha Menon',
            'email'               => 'asha'.uniqid().'@test.com',
            'employee_code'       => 'E'.random_int(100000, 999999),
            'department'          => 'Engineering',
            'designation'         => 'Developer',
            'status'              => 'Active',
            'joining_date'        => '2020-01-01',
            'shift'               => 'General',
            'sangoetrack_user_id' => 55,
            'sangoetrack_workspace_id' => 7,
        ], $attrs));
    }

    /** Fake login + one attendance-history response. */
    private function fakeRemote(array $rows): void
    {
        Http::fake([
            'track.test/api/login' => Http::response(['token' => 'jwt-abc'], 200),
            'track.test/api/Hrm/attendence-history' => Http::response(['data' => $rows], 200),
        ]);
    }

    /**
     * Fake successive attendance-history responses.
     *
     * Calling Http::fake() twice MERGES stubs and the first match wins, so a
     * second fakeRemote() would silently replay the first payload. A sequence is
     * the only way to model "the remote changed between two syncs".
     */
    private function fakeRemoteSequence(array ...$payloads): void
    {
        $sequence = Http::sequence();
        foreach ($payloads as $rows) {
            $sequence->push(['data' => $rows], 200);
        }

        Http::fake([
            'track.test/api/login' => Http::response(['token' => 'jwt-abc'], 200),
            'track.test/api/Hrm/attendence-history' => $sequence,
        ]);
    }

    /** hr_attendance.date is a date column; SQLite returns it as a datetime. */
    private function assertHasDay(HrEmployee $employee, string $date): void
    {
        $this->assertSame(
            1,
            HrAttendance::where('employee_id', $employee->id)->whereDate('date', $date)->count(),
            "Expected exactly one hr_attendance row on {$date} for employee #{$employee->id}"
        );
    }

    private function sync(): AttendanceSyncService
    {
        return app(AttendanceSyncService::class);
    }

    /* ── mapping ─────────────────────────────────────────────────────── */

    public function test_a_punch_is_written_as_a_crm_attendance_row(): void
    {
        $employee = $this->employee();
        $this->fakeRemote([
            ['date' => '2026-09-01', 'check_in' => '09:05', 'check_out' => '18:10'],
        ]);

        $result = $this->sync()->syncEmployee($employee, '9', '2026');

        $this->assertSame(1, $result['synced']);
        $this->assertHasDay($employee, '2026-09-01');
        $this->assertSame(self::TENANT, (int) HrAttendance::firstOrFail()->tenant_id);
    }

    /** A bare "09:05" must anchor to the row's own date, never to today. */
    public function test_bare_times_anchor_to_the_row_date(): void
    {
        $employee = $this->employee();
        $this->fakeRemote([
            ['date' => '2026-09-02', 'check_in' => '09:05', 'check_out' => '18:00'],
        ]);

        $this->sync()->syncEmployee($employee, '9', '2026');

        $row = HrAttendance::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('2026-09-02 09:05:00', $row->check_in->toDateTimeString());
        $this->assertSame('2026-09-02 18:00:00', $row->check_out->toDateTimeString());
    }

    /** Some payloads send a day number rather than a full date. */
    public function test_a_bare_day_number_resolves_against_the_requested_period(): void
    {
        $employee = $this->employee();
        $this->fakeRemote([['date' => 3, 'check_in' => '09:00', 'check_out' => '18:00']]);

        $this->sync()->syncEmployee($employee, '9', '2026');

        $this->assertHasDay($employee, '2026-09-03');
    }

    public function test_rows_without_a_usable_date_are_skipped_not_fatal(): void
    {
        $employee = $this->employee();
        $this->fakeRemote([
            ['check_in' => '09:00'],                                   // no date
            ['date' => '2026-09-04', 'check_in' => '09:00', 'check_out' => '18:00'],
        ]);

        $result = $this->sync()->syncEmployee($employee, '9', '2026');

        $this->assertSame(1, $result['synced']);
        $this->assertSame(1, $result['skipped']);
    }

    /* ── derivation is reused, never re-implemented ──────────────────── */

    public function test_status_is_derived_by_the_crm_not_the_payload(): void
    {
        $employee = $this->employee();               // General shift: 09:00 +15m grace
        $this->fakeRemote([
            ['date' => '2026-09-07', 'check_in' => '09:05', 'check_out' => '18:00'],  // within grace
            ['date' => '2026-09-08', 'check_in' => '09:45', 'check_out' => '18:00'],  // beyond grace
        ]);

        $this->sync()->syncEmployee($employee, '9', '2026');

        $rows = HrAttendance::where('employee_id', $employee->id)->orderBy('date')->get();
        $this->assertSame('Present', $rows[0]->status);
        $this->assertSame('Late', $rows[1]->status);
    }

    public function test_working_and_overtime_hours_come_from_the_existing_calculator(): void
    {
        $employee = $this->employee();
        $this->fakeRemote([
            ['date' => '2026-09-09', 'check_in' => '09:00', 'check_out' => '19:30'],  // 10.5h
        ]);

        $this->sync()->syncEmployee($employee, '9', '2026');

        $row = HrAttendance::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('10.50', (string) $row->working_hours);
        $this->assertSame('2.50', (string) $row->overtime_hours);   // STANDARD_HOURS = 8
    }

    /** An explicit remote status wins and is not overwritten by the punch. */
    public function test_an_explicit_remote_status_is_honoured(): void
    {
        $employee = $this->employee();
        $this->fakeRemote([['date' => '2026-09-10', 'status' => 'On Leave']]);

        $this->sync()->syncEmployee($employee, '9', '2026');

        $this->assertSame('Leave', HrAttendance::where('employee_id', $employee->id)->firstOrFail()->status);
    }

    public function test_an_unknown_remote_status_falls_back_to_crm_derivation(): void
    {
        $employee = $this->employee();
        $this->fakeRemote([
            ['date' => '2026-09-11', 'status' => 'Something Odd', 'check_in' => '09:02', 'check_out' => '18:00'],
        ]);

        $this->sync()->syncEmployee($employee, '9', '2026');

        $this->assertSame('Present', HrAttendance::where('employee_id', $employee->id)->firstOrFail()->status);
    }

    /* ── idempotency ─────────────────────────────────────────────────── */

    public function test_resyncing_an_unchanged_day_writes_nothing_new(): void
    {
        $employee = $this->employee();
        $this->fakeRemote([['date' => '2026-09-12', 'check_in' => '09:00', 'check_out' => '18:00']]);

        $this->sync()->syncEmployee($employee, '9', '2026');
        $first = HrAttendance::where('employee_id', $employee->id)->firstOrFail();

        $second = $this->sync()->syncEmployee($employee, '9', '2026');
        $again  = HrAttendance::where('employee_id', $employee->id)->firstOrFail();

        // Asserting `failed === 0` is load-bearing: an earlier version of this
        // test passed only because the re-sync threw a duplicate-key error that
        // the per-day catch swallowed, leaving the original row untouched.
        $this->assertSame(0, $second['failed'], 'a no-op re-sync must not error');
        $this->assertSame(1, $second['synced']);
        $this->assertSame(1, HrAttendance::where('employee_id', $employee->id)->count());
        $this->assertEquals($first->updated_at->toDateTimeString(), $again->updated_at->toDateTimeString());
    }

    public function test_a_corrected_punch_updates_the_existing_row(): void
    {
        $employee = $this->employee();
        $this->fakeRemoteSequence(
            [['date' => '2026-09-13', 'check_in' => '09:00', 'check_out' => '17:00']],
            [['date' => '2026-09-13', 'check_in' => '09:00', 'check_out' => '18:00']],
        );

        $this->sync()->syncEmployee($employee, '9', '2026');
        $this->sync()->syncEmployee($employee, '9', '2026');

        $row = HrAttendance::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(1, HrAttendance::where('employee_id', $employee->id)->count());
        $this->assertSame('2026-09-13 18:00:00', $row->check_out->toDateTimeString());
        $this->assertSame('9.00', (string) $row->working_hours);
    }

    /* ── isolation ───────────────────────────────────────────────────── */

    public function test_an_ineligible_employee_is_skipped_not_fatal(): void
    {
        // Joins after the synced days — ensureRecord() refuses these deliberately.
        $employee = $this->employee(['joining_date' => '2030-01-01']);
        $this->fakeRemote([['date' => '2026-09-14', 'check_in' => '09:00', 'check_out' => '18:00']]);

        $result = $this->sync()->syncEmployee($employee, '9', '2026');

        $this->assertSame(0, $result['synced']);
        $this->assertSame(1, $result['skipped']);
        $this->assertDatabaseCount('hr_attendance', 0);
    }

    public function test_unmapped_employees_are_skipped_without_calling_the_api(): void
    {
        $employee = $this->employee(['sangoetrack_user_id' => null]);
        Http::fake();

        $result = $this->sync()->syncEmployee($employee, '9', '2026');

        $this->assertSame(0, $result['synced']);
        $this->assertSame(1, $result['skipped']);
        Http::assertNothingSent();
    }

    public function test_sync_all_only_touches_the_given_tenant(): void
    {
        $mine   = $this->employee(['tenant_id' => 1, 'sangoetrack_user_id' => 55]);
        $theirs = $this->employee(['tenant_id' => 2, 'sangoetrack_user_id' => 56]);
        $this->fakeRemote([['date' => '2026-09-15', 'check_in' => '09:00', 'check_out' => '18:00']]);

        $this->sync()->syncAll(1, '9', '2026');

        $this->assertDatabaseHas('hr_attendance', ['employee_id' => $mine->id]);
        $this->assertDatabaseMissing('hr_attendance', ['employee_id' => $theirs->id]);
    }

    /* ── client behaviour ────────────────────────────────────────────── */

    public function test_the_token_is_cached_across_calls(): void
    {
        $this->fakeRemote([['date' => '2026-09-16', 'check_in' => '09:00', 'check_out' => '18:00']]);
        $client = app(SangoeTrackClient::class);

        $client->getAttendanceHistory(55, 7, '9', '2026');
        $client->getAttendanceHistory(55, 7, '9', '2026');

        Http::assertSentCount(3);   // 1 login + 2 history calls
    }

    public function test_a_login_without_a_token_fails_loudly(): void
    {
        Http::fake(['track.test/api/login' => Http::response(['message' => 'ok'], 200)]);

        $this->expectException(SangoeTrackException::class);
        app(SangoeTrackClient::class)->login(true);
    }

    public function test_an_upstream_error_is_a_sangoetrack_exception(): void
    {
        Http::fake([
            'track.test/api/login' => Http::response(['token' => 'jwt-abc'], 200),
            'track.test/api/Hrm/attendence-history' => Http::response(['message' => 'boom'], 500),
        ]);

        $this->expectException(SangoeTrackException::class);
        app(SangoeTrackClient::class)->getAttendanceHistory(55, 7, '9', '2026');
    }
}
