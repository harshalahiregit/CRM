<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeShift;
use App\Models\Hr\HrShift;
use App\Models\Hr\HrShiftRotation;
use App\Models\Hr\HrShiftRotationStep;
use App\Models\Hr\HrShiftTiming;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shift Management: masters, weekday timings (which carry the weekly off),
 * rotation plans, and effective-dated employee assignment.
 *
 * Assignment and history are the same table. Assigning a shift CLOSES the previous
 * assignment the day before the new one starts rather than deleting it, so the
 * record of who worked what, when, survives every change.
 *
 * Nothing here writes to `hr_employees.shift` or `hr_attendance.shift`. Those free
 * text columns keep their existing meaning; this is the structured source that new
 * code reads, and `shiftForDate()` is the single entry point for "which shift, and
 * is this day off?".
 */
class ShiftService
{
    /* ── Shift master ─────────────────────────────────────────────────── */

    public function shifts(int $tenantId, array $filters = []): array
    {
        $q = HrShift::forTenant($tenantId)->with('timings');

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['shift_type'])) {
            $q->where('shift_type', $filters['shift_type']);
        }

        return $q->orderBy('name')->get()->map(fn ($s) => $this->presentShift($s))->all();
    }

    public function showShift(int $id, int $tenantId): array
    {
        return $this->presentShift($this->findShift($id, $tenantId));
    }

    public function createShift(array $data, int $tenantId, ?User $actor = null): array
    {
        $shift = DB::transaction(function () use ($data, $tenantId, $actor) {
            $shift = HrShift::create($this->shiftAttributes($data) + [
                'tenant_id' => $tenantId, 'created_by' => $actor?->id,
            ]);
            $this->syncTimings($shift, $data['timings'] ?? null, $tenantId);

            return $shift;
        });

        $shift->recordAudit('Shift Created', $actor, null, ['name' => $shift->name]);
        $this->log('Shift created', $tenantId, $shift->id);

        return $this->presentShift($shift->load('timings'));
    }

    public function updateShift(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $shift = $this->findShift($id, $tenantId);

        DB::transaction(function () use ($shift, $data, $tenantId, $actor) {
            $shift->update($this->shiftAttributes($data) + ['updated_by' => $actor?->id]);
            if (array_key_exists('timings', $data)) {
                $this->syncTimings($shift, $data['timings'], $tenantId);
            }
        });

        $shift->recordAudit('Shift Updated', $actor);

        return $this->presentShift($shift->fresh('timings'));
    }

    /**
     * Deleting a shift that is or was assigned would orphan the history that
     * explains someone's past attendance. Deactivate instead.
     */
    public function deleteShift(int $id, int $tenantId, ?User $actor = null): void
    {
        $shift = $this->findShift($id, $tenantId);

        if (HrEmployeeShift::forTenant($tenantId)->where('shift_id', $id)->exists()) {
            throw new BusinessException('This shift has been assigned to employees. Deactivate it instead of deleting it.');
        }
        if (HrShiftRotationStep::forTenant($tenantId)->where('shift_id', $id)->exists()) {
            throw new BusinessException('This shift is used by a rotation plan. Remove it from the plan first.');
        }

        $shift->recordAudit('Shift Deleted', $actor, null, ['name' => $shift->name]);
        $shift->timings()->delete();
        $shift->delete();
    }

    /* ── Rotation plans ───────────────────────────────────────────────── */

    public function rotations(int $tenantId): array
    {
        return HrShiftRotation::forTenant($tenantId)->with('steps.shift:id,name,code')
            ->orderBy('name')->get()->map(fn ($r) => $this->presentRotation($r))->all();
    }

    public function saveRotation(?int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $rotation = DB::transaction(function () use ($id, $data, $tenantId, $actor) {
            $attrs = [
                'name' => $data['name'] ?? null, 'code' => $data['code'] ?? null,
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ];

            if ($id) {
                $rotation = HrShiftRotation::forTenant($tenantId)->find($id);
                if (! $rotation) {
                    throw new BusinessException('Rotation plan not found', 404);
                }
                $rotation->update(array_filter($attrs, fn ($v) => $v !== null) + ['updated_by' => $actor?->id]);
            } else {
                $rotation = HrShiftRotation::create($attrs + ['tenant_id' => $tenantId, 'created_by' => $actor?->id]);
            }

            if (array_key_exists('steps', $data)) {
                $rotation->steps()->delete();
                foreach (array_values($data['steps'] ?? []) as $i => $step) {
                    $this->findShift((int) $step['shift_id'], $tenantId);   // tenant guard
                    HrShiftRotationStep::create([
                        'tenant_id' => $tenantId, 'rotation_id' => $rotation->id,
                        'shift_id' => (int) $step['shift_id'], 'sequence' => $i,
                        'duration_days' => max(1, (int) ($step['duration_days'] ?? 7)),
                    ]);
                }
            }

            return $rotation;
        });

        $rotation->recordAudit($id ? 'Rotation Updated' : 'Rotation Created', $actor);

        return $this->presentRotation($rotation->fresh('steps.shift'));
    }

    public function deleteRotation(int $id, int $tenantId, ?User $actor = null): void
    {
        $rotation = HrShiftRotation::forTenant($tenantId)->find($id);
        if (! $rotation) {
            throw new BusinessException('Rotation plan not found', 404);
        }
        if (HrEmployeeShift::forTenant($tenantId)->where('rotation_id', $id)->exists()) {
            throw new BusinessException('This rotation is assigned to employees. Deactivate it instead of deleting it.');
        }

        $rotation->recordAudit('Rotation Deleted', $actor);
        $rotation->steps()->delete();
        $rotation->delete();
    }

    /* ── Assignment (== history) ──────────────────────────────────────── */

    /**
     * Assign a shift or rotation from a date.
     *
     * The previous open assignment is CLOSED the day before, never deleted — that
     * closed row is the history. Two open assignments would make "which shift is
     * this employee on?" ambiguous, so the close is not optional.
     */
    public function assign(array $data, int $tenantId, ?User $actor = null): array
    {
        $employee = HrEmployee::where('tenant_id', $tenantId)->find((int) ($data['employee_id'] ?? 0));
        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }

        $shiftId    = $data['shift_id'] ?? null;
        $rotationId = $data['rotation_id'] ?? null;

        if ((bool) $shiftId === (bool) $rotationId) {
            throw new BusinessException('Assign either a shift or a rotation plan — not both, and not neither.');
        }
        if ($shiftId) {
            $this->findShift((int) $shiftId, $tenantId);
        } else {
            $rotation = HrShiftRotation::forTenant($tenantId)->with('steps')->find((int) $rotationId);
            if (! $rotation) {
                throw new BusinessException('Rotation plan not found', 404);
            }
            if ($rotation->steps->isEmpty()) {
                throw new BusinessException('This rotation plan has no steps, so it would never resolve to a shift.');
            }
        }

        $from = Carbon::parse($data['effective_from'] ?? now())->startOfDay();

        $assignment = DB::transaction(function () use ($employee, $tenantId, $shiftId, $rotationId, $from, $data, $actor) {
            $current = $this->currentAssignmentQuery($employee->id, $tenantId)->first();

            if ($current) {
                if ($current->effective_from->gt($from)) {
                    throw new BusinessException(
                        'This employee already has an assignment starting '
                        .$current->effective_from->toDateString().'. Choose a later date.'
                    );
                }
                $current->update(['effective_to' => $from->copy()->subDay()->toDateString()]);
            }

            return HrEmployeeShift::create([
                'tenant_id' => $tenantId, 'employee_id' => $employee->id,
                'shift_id' => $shiftId ?: null, 'rotation_id' => $rotationId ?: null,
                'effective_from' => $from->toDateString(),
                'reason' => $data['reason'] ?? null, 'assigned_by' => $actor?->id,
            ]);
        });

        $assignment->recordAudit('Shift Assigned', $actor, $data['reason'] ?? null, [
            'employee' => $employee->name, 'from' => $from->toDateString(),
        ]);
        $this->log('Shift assigned', $tenantId, $assignment->id);

        return $this->presentAssignment($assignment->load(['shift', 'rotation']));
    }

    /** Full assignment history for one employee, newest first. */
    public function history(int $employeeId, int $tenantId): array
    {
        return HrEmployeeShift::forTenant($tenantId)
            ->where('employee_id', $employeeId)
            ->with(['shift:id,name,code,shift_type', 'rotation:id,name'])
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->get()->map(fn ($a) => $this->presentAssignment($a))->all();
    }

    /** Current assignment for every employee — the roster view. */
    public function roster(int $tenantId, array $filters = []): array
    {
        $q = HrEmployeeShift::forTenant($tenantId)
            ->whereNull('effective_to')
            ->with(['employee:id,name,employee_code,department,designation', 'shift:id,name,code,shift_type', 'rotation:id,name']);

        if (! empty($filters['shift_id'])) {
            $q->where('shift_id', (int) $filters['shift_id']);
        }

        return $q->get()
            ->filter(fn ($a) => $a->employee !== null)
            ->map(fn ($a) => $this->presentAssignment($a) + [
                'employee_name' => $a->employee->name,
                'employee_code' => $a->employee->employee_code,
                'department'    => $a->employee->department,
                'designation'   => $a->employee->designation,
            ])->values()->all();
    }

    /* ── The one resolver everything else should use ──────────────────── */

    /**
     * Which shift an employee is on for a given date, and whether that date is a
     * weekly off.
     *
     * Returns null `shift` when nothing is assigned — the caller then keeps doing
     * whatever it did before shifts existed, which is what makes this additive.
     *
     * For a ROTATION, the shift is derived from how far the date sits into the
     * cycle: the assignment's start date is day 0, and the steps repeat.
     */
    public function shiftForDate(int $employeeId, int $tenantId, $date): array
    {
        $day = Carbon::parse($date)->startOfDay();

        $assignment = HrEmployeeShift::forTenant($tenantId)
            ->where('employee_id', $employeeId)
            ->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->orderByDesc('effective_from')
            ->with(['shift.timings', 'rotation.steps.shift.timings'])
            ->first();

        if (! $assignment) {
            return ['shift' => null, 'is_week_off' => false, 'timing' => null,
                    'reason' => 'No shift assigned for this date'];
        }

        $shift = $assignment->shift ?: $this->rotationShiftFor($assignment, $day);

        if (! $shift) {
            return ['shift' => null, 'is_week_off' => false, 'timing' => null,
                    'reason' => 'The rotation plan resolved to no shift'];
        }

        $timing = $shift->timings->firstWhere('day_of_week', (int) $day->dayOfWeek);
        // Week of the month by calendar position (1st–7th = week 1), which is how
        // "2nd Saturday" is meant, not by ISO week number.
        $weekOfMonth = (int) ceil($day->day / 7);

        return [
            'shift'       => $shift,
            'is_week_off' => $timing ? $timing->isOffInWeek($weekOfMonth) : false,
            'timing'      => $timing,
            'reason'      => null,
        ];
    }

    /** Convenience for callers that only need the yes/no. */
    public function isWeekOff(int $employeeId, int $tenantId, $date): bool
    {
        return (bool) $this->shiftForDate($employeeId, $tenantId, $date)['is_week_off'];
    }

    /**
     * Is this a non-working day for this employee?
     *
     * THE single answer to that question — Leave and Attendance both call it, so
     * they can never disagree about whether someone was due at work.
     *
     * The fallback is the whole point of the method. An employee with a shift gets
     * their shift's pattern (including alternate Saturdays and whichever leg of a
     * rotation applies that day). An employee with NO assignment falls back to
     * Carbon's Saturday/Sunday, which is exactly what every caller did before
     * shifts existed — so nothing changes for them until someone assigns a shift.
     *
     * `source` is returned so callers can say WHY a day was excluded rather than
     * leaving a leave-day count unexplainable.
     *
     * @return array{off: bool, source: string, shift: ?HrShift, timing: ?HrShiftTiming}
     */
    public function isOffDay(int $employeeId, int $tenantId, $date): array
    {
        $resolved = $this->shiftForDate($employeeId, $tenantId, $date);

        if ($resolved['shift']) {
            return [
                'off'    => (bool) $resolved['is_week_off'],
                'source' => 'shift',
                'shift'  => $resolved['shift'],
                'timing' => $resolved['timing'],
            ];
        }

        return [
            'off'    => Carbon::parse($date)->isWeekend(),
            'source' => 'default_weekend',
            'shift'  => null,
            'timing' => null,
        ];
    }

    /**
     * isOffDay() for a whole date range, resolved in ONE pass.
     *
     * A leave application spanning a month would otherwise re-query the assignment
     * and its rotation steps once per day. The assignment is looked up once here
     * and the per-day answer derived from it.
     *
     * @return array<string, array{off: bool, source: string, shift_name: ?string}>  keyed by Y-m-d
     */
    public function offDaysBetween(int $employeeId, int $tenantId, $from, $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end   = Carbon::parse($to)->startOfDay();
        $out   = [];

        // Every assignment overlapping the range, newest first — the same ordering
        // shiftForDate() uses, so a day resolves identically either way.
        $assignments = HrEmployeeShift::forTenant($tenantId)
            ->where('employee_id', $employeeId)
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))
            ->orderByDesc('effective_from')
            ->with(['shift.timings', 'rotation.steps.shift.timings'])
            ->get();

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $assignment = $assignments->first(fn ($a) => $a->effective_from->lte($day)
                && ($a->effective_to === null || $a->effective_to->gte($day)));

            $shift = $assignment
                ? ($assignment->shift ?: $this->rotationShiftFor($assignment, $day))
                : null;

            if (! $shift) {
                $out[$day->toDateString()] = [
                    'off' => $day->isWeekend(), 'source' => 'default_weekend', 'shift_name' => null,
                ];
                continue;
            }

            $timing = $shift->timings->firstWhere('day_of_week', (int) $day->dayOfWeek);
            $out[$day->toDateString()] = [
                'off'        => $timing ? $timing->isOffInWeek((int) ceil($day->day / 7)) : false,
                'source'     => 'shift',
                'shift_name' => $shift->name,
            ];
        }

        return $out;
    }

    /**
     * Where in the rotation cycle a date falls.
     *
     * Days elapsed since the assignment began, modulo the cycle length, then walked
     * through the steps. A zero-length cycle would divide by zero, so it returns null.
     */
    private function rotationShiftFor(HrEmployeeShift $assignment, Carbon $day): ?HrShift
    {
        $rotation = $assignment->rotation;
        if (! $rotation || $rotation->steps->isEmpty()) {
            return null;
        }

        $cycle = (int) $rotation->steps->sum('duration_days');
        if ($cycle <= 0) {
            return null;
        }

        $elapsed = (int) $assignment->effective_from->startOfDay()->diffInDays($day);
        $offset  = (($elapsed % $cycle) + $cycle) % $cycle;   // safe for dates before the start

        foreach ($rotation->steps as $step) {
            $offset -= (int) $step->duration_days;
            if ($offset < 0) {
                return $step->shift;
            }
        }

        return $rotation->steps->last()?->shift;
    }

    /* ── Helpers ──────────────────────────────────────────────────────── */

    private function shiftAttributes(array $data): array
    {
        return array_filter([
            'name'              => $data['name'] ?? null,
            'code'              => $data['code'] ?? null,
            'shift_type'        => $data['shift_type'] ?? null,
            'is_night_shift'    => $data['is_night_shift'] ?? null,
            'grace_in_minutes'  => $data['grace_in_minutes'] ?? null,
            'grace_out_minutes' => $data['grace_out_minutes'] ?? null,
            'break_minutes'     => $data['break_minutes'] ?? null,
            'full_day_hours'    => $data['full_day_hours'] ?? null,
            'half_day_hours'    => $data['half_day_hours'] ?? null,
            'description'       => $data['description'] ?? null,
            'is_active'         => $data['is_active'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Replace the weekday rows wholesale.
     *
     * A partial merge would leave a stale weekly off behind when a day is removed
     * from the payload, and a stale day off is a day someone does not get paid for.
     */
    private function syncTimings(HrShift $shift, ?array $timings, int $tenantId): void
    {
        if ($timings === null) {
            return;
        }

        $shift->timings()->delete();

        foreach ($timings as $t) {
            $day = (int) ($t['day_of_week'] ?? -1);
            if ($day < 0 || $day > 6) {
                throw new BusinessException('Day of week must be 0 (Sunday) to 6 (Saturday).');
            }
            $isOff = (bool) ($t['is_week_off'] ?? false);

            HrShiftTiming::create([
                'tenant_id'    => $tenantId,
                'shift_id'     => $shift->id,
                'day_of_week'  => $day,
                'start_time'   => $isOff ? null : ($t['start_time'] ?? null),
                'end_time'     => $isOff ? null : ($t['end_time'] ?? null),
                'is_week_off'  => $isOff,
                'week_numbers' => $isOff ? array_values(array_map('intval', $t['week_numbers'] ?? [])) : null,
            ]);
        }
    }

    private function currentAssignmentQuery(int $employeeId, int $tenantId)
    {
        return HrEmployeeShift::forTenant($tenantId)
            ->where('employee_id', $employeeId)
            ->whereNull('effective_to')
            ->orderByDesc('effective_from');
    }

    private function findShift(int $id, int $tenantId): HrShift
    {
        $shift = HrShift::forTenant($tenantId)->with('timings')->find($id);
        if (! $shift) {
            throw new BusinessException('Shift not found', 404);
        }

        return $shift;
    }

    private function presentShift(HrShift $s): array
    {
        return [
            'id' => $s->id, 'name' => $s->name, 'code' => $s->code,
            'shift_type' => $s->shift_type, 'is_night_shift' => (bool) $s->is_night_shift,
            'grace_in_minutes' => (int) $s->grace_in_minutes,
            'grace_out_minutes' => (int) $s->grace_out_minutes,
            'break_minutes' => (int) $s->break_minutes,
            'full_day_hours' => $s->full_day_hours !== null ? (float) $s->full_day_hours : null,
            'half_day_hours' => $s->half_day_hours !== null ? (float) $s->half_day_hours : null,
            'description' => $s->description, 'is_active' => (bool) $s->is_active,
            'timings' => $s->relationLoaded('timings') ? $s->timings->map(fn ($t) => [
                'day_of_week' => $t->day_of_week, 'day_name' => $t->dayName(),
                'start_time' => $t->start_time, 'end_time' => $t->end_time,
                'is_week_off' => (bool) $t->is_week_off,
                'week_numbers' => $t->week_numbers ?: [],
            ])->all() : [],
            'week_offs' => $s->relationLoaded('timings')
                ? $s->timings->where('is_week_off', true)->map(fn ($t) => $t->dayName())->values()->all()
                : [],
        ];
    }

    private function presentRotation(HrShiftRotation $r): array
    {
        return [
            'id' => $r->id, 'name' => $r->name, 'code' => $r->code,
            'description' => $r->description, 'is_active' => (bool) $r->is_active,
            'cycle_days' => $r->relationLoaded('steps') ? $r->cycleDays() : null,
            'steps' => $r->relationLoaded('steps') ? $r->steps->map(fn ($s) => [
                'id' => $s->id, 'shift_id' => $s->shift_id, 'shift_name' => $s->shift?->name,
                'sequence' => $s->sequence, 'duration_days' => $s->duration_days,
            ])->all() : [],
        ];
    }

    private function presentAssignment(HrEmployeeShift $a): array
    {
        return [
            'id' => $a->id, 'employee_id' => $a->employee_id,
            'shift_id' => $a->shift_id, 'shift_name' => $a->shift?->name,
            'shift_type' => $a->shift?->shift_type,
            'rotation_id' => $a->rotation_id, 'rotation_name' => $a->rotation?->name,
            'effective_from' => optional($a->effective_from)->toDateString(),
            'effective_to' => optional($a->effective_to)->toDateString(),
            'is_current' => $a->isCurrent(),
            'reason' => $a->reason,
        ];
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
