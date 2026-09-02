<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrEmployee;
use App\Services\Hr\AttendanceService;
use App\Services\Hr\EmployeeIdentityService;
use Illuminate\Http\Request;

/**
 * Clocking yourself in and out.
 *
 * AttendanceController::operate() requires canManageHrQueue AND an employee_id —
 * it exists to record attendance FOR somebody. So there has never been a way for
 * a person to clock themselves in without being handed HR-admin rights over the
 * whole company, which is what has blocked self check-in.
 *
 * The security property here is structural rather than checked: there is no
 * employee_id parameter to send. The employee is resolved from the token, so the
 * only record these endpoints can touch is the caller's own. A permission bug
 * cannot widen that, because the widening does not exist in the shape of the
 * request.
 *
 * Being a linked employee IS the authorisation. Nobody needs a grant to be
 * themselves, and requiring one would ship a feature nobody could use until an
 * admin ticked 200 boxes. The `self` capability stays in the vocabulary for
 * finer control later — turning self-service off for a group, say — rather than
 * as a precondition for it working at all.
 *
 * Every write goes through AttendanceService, the same path the HR screens and
 * the SangoeTrack sync use, so late/overtime/working-hours rules cannot fork
 * between "HR recorded it" and "the employee recorded it".
 */
class MyAttendanceController extends Controller
{
    public function __construct(
        private AttendanceService $attendance,
        private EmployeeIdentityService $identity,
    ) {
    }

    /** Today's record for the caller, plus what they can do next. */
    public function today(Request $request)
    {
        $employee = $this->me($request);
        $record   = HrAttendance::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereDate('date', now()->toDateString())
            ->first();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'employee' => [
                    'id'            => $employee->id,
                    'name'          => $employee->name,
                    'employee_code' => $employee->employee_code,
                ],
                'date'       => now()->toDateString(),
                'attendance' => $record,
                // Derived here so every client agrees on which button to show,
                // rather than each one re-deriving it from nullable columns.
                'can'        => [
                    'check_in'    => $record === null || $record->check_in === null,
                    'check_out'   => $record !== null && $record->check_in !== null && $record->check_out === null,
                    'break_start' => $record !== null && $record->check_in !== null && $record->check_out === null && $record->break_start === null,
                    'break_end'   => $record !== null && $record->break_start !== null && $record->break_end === null,
                ],
            ],
        ]);
    }

    public function checkIn(Request $request)
    {
        return $this->act($request, 'checkIn');
    }

    public function checkOut(Request $request)
    {
        return $this->act($request, 'checkOut');
    }

    public function breakStart(Request $request)
    {
        return $this->act($request, 'breakStart');
    }

    public function breakEnd(Request $request)
    {
        return $this->act($request, 'breakEnd');
    }

    /**
     * The caller's own employee record.
     *
     * 403 rather than 404: the record may well exist, the caller simply is not
     * linked to it. "Not found" would send somebody looking for a missing row
     * when the actual problem is that nobody joined their login to their
     * employee record — the gap Phase 1 exists to close.
     */
    private function me(Request $request): HrEmployee
    {
        $employee = $this->identity->employeeFor($request->user());

        abort_unless(
            $employee,
            403,
            'Your login is not linked to an employee record, so attendance cannot be recorded against it. Contact HR.'
        );

        return $employee;
    }

    private function act(Request $request, string $method)
    {
        $employee = $this->me($request);

        // No employee_id, and no date: you clock in now, for yourself. Recording
        // attendance for another day is a correction, and corrections belong to
        // HR with a reason attached — not to a button on your own dashboard.
        $data = $request->validate([
            'shift' => 'nullable|in:'.implode(',', array_keys(HrAttendance::SHIFTS)),
        ]);

        $record = $this->attendance->ensureRecord($employee, now()->toDateString(), $data['shift'] ?? null);

        return response()->json([
            'status' => 'success',
            'data'   => $this->attendance->{$method}($record),
        ]);
    }
}
