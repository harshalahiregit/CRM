<?php

namespace App\Http\Controllers\Api\Hrm;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrEmployee;
use App\Services\Hr\AttendanceService;
use App\Services\Hr\EmployeeIdentityService;
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

        $this->storeSelfie($request, $employee, $data['type']);

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

        $today = $this->today($employee);

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

        // Not yet clocked out: show the time worked so far rather than nothing,
        // which is what somebody glancing at the app actually wants.
        if ($a->check_in && ! $a->check_out) {
            return $this->formatHours(abs(Carbon::parse($a->check_in)->diffInMinutes(now())) / 60);
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
     * Announcements: holidays coming up.
     *
     * The CRM has no announcements table, and their model's fields map cleanly
     * onto holidays — which is what an attendance app's home screen is actually
     * for. Every key their model reads is present.
     */
    private function announcements(HrEmployee $employee): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('hr_holidays')) {
            return [];
        }

        return \Illuminate\Support\Facades\DB::table('hr_holidays')
            ->where('tenant_id', $employee->tenant_id)
            ->where('is_active', true)
            ->whereDate('holiday_date', '>=', now()->toDateString())
            ->orderBy('holiday_date')
            ->limit(10)
            ->get()
            ->map(fn ($h) => [
                'id'          => $h->id,
                'title'       => $h->title,
                'start_date'  => (string) $h->holiday_date,
                'end_date'    => (string) $h->holiday_date,
                'description' => (string) ($h->description ?? ''),
                'workspace'   => $employee->tenant_id,
                'created_by'  => $h->created_by,
            ])->values()->all();
    }

    /** Best effort: a punch is never lost because a photo could not be saved. */
    private function storeSelfie(Request $request, HrEmployee $employee, string $type): void
    {
        if (! $request->hasFile('selfie')) {
            return;
        }

        try {
            $request->file('selfie')->store("hr/attendance/tenant_{$employee->tenant_id}/{$employee->id}", 'local');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Selfie not stored', [
                'employee' => $employee->id, 'type' => $type, 'error' => $e->getMessage(),
            ]);
        }
    }
}
