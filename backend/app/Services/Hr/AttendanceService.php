<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrEmployee;
use App\Repositories\Hr\AttendanceRepository;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Attendance.
 *
 * A newly opened day takes its shift timing — and whether it is a weekly off —
 * from the employee's shift assignment. An employee with no assignment keeps the
 * pre-shift behaviour exactly: the 'General' preset, opened as Absent.
 */
class AttendanceService
{
    /** Statuses that count as "present" for attendance-percentage purposes. */
    private const PRESENT_ISH = ['Present', 'Late', 'Half Day', 'Work From Home', 'Remote'];

    public function __construct(
        private AttendanceRepository $attendanceRepository,
        private ShiftService $shifts,
    ) {
    }

    /* ─────────────── Listing & dashboard ─────────────── */

    public function list(int $tenantId, array $filters): Collection
    {
        return $this->attendanceRepository->filtered($tenantId, $filters);
    }

    /** Dashboard cards for a given date (defaults to today). */
    /**
     * Employees eligible for attendance on a given date:
     *   status = Active, joining_date on/before the date, and tenant-scoped.
     * Future joiners, inactive and terminated staff are excluded. This is the single
     * definition used by the stats and by the marking guard.
     */
    public function eligibleEmployees(int $tenantId, ?string $date = null)
    {
        return HrEmployee::where('tenant_id', $tenantId)
            ->where('status', 'Active')
            ->whereNotNull('joining_date')
            ->whereDate('joining_date', '<=', $date ?: Carbon::today()->toDateString());
    }

    /** True when the employee may have attendance recorded on that date. */
    public function isEligible(HrEmployee $employee, ?string $date = null): bool
    {
        return $this->eligibleEmployees($employee->tenant_id, $date)->whereKey($employee->id)->exists();
    }

    public function dashboardStats(int $tenantId, ?string $date = null): array
    {
        $date  = $date ?: Carbon::today()->toDateString();
        // Only attendance-eligible employees form the denominator, so future
        // joiners and inactive staff never inflate absent/attendance_pct.
        $total = $this->eligibleEmployees($tenantId, $date)->count();

        $records = HrAttendance::where('tenant_id', $tenantId)->whereDate('date', $date)->get();
        $by      = fn ($status) => $records->where('status', $status)->count();

        $marked   = $records->count();
        $unmarked = max(0, $total - $marked);
        $presentish = $records->whereIn('status', self::PRESENT_ISH)->count();
        $avgHours = round((float) $records->whereNotNull('working_hours')->avg('working_hours'), 2);

        return [
            'date'                 => $date,
            'total_employees'      => $total,
            'present'              => $by('Present'),
            'absent'               => $by('Absent') + $unmarked,   // unmarked = not yet recorded
            'late'                 => $by('Late'),
            'half_day'             => $by('Half Day'),
            'on_leave'             => $by('Leave'),
            'remote'               => $by('Remote') + $by('Work From Home'),
            'holiday'              => $by('Holiday'),
            'weekend'              => $by('Weekend'),
            'avg_working_hours'    => $avgHours,
            'attendance_pct'       => $total > 0 ? round($presentish / $total * 100, 1) : 0.0,
        ];
    }

    /* ─────────────── Operations ─────────────── */

    /** Find-or-create the attendance row for an employee on a date (tenant-scoped). */
    public function ensureRecord(HrEmployee $employee, string $date, ?string $shift = null): HrAttendance
    {
        // Eligibility guard: no attendance may be opened for a future joiner or an
        // inactive/terminated employee. Existing rows are never touched.
        if (! $this->isEligible($employee, $date)) {
            throw new BusinessException('This employee is not eligible for attendance on '.$date.' (must be Active and already joined).', 422);
        }

        // Two-step lookup on purpose. The `date` cast serialises with a time
        // component, so a row written as "2026-09-13 00:00:00" is not found by
        // an equality match on "2026-09-13" unless the driver coerces it — MySQL
        // does (DATE column), SQLite does not. A plain firstOrNew() therefore
        // misses on SQLite and tries to INSERT a duplicate, which the
        // (tenant_id, employee_id, date) unique index rejects.
        // Equality first so MySQL still uses that index; whereDate() is the
        // fallback that makes every driver agree.
        $base = HrAttendance::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id);

        $record = (clone $base)->where('date', $date)->first()
            ?? (clone $base)->whereDate('date', $date)->first()
            ?? new HrAttendance([
                'tenant_id'   => $employee->tenant_id,
                'employee_id' => $employee->id,
                'date'        => $date,
            ]);

        if (! $record->exists) {
            // The assigned shift decides the timing and whether the day is a weekly
            // off. An explicit $shift still wins — a manual entry or an external
            // sync stating a shift is a deliberate override, not a guess.
            $assigned = $shift ? null : $this->assignedShiftFor($employee, $date);

            $record->status = ($assigned && $assigned['off']) ? 'Weekend' : 'Absent';

            if ($assigned && $assigned['shift']) {
                $this->applyAssignedShift($record, $assigned);
            } else {
                $this->applyShift($record, $shift ?: 'General');
            }
            $record->save();
        } elseif ($shift) {
            $this->applyShift($record, $shift);
        }

        return $record;
    }

    /**
     * The employee's assigned shift for a date, or null when they have none.
     *
     * Returns null rather than a default so ensureRecord() can tell "no shift
     * assigned" (keep the pre-shift behaviour) apart from "assigned, and today is
     * a working day" — the two need different handling.
     *
     * @return array{shift: mixed, off: bool, start: ?string, end: ?string, grace: int}|null
     */
    private function assignedShiftFor(HrEmployee $employee, string $date): ?array
    {
        $resolved = $this->shifts->isOffDay((int) $employee->id, (int) $employee->tenant_id, $date);

        if ($resolved['source'] !== 'shift') {
            return null;   // unassigned — nothing here applies
        }

        return [
            'shift' => $resolved['shift'],
            'off'   => (bool) $resolved['off'],
            'start' => $resolved['timing']?->start_time,
            'end'   => $resolved['timing']?->end_time,
            'grace' => (int) ($resolved['shift']->grace_in_minutes ?? 0),
        ];
    }

    /**
     * Write the assigned shift's own name, timing and grace onto the record.
     *
     * Deliberately NOT routed through applyShift(): that maps a name onto the
     * five hardcoded HrAttendance::SHIFTS presets, and a tenant-defined shift is
     * not one of them. Its configured times are used verbatim.
     */
    private function applyAssignedShift(HrAttendance $record, array $assigned): void
    {
        $record->shift        = $assigned['shift']->name;
        $record->shift_start  = $assigned['start'];
        $record->shift_end    = $assigned['end'];
        $record->grace_period = $assigned['grace'];
    }

    public function checkIn(HrAttendance $a, ?string $at = null): HrAttendance
    {
        $a->check_in = $at ? Carbon::parse($at) : now();
        $this->applyStatusFromCheckIn($a);
        $this->recompute($a);
        $a->save();
        $a->recordAudit('Attendance Check-In', null, null, ['time' => optional($a->check_in)->toDateTimeString(), 'status' => $a->status]);

        return $a->fresh('employee');
    }

    public function checkOut(HrAttendance $a, ?string $at = null): HrAttendance
    {
        $a->check_out = $at ? Carbon::parse($at) : now();
        $this->recompute($a);
        $a->save();
        $a->recordAudit('Attendance Check-Out', null, null, ['time' => optional($a->check_out)->toDateTimeString(), 'working_hours' => $a->working_hours]);

        return $a->fresh('employee');
    }

    public function breakStart(HrAttendance $a, ?string $at = null): HrAttendance
    {
        $a->break_start = $at ? Carbon::parse($at) : now();
        $a->save();
        $a->recordAudit('Break Started', null, null, ['time' => optional($a->break_start)->toDateTimeString()]);

        return $a->fresh('employee');
    }

    public function breakEnd(HrAttendance $a, ?string $at = null): HrAttendance
    {
        $a->break_end = $at ? Carbon::parse($at) : now();
        $this->recompute($a);
        $a->save();
        $a->recordAudit('Break Ended', null, null, ['time' => optional($a->break_end)->toDateTimeString()]);

        return $a->fresh('employee');
    }

    /** Manual attendance entry / upsert for an employee + date. */
    public function saveManual(HrEmployee $employee, array $data): HrAttendance
    {
        $record = $this->ensureRecord($employee, $data['date'], $data['shift'] ?? null);
        $this->fillEditable($record, $data);
        $this->recompute($record);
        $record->save();
        $record->recordAudit('Attendance Marked (Manual)', null, $data['remarks'] ?? null, ['status' => $record->status]);

        return $record->fresh('employee');
    }

    /**
     * Upsert one day from an external attendance source (SangoeTrack).
     *
     * Exists because neither saveManual() nor correct() fits a sync:
     *  - both audit as a human action, which is untrue and would bury the real
     *    audit trail under one row per employee per day per run;
     *  - saveManual() never derives the status, because applyStatusFromCheckIn()
     *    is only reached via checkIn(). Feeding a punch through it would leave
     *    every synced day sitting at the 'Absent' that ensureRecord() opens with.
     *
     * So this runs the same private helpers the manual paths use — applyShift via
     * ensureRecord, fillEditable, applyStatusFromCheckIn, recompute — and adds
     * nothing of its own. Status/hours/overtime stay defined in exactly one place.
     *
     * An explicit remote status (Leave, Holiday, Weekend…) wins; otherwise the
     * CRM derives Present/Late from the punch against the shift and grace period.
     *
     * @param  array{date:string, check_in?:?string, check_out?:?string, status?:?string, shift?:?string, remarks?:?string}  $data
     */
    public function syncExternal(HrEmployee $employee, array $data, string $source = 'SangoeTrack'): HrAttendance
    {
        $record = $this->ensureRecord($employee, $data['date'], $data['shift'] ?? $employee->shift ?: null);

        $before = $record->only(['check_in', 'check_out', 'status', 'working_hours', 'overtime_hours']);

        $this->fillEditable($record, $data);

        // Only auto-derive when the source did not state a status; the helper is
        // itself a no-op for Leave/Holiday/Weekend, so this is belt and braces.
        if (empty($data['status'])) {
            $this->applyStatusFromCheckIn($record);
        }

        $this->recompute($record);

        // A no-op day (already synced, nothing moved) must not touch the row or
        // the audit log — daily re-runs over a whole month are the normal case.
        $after = $record->only(['check_in', 'check_out', 'status', 'working_hours', 'overtime_hours']);
        $dirty = $record->isDirty();

        if (! $dirty && $this->sameSnapshot($before, $after)) {
            return $record;
        }

        $record->save();
        $record->recordAudit(
            'Attendance Synced ('.$source.')',
            null,
            null,
            ['status' => $record->status, 'source' => $source],
            $source
        );

        return $record;
    }

    /** Loose comparison of two attendance snapshots (Carbon vs string safe). */
    private function sameSnapshot(array $a, array $b): bool
    {
        foreach ($a as $k => $v) {
            $left  = $v instanceof Carbon ? $v->toDateTimeString() : (string) $v;
            $right = ($b[$k] ?? null) instanceof Carbon ? $b[$k]->toDateTimeString() : (string) ($b[$k] ?? null);
            if ($left !== $right) {
                return false;
            }
        }

        return true;
    }

    /** Correction to an existing record (audited with the changed fields). */
    public function correct(HrAttendance $record, array $data): HrAttendance
    {
        $before = $record->only(['check_in', 'check_out', 'break_start', 'break_end', 'status', 'shift', 'remarks']);
        $this->fillEditable($record, $data);
        $this->recompute($record);
        $record->save();

        $changed = [];
        foreach ($before as $k => $v) {
            if ((string) $v !== (string) $record->{$k}) {
                $changed[$k] = ['from' => $v instanceof Carbon ? $v->toDateTimeString() : $v, 'to' => (string) $record->{$k}];
            }
        }
        $record->recordAudit('Attendance Corrected', null, $data['remarks'] ?? null, $changed);

        return $record->fresh('employee');
    }

    /* ─────────────── Employee profile summary + calendar ─────────────── */

    public function employeeSummary(HrEmployee $employee, ?string $month = null): array
    {
        $anchor = $month ? Carbon::parse($month.'-01') : Carbon::today()->startOfMonth();
        $start  = $anchor->copy()->startOfMonth();
        $end    = $anchor->copy()->endOfMonth();

        $records = HrAttendance::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')
            ->get();

        $today   = HrAttendance::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereDate('date', Carbon::today())
            ->first();

        $considered = $records->whereNotIn('status', ['Weekend', 'Holiday'])->count();
        $presentish = $records->whereIn('status', self::PRESENT_ISH)->count();

        return [
            'month'          => $anchor->format('Y-m'),
            'month_label'    => $anchor->format('F Y'),
            'today'          => $today ? $this->mapDay($today) : null,
            'present_count'  => $records->where('status', 'Present')->count(),
            'late_count'     => $records->where('status', 'Late')->count(),
            'absent_count'   => $records->where('status', 'Absent')->count(),
            'leave_count'    => $records->where('status', 'Leave')->count(),
            'half_day_count' => $records->where('status', 'Half Day')->count(),
            'overtime_hours' => round((float) $records->sum('overtime_hours'), 2),
            'working_hours'  => round((float) $records->sum('working_hours'), 2),
            'attendance_pct' => $considered > 0 ? round($presentish / $considered * 100, 1) : 0.0,
            'calendar'       => $records->map(fn ($r) => $this->mapDay($r))->values()->all(),
        ];
    }

    /* ─────────────── Export ─────────────── */

    /** Flat rows for CSV / Excel export (respects the same filters as the list). */
    public function exportRows(int $tenantId, array $filters): array
    {
        return $this->attendanceRepository->filtered($tenantId, $filters)->map(function ($r) {
            return [
                'Employee'      => $r->employee?->name,
                'Employee ID'   => $r->employee?->employee_code,
                'Department'    => $r->employee?->department,
                'Designation'   => $r->employee?->designation,
                'Shift'         => $r->shift,
                'Date'          => optional($r->date)->toDateString(),
                'Check In'      => optional($r->check_in)->format('H:i'),
                'Check Out'     => optional($r->check_out)->format('H:i'),
                'Break'         => $this->breakLabel($r),
                'Working Hours' => $r->working_hours,
                'Overtime'      => $r->overtime_hours,
                'Status'        => $r->status,
                'Remarks'       => $r->remarks,
            ];
        })->all();
    }

    /* ─────────────── Helpers ─────────────── */

    private function mapDay(HrAttendance $r): array
    {
        return [
            'id'             => $r->id,
            'date'          => optional($r->date)->toDateString(),
            'status'        => $r->status,
            'shift'         => $r->shift,
            'check_in'      => optional($r->check_in)->format('H:i'),
            'check_out'     => optional($r->check_out)->format('H:i'),
            'break_start'   => optional($r->break_start)->format('H:i'),
            'break_end'     => optional($r->break_end)->format('H:i'),
            'working_hours' => $r->working_hours,
            'overtime_hours'=> $r->overtime_hours,
            'remarks'       => $r->remarks,
        ];
    }

    private function breakLabel(HrAttendance $r): ?string
    {
        if ($r->break_start && $r->break_end) {
            return $r->break_start->format('H:i').'–'.$r->break_end->format('H:i');
        }

        return null;
    }

    private function applyShift(HrAttendance $record, string $shift): void
    {
        $preset = HrAttendance::SHIFTS[$shift] ?? HrAttendance::SHIFTS['General'];
        $record->shift = $shift;
        if ($shift !== 'Custom') {
            [$record->shift_start, $record->shift_end, $record->grace_period] = $preset;
        }
    }

    /** Apply editable fields from a manual/correction payload. */
    private function fillEditable(HrAttendance $record, array $data): void
    {
        if (! empty($data['shift'])) {
            $this->applyShift($record, $data['shift']);
        }
        foreach (['shift_start', 'shift_end', 'grace_period', 'remarks'] as $f) {
            if (array_key_exists($f, $data) && $data[$f] !== null) {
                $record->{$f} = $data[$f];
            }
        }
        foreach (['check_in', 'check_out', 'break_start', 'break_end'] as $t) {
            if (array_key_exists($t, $data)) {
                $record->{$t} = $data[$t] ? Carbon::parse($data[$t]) : null;
            }
        }
        if (! empty($data['status'])) {
            $record->status = $data['status'];
        }
    }

    /** Derive Present vs Late from the check-in time against shift start + grace. */
    private function applyStatusFromCheckIn(HrAttendance $a): void
    {
        // Only auto-derive when the day isn't a manual leave/holiday/weekend etc.
        if (! in_array($a->status, ['Absent', 'Present', 'Late'], true)) {
            return;
        }
        if (! $a->check_in || ! $a->shift_start) {
            $a->status = $a->check_in ? 'Present' : $a->status;

            return;
        }
        $limit = Carbon::parse($a->date->toDateString().' '.$a->shift_start)->addMinutes((int) $a->grace_period);
        $a->status = $a->check_in->gt($limit) ? 'Late' : 'Present';
    }

    /** Recompute working hours and overtime from the punch times. */
    private function recompute(HrAttendance $a): void
    {
        if ($a->check_in && $a->check_out && $a->check_out->gt($a->check_in)) {
            // abs() keeps this correct under Carbon 3's signed diffInMinutes.
            $gross = abs($a->check_in->diffInMinutes($a->check_out));
            $break = ($a->break_start && $a->break_end && $a->break_end->gt($a->break_start))
                ? abs($a->break_start->diffInMinutes($a->break_end)) : 0;
            $net = max(0, $gross - $break);
            $a->working_hours  = round($net / 60, 2);
            $a->overtime_hours = round(max(0, ($net / 60) - HrAttendance::STANDARD_HOURS), 2);
        } else {
            $a->working_hours  = $a->working_hours ?? null;
            $a->overtime_hours = $a->overtime_hours ?? null;
        }

        try {
            Log::channel('hr')->debug('Attendance recomputed', ['id' => $a->id, 'working' => $a->working_hours]);
        } catch (\Throwable $e) {
            // logging is best-effort
        }
    }
}
