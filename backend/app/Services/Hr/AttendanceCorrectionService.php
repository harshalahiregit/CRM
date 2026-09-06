<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrAttendanceCorrection;
use App\Models\Hr\HrEmployee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fixing a punch that was wrong, or was never made.
 *
 * Approving does two things — decide, and then WRITE — and the row records
 * whether the write happened. An approval that never reached hr_attendance,
 * leaving the day still reading wrong, is the failure worth being able to see
 * rather than having to infer.
 *
 * The write goes through AttendanceService::restampAndSave so status, hours and
 * overtime are derived by the same code as a normal clock-out. Recomputing them
 * here would be a second answer to "how long did they work".
 */
class AttendanceCorrectionService
{
    public function __construct(
        private RequestThreadService $thread,
        private AttendanceService $attendance,
    ) {
    }

    /* ── the employee's side ─────────────────────────────────────────── */

    public function request(HrEmployee $employee, array $data, User $actor): HrAttendanceCorrection
    {
        $date = Carbon::parse($data['attendance_date'])->toDateString();

        if (Carbon::parse($date)->isFuture()) {
            throw new BusinessException('You cannot ask to correct a day that has not happened yet.', 422);
        }

        $times = array_filter([
            'requested_check_in'    => $data['requested_check_in'] ?? null,
            'requested_check_out'   => $data['requested_check_out'] ?? null,
            'requested_break_start' => $data['requested_break_start'] ?? null,
            'requested_break_end'   => $data['requested_break_end'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if (! $times) {
            throw new BusinessException('Say which time should change — a correction with no times asks for nothing.', 422);
        }

        if (isset($times['requested_check_in'], $times['requested_check_out'])
            && $times['requested_check_out'] <= $times['requested_check_in']) {
            throw new BusinessException('The clock-out has to be after the clock-in.', 422);
        }

        // One open request per day. Two people arguing about the same day through
        // two rows is how a day ends up corrected twice.
        $open = HrAttendanceCorrection::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date)
            ->whereIn('status', [HrAttendanceCorrection::PENDING, HrAttendanceCorrection::ON_HOLD])
            ->exists();

        if ($open) {
            throw new BusinessException('You already have a correction open for that day.', 422);
        }

        return DB::transaction(function () use ($employee, $data, $date, $times, $actor) {
            $existing = HrAttendance::where('tenant_id', $employee->tenant_id)
                ->where('employee_id', $employee->id)
                ->whereDate('date', $date)
                ->first();

            $correction = HrAttendanceCorrection::create(array_merge([
                'tenant_id'       => $employee->tenant_id,
                'employee_id'     => $employee->id,
                'attendance_id'   => $existing?->id,
                'attendance_date' => $date,
                'reason'          => $data['reason'],
                'status'          => HrAttendanceCorrection::PENDING,
            ], $times));

            $this->thread->event(
                $correction,
                'submitted',
                'Correction requested for ' . $date . '. ' . $this->describe($times),
                $actor,
                ['date' => $date] + $times
            );

            return $correction;
        });
    }

    /** Answering a hold. Clears it and returns the request to the queue. */
    public function reply(HrAttendanceCorrection $c, User $actor, string $body): HrAttendanceCorrection
    {
        $this->assertOpen($c);

        return DB::transaction(function () use ($c, $actor, $body) {
            $this->thread->message($c, $actor, $body);

            if ($c->isOnHold()) {
                $c->update([
                    'status'    => $c->held_from ?: HrAttendanceCorrection::PENDING,
                    'held_from' => null,
                ]);
                $this->thread->event($c, 'hold_cleared', 'The employee replied, and the request returned to the queue.', $actor);
            }

            return $c->fresh();
        });
    }

    public function withdraw(HrAttendanceCorrection $c, User $actor): HrAttendanceCorrection
    {
        $this->assertOpen($c);

        return DB::transaction(function () use ($c, $actor) {
            $c->update(['status' => HrAttendanceCorrection::REJECTED, 'held_from' => null, 'decided_at' => now()]);
            $this->thread->event($c, 'withdrawn', 'The employee withdrew this request.', $actor);

            return $c->fresh();
        });
    }

    /* ── the approver's side ─────────────────────────────────────────── */

    /**
     * Approve, and write the times onto the day.
     *
     * The attendance row is created when the day has none — a missing punch is
     * the most common thing anybody asks to correct, and refusing because there
     * is nothing to edit would refuse the main case.
     */
    public function approve(HrAttendanceCorrection $c, User $actor, ?string $remarks = null): HrAttendanceCorrection
    {
        $this->assertOpen($c);

        return DB::transaction(function () use ($c, $actor, $remarks) {
            $date = $c->attendance_date->toDateString();

            // whereDate, not an equality match on `date`. The cast persists
            // midnight, so '2026-03-02 00:00:00' never equals '2026-03-02' — a
            // firstOrNew here silently missed the existing row and then hit the
            // (tenant, employee, date) unique constraint instead of updating it.
            $row = HrAttendance::where('tenant_id', $c->tenant_id)
                ->where('employee_id', $c->employee_id)
                ->whereDate('date', $date)
                ->first()
                ?? new HrAttendance([
                    'tenant_id'   => $c->tenant_id,
                    'employee_id' => $c->employee_id,
                    'date'        => $date,
                ]);

            $before = [
                'check_in'  => optional($row->check_in)->toDateTimeString(),
                'check_out' => optional($row->check_out)->toDateTimeString(),
            ];

            // A null in the request means "leave this one alone", never "clear it".
            foreach ($c->requestedTimes() as $field => $time) {
                $row->{$field} = $date . ' ' . $time;
            }

            if (! $row->exists) {
                $row->status = 'Present';
            }

            $row->save();

            // Status, hours and overtime from the same code a normal clock-out uses.
            $row = $this->attendance->restampAndSave($row);

            $c->update([
                'status'        => HrAttendanceCorrection::APPROVED,
                'attendance_id' => $row->id,
                'admin_remarks' => $remarks,
                'decided_by'    => $actor->id,
                'decided_at'    => now(),
                'held_from'     => null,
                'applied'       => true,
            ]);

            $this->thread->event(
                $c,
                'approved',
                'Correction approved and applied to ' . $date . '.' . ($remarks ? ' ' . trim($remarks) : ''),
                $actor,
                ['before' => $before, 'after' => [
                    'check_in'  => optional($row->check_in)->toDateTimeString(),
                    'check_out' => optional($row->check_out)->toDateTimeString(),
                ], 'working_hours' => $row->working_hours]
            );

            return $c->fresh();
        });
    }

    public function reject(HrAttendanceCorrection $c, User $actor, string $remarks): HrAttendanceCorrection
    {
        $this->assertOpen($c);

        if (trim($remarks) === '') {
            throw new BusinessException('Rejecting a correction needs a reason.', 422);
        }

        return DB::transaction(function () use ($c, $actor, $remarks) {
            $c->update([
                'status'        => HrAttendanceCorrection::REJECTED,
                'admin_remarks' => trim($remarks),
                'decided_by'    => $actor->id,
                'decided_at'    => now(),
                'held_from'     => null,
            ]);

            $this->thread->event($c, 'declined', 'Correction rejected. Reason: ' . trim($remarks), $actor, ['reason' => trim($remarks)]);

            return $c->fresh();
        });
    }

    /** Ask the employee something before deciding. */
    public function hold(HrAttendanceCorrection $c, User $actor, string $reason): HrAttendanceCorrection
    {
        $this->assertOpen($c);

        if (trim($reason) === '') {
            throw new BusinessException('A hold needs a reason — the employee has to know what to do about it.', 422);
        }

        return DB::transaction(function () use ($c, $actor, $reason) {
            $c->update([
                'status'    => HrAttendanceCorrection::ON_HOLD,
                'held_from' => $c->isOnHold() ? $c->held_from : $c->status,
            ]);

            $this->thread->event($c, 'held', 'Held. Reason: ' . trim($reason), $actor, ['reason' => trim($reason)]);

            return $c->fresh();
        });
    }

    public function note(HrAttendanceCorrection $c, User $actor, string $body): void
    {
        $this->thread->note($c, $actor, $body);
    }

    /* ── internals ───────────────────────────────────────────────────── */

    private function assertOpen(HrAttendanceCorrection $c): void
    {
        if ($c->is_decided) {
            throw new BusinessException('This correction has already been decided.', 422);
        }
    }

    private function describe(array $times): string
    {
        $said = [];
        foreach ($times as $k => $v) {
            $said[] = str_replace(['requested_', '_'], ['', ' '], $k) . ' ' . $v;
        }

        return $said ? ucfirst(implode(', ', $said)) . '.' : '';
    }
}
