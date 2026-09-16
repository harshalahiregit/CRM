<?php

namespace App\Http\Controllers\Api\Hrm;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrEmployee;
use App\Services\Hr\AttendanceService;
use App\Services\Hr\EmployeeIdentityService;
use App\Models\Notification;
use App\Support\Hr\TenantTime;
use App\Support\Hrm\HrmResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The app's daily path: the home screen, clocking, breaks and history.
 *
 * Field names come from the app's Dart models and are reproduced EXACTLY,
 * including two inconsistencies that are theirs and must not be tidied:
 *
 *   `attendence_id`  — misspelled — is what clock-in-out and break-toggle send
 *                      and read. `home` uses the correctly spelled
 *                      `attendance_id`. Both spellings are live, on different
 *                      endpoints, and "fixing" either silently breaks the app.
 *
 *   `is_clockin` is an INT while `is_on_break` is a BOOL, in the same payload.
 *   Their models declare them that way, so sending a bool for the first, or an
 *   int for the second, fails to parse.
 *
 * The route itself is /attendence-history, misspelled in the app's URL table.
 */
class HrmAttendanceController extends Controller
{
    public function __construct(
        private EmployeeIdentityService $identity,
        private AttendanceService $attendance,
    ) {
    }

    /** The home screen: today's clock state plus announcements. */
    public function home(Request $request)
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return HrmResponse::fail('Your login is not linked to an employee record. Contact HR.');
        }

        $today = $this->today($employee);

        return HrmResponse::ok([
            // int, not bool — their model declares int? isClockin.
            'is_clockin'    => $today && $today->check_in && ! $today->check_out ? 1 : 0,
            'attendance_id' => $today?->id,
            'clock_in'      => $this->time($today?->check_in),
            'clock_out'     => $this->time($today?->check_out),
            'total_hours'   => $this->hours($today),
            // bool here, unlike is_clockin above. Theirs, not a typo of mine.
            'is_on_break'   => (bool) ($today && $today->break_start && ! $today->break_end),
            // Never null: the app iterates this without a guard.
            'announcements' => $this->announcements($employee),
        ]);
    }

    /**
     * Clock in or out.
     *
     * Multipart, with a `selfie` file. The selfie is stored when sent and the
     * punch is recorded either way — refusing a clock-out because a camera
     * failed would strand somebody at the end of a shift.
     */
    public function clock(Request $request)
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return HrmResponse::fail('Your login is not linked to an employee record. Contact HR.');
        }

        $data = $request->validate([
            'type'      => 'required|in:clockin,clockout',
            'latitude'  => 'nullable|string|max:40',
            'longitude' => 'nullable|string|max:40',
            'selfie'    => 'nullable|file|max:10240',
        ]);

        $today = $this->today($employee);

        if ($data['type'] === 'clockin') {
            if ($today && $today->check_in && ! $today->check_out) {
                return HrmResponse::fail('You are already clocked in.');
            }

            // A finished day cannot be started again. The table holds ONE check_in
            // and ONE check_out per day, so a second clock-in overwrote the first
            // and left the earlier check_out behind — a day whose end precedes its
            // beginning, and hours computed from nonsense.
            if ($today && $today->check_in && $today->check_out) {
                return HrmResponse::fail('You have already completed your shift today.');
            }

            $today ??= new HrAttendance([
                'tenant_id'   => $employee->tenant_id,
                'employee_id' => $employee->id,
                'date'        => now()->toDateString(),
                'status'      => 'Present',
            ]);

            $today->check_in = now();
            $today->save();
        } else {
            // A shift that began before midnight is still the shift being ended.
            // Keyed strictly to today's date, somebody who clocked in at 22:00
            // could not clock out at 02:00: the record exists, filed under
            // yesterday, and they were told they had never clocked in — with no
            // way to close the day from the phone.
            $open = ($today && $today->check_in && ! $today->check_out)
                ? $today
                : $this->openShift($employee);

            if (! $open) {
                // Say which of the two it is; "you never clocked in" when the real
                // answer is "you already left" wastes somebody's morning.
                return HrmResponse::fail(
                    $today && $today->check_out
                        ? 'You have already clocked out.'
                        : 'You have not clocked in today.'
                );
            }

            $today = $open;

            // An open break would otherwise be counted as worked time.
            if ($today->break_start && ! $today->break_end) {
                $today->break_end = now();
            }

            $today->check_out = now();
            $today->save();
        }

        // Keep what the punch came with. The app sends coordinates and a selfie on
        // every clock in and out — it refuses to punch without the photo — and all
        // of it used to be validated and dropped.
        $this->recordPunchEvidence($request, $today, $employee, $data['type']);

        // Status, hours and overtime from the same code the CRM uses.
        $today = $this->attendance->restampAndSave($today);

        return HrmResponse::ok([
            'is_clockin'          => $today->check_in && ! $today->check_out ? 1 : 0,
            'clock_in'            => $this->time($today->check_in),
            'clock_out'           => $this->time($today->check_out),
            'total_hours'         => $this->hours($today),
            // Their spelling, on this endpoint.
            'attendence_id'       => $today->id,
            'attendence_clock_in' => $this->time($today->check_in),
        ], $data['type'] === 'clockin' ? 'Clocked in.' : 'Clocked out.');
    }

    /** Start or end a break. */
    public function breakToggle(Request $request)
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return HrmResponse::fail('Your login is not linked to an employee record. Contact HR.');
        }

        $data = $request->validate(['type' => 'required|in:start,continue']);

        // The same fallback clock-out makes: a shift that began before midnight
        // is still the shift being broken from. Keyed strictly to today's date,
        // somebody who clocked in at 22:00 was told at 00:30 that they had never
        // clocked in — their record exists, filed under yesterday — and there
        // was no way to take a break from the phone for the rest of the night.
        $today = $this->today($employee);

        if (! $today || ! $today->check_in || $today->check_out) {
            $today = $this->openShift($employee);
        }

        if (! $today || ! $today->check_in || $today->check_out) {
            return HrmResponse::fail('You need to be clocked in to take a break.');
        }

        $onBreak = $today->break_start && ! $today->break_end;

        if ($data['type'] === 'start') {
            if ($onBreak) {
                return HrmResponse::fail('You are already on a break.');
            }

            // One break a day is what the columns hold. Restarting after ending
            // one would overwrite the first and lose the time already deducted.
            if ($today->break_end) {
                return HrmResponse::fail('You have already taken your break today.');
            }

            $today->break_start = now();
        } else {
            if (! $onBreak) {
                return HrmResponse::fail('You are not on a break.');
            }

            $today->break_end = now();
        }

        $today->save();
        $today = $this->attendance->restampAndSave($today);

        return HrmResponse::ok([
            'is_on_break' => (bool) ($today->break_start && ! $today->break_end),
        ], $data['type'] === 'start' ? 'Break started.' : 'Break ended.');
    }

    /**
     * A month of the caller's own attendance.
     *
     * The route is /attendence-history — misspelled in the app's URL table, and
     * reproduced rather than corrected.
     */
    public function history(Request $request)
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return HrmResponse::fail('Your login is not linked to an employee record. Contact HR.');
        }

        $data = $request->validate([
            'type'  => 'nullable|string',
            'month' => 'nullable',
            'year'  => 'nullable',
        ]);

        $month = (int) ($data['month'] ?? now()->month);
        $year  = (int) ($data['year'] ?? now()->year);

        $from = Carbon::createFromDate($year, $month, 1)->startOfMonth();

        $rows = HrAttendance::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            // whereDate, not between: the date cast persists midnight, so an
            // equality range silently drops the last day of the month.
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $from->copy()->endOfMonth()->toDateString())
            ->orderBy('date')
            ->get();

        // A LIST, one entry per day — not a single object.
        //
        // AttendanceHistory declares `List<AttendanceData>? data` and iterates it;
        // the screen renders each entry as a card with its own date and total,
        // holding that day's punches. Returning an object made Map.forEach receive
        // a one-argument closure, which throws inside fromJson — so the app showed
        // NO history at all rather than showing it wrongly.
        $byDay = $rows->groupBy(fn (HrAttendance $a) => $a->date->format('Y-m-d'));

        return HrmResponse::ok($byDay->map(fn ($dayRows, $day) => [
            'total_time' => $this->formatHours((float) $dayRows->sum('working_hours')),
            'date'       => $day,
            'history'    => $dayRows->map(fn (HrAttendance $a) => [
                'id'        => $a->id,
                'status'    => $a->status,
                'clock_in'  => $this->time($a->check_in),
                'clock_out' => $this->time($a->check_out),
                'total'     => $this->hours($a),
            ])->values()->all(),
        ])->values()->all());
    }

    /* ── internals ───────────────────────────────────────────────────── */

    /**
     * Every action starts here, so this is also where the workspace clock is
     * settled — see time(), which formats stored UTC on the tenant's own clock
     * and has no other way to know whose workspace it is.
     */
    private ?int $tenantId = null;

    private function employee(Request $request): ?HrEmployee
    {
        $employee = $this->identity->employeeFor($request->user());
        $this->tenantId = $employee?->tenant_id ?? $request->user()?->tenant_id;

        return $employee;
    }

    /**
     * The shift still running — today's, or yesterday's if it crossed midnight.
     *
     * Bounded to one day back on purpose: an abandoned record from last week is
     * a correction for HR to make, not something a clock-out should silently
     * close and stamp with the wrong hours.
     */
    private function openShift(HrEmployee $employee): ?HrAttendance
    {
        return HrAttendance::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->whereDate('date', '>=', now()->copy()->subDay()->toDateString())
            ->orderByDesc('date')
            ->first();
    }

    private function today(HrEmployee $employee): ?HrAttendance
    {
        return HrAttendance::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereDate('date', TenantTime::today($employee->tenant_id))
            ->first();
    }

    /**
     * Strings, not nulls: their model reads String? and shows a blank as "--".
     *
     * On the workspace's clock, not the server's. Stored UTC, a 2:11pm clock-in
     * was sent to the phone as "08:41".
     */
    private function time($value): string
    {
        return TenantTime::hm($value, $this->tenantId);
    }

    private function hours(?HrAttendance $a): string
    {
        if (! $a) {
            return '';
        }

        // Still clocked in TODAY: show the time so far, which is what somebody
        // glancing at the app wants — in at 09:00, it is 14:00, show 5:00.
        //
        // Only today. An open shift from four days ago showed 91:43, because the
        // clock kept running on a day nobody closed; on the history screen that
        // reads as "you worked 91 hours that day", beside a card header saying
        // 00:00 for the same day. A day that was never clocked out has no total,
        // and saying so is the honest answer — the correction screen is where it
        // gets fixed.
        if ($a->check_in && ! $a->check_out) {
            $isToday = Carbon::parse($a->date)->isSameDay(
                TenantTime::now($a->tenant_id)
            );

            return $isToday
                ? $this->formatHours(abs(Carbon::parse($a->check_in)->diffInMinutes(now())) / 60)
                : '';
        }

        return $a->working_hours !== null ? $this->formatHours((float) $a->working_hours) : '';
    }

    private function formatHours(float $hours): string
    {
        $h = (int) floor($hours);
        $m = (int) round(($hours - $h) * 60);

        if ($m === 60) {
            $h++;
            $m = 0;
        }

        return sprintf('%02d:%02d', $h, $m);
    }

    /**
     * Announcements: the ones somebody actually wrote and sent to this person.
     *
     * This used to return upcoming holidays, because when it was written the CRM
     * had no announcements to return. It does now — they are composed in the
     * Notification Center — and a section headed "Announcements" showing the
     * holiday calendar was both wrong and redundant, since holidays have their
     * own screen with a month view.
     *
     * Only this employee's own, because an announcement can be addressed to one
     * department and must not appear on everybody's home screen.
     */
    private function announcements(HrEmployee $employee): array
    {
        if (! $employee->user_id) {
            return [];
        }

        return Notification::where('tenant_id', $employee->tenant_id)
            ->where('user_id', $employee->user_id)
            ->where('type', 'announcement')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (Notification $n) => [
                'id'          => $n->id,
                'title'       => (string) $n->title,
                // Their model reads a start and an end and prints them as a
                // range. An announcement happens once, so both are the moment it
                // was sent rather than a fabricated span.
                'start_date'  => $n->created_at?->toDateString() ?? '',
                'end_date'    => $n->created_at?->toDateString() ?? '',
                'description' => (string) $n->message,
                'workspace'   => $employee->tenant_id,
                'created_by'  => null,
                // So the card can say which ones still need reading.
                'is_read'     => $n->read_at !== null,
                'attachments' => count($n->attachments ?? []),
            ])->values()->all();
    }

    /**
     * Where the punch happened, the photo taken with it, and the address it came
     * from — against the in or the out, whichever this was.
     *
     * Best effort throughout: a punch is never lost because a photo could not be
     * written or a column could not be filled. Somebody standing at a gate has
     * done their part, and refusing the punch would cost them a day's attendance
     * over a storage problem that is not theirs.
     *
     * The selfie was previously stored and its path discarded, so the file
     * existed on disk with nothing pointing at it. The path is kept now, which
     * is what makes it viewable from the register.
     */
    private function recordPunchEvidence(
        Request $request,
        HrAttendance $today,
        HrEmployee $employee,
        string $type,
    ): void {
        $out = $type === 'clockout';

        $fields = [
            ($out ? 'check_out_latitude'  : 'check_in_latitude')  => $request->input('latitude'),
            ($out ? 'check_out_longitude' : 'check_in_longitude') => $request->input('longitude'),
            // The app does not send an address today; the column is filled when a
            // caller has one, so a reverse-geocode can drop in without a change here.
            ($out ? 'check_out_address'   : 'check_in_address')   => $request->input('address'),
            ($out ? 'check_out_ip'        : 'check_in_ip')        => $request->ip(),
        ];

        if ($request->hasFile('selfie')) {
            try {
                $path = $request->file('selfie')->store(
                    "hr/attendance/tenant_{$employee->tenant_id}/{$employee->id}",
                    'local',
                );
                $fields[$out ? 'check_out_selfie' : 'check_in_selfie'] = $path;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Selfie not stored', [
                    'employee' => $employee->id, 'type' => $type, 'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $today->forceFill(array_filter($fields, fn ($v) => $v !== null && $v !== ''))->save();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Punch evidence not stored', [
                'attendance' => $today->id, 'type' => $type, 'error' => $e->getMessage(),
            ]);
        }
    }
}
