<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrEmployee;
use App\Services\Hr\AttendanceService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    public function __construct(private AttendanceService $attendanceService)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->attendanceService->list($request->user()->tenant_id, $request->only(['date', 'status', 'shift', 'department', 'designation', 'search']))
        );
    }

    public function stats(Request $request)
    {
        return response()->json(
            $this->attendanceService->dashboardStats($request->user()->tenant_id, $request->query('date'))
        );
    }

    /** Manual attendance entry / upsert for an employee + date. */
    public function storeManual(Request $request)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'employee_id' => 'required|integer',
            'date'        => 'required|date',
            'status'      => 'required|in:'.implode(',', HrAttendance::STATUSES),
            'shift'       => 'nullable|in:'.implode(',', array_keys(HrAttendance::SHIFTS)),
            'shift_start' => 'nullable|string',
            'shift_end'   => 'nullable|string',
            'grace_period' => 'nullable|integer|min:0|max:180',
            'check_in'    => 'nullable|date',
            'check_out'   => 'nullable|date',
            'break_start' => 'nullable|date',
            'break_end'   => 'nullable|date',
            'remarks'     => 'nullable|string|max:1000',
        ]);

        $employee = $this->resolveEmployee($request, $data['employee_id']);

        return response()->json($this->attendanceService->saveManual($employee, $data), 201);
    }

    public function checkIn(Request $request)
    {
        return $this->operate($request, 'checkIn');
    }

    public function checkOut(Request $request)
    {
        return $this->operate($request, 'checkOut');
    }

    public function breakStart(Request $request)
    {
        return $this->operate($request, 'breakStart');
    }

    public function breakEnd(Request $request)
    {
        return $this->operate($request, 'breakEnd');
    }

    /** Correction to an existing attendance record. */
    public function correct(Request $request, HrAttendance $attendance)
    {
        $this->assertTenant($request, $attendance);
        $this->assertCanManage($request);

        $data = $request->validate([
            'status'      => 'nullable|in:'.implode(',', HrAttendance::STATUSES),
            'shift'       => 'nullable|in:'.implode(',', array_keys(HrAttendance::SHIFTS)),
            'shift_start' => 'nullable|string',
            'shift_end'   => 'nullable|string',
            'grace_period' => 'nullable|integer|min:0|max:180',
            'check_in'    => 'nullable|date',
            'check_out'   => 'nullable|date',
            'break_start' => 'nullable|date',
            'break_end'   => 'nullable|date',
            'remarks'     => 'nullable|string|max:1000',
        ]);

        return response()->json($this->attendanceService->correct($attendance, $data));
    }

    /** Employee profile Attendance tab — summary + monthly calendar. */
    public function employeeAttendance(Request $request, HrEmployee $employee)
    {
        abort_unless((int) $employee->tenant_id === (int) $request->user()->tenant_id, 404, 'Employee not found');

        return response()->json($this->attendanceService->employeeSummary($employee, $request->query('month')));
    }

    /** CSV / Excel export (same filters as the list). */
    public function export(Request $request): StreamedResponse
    {
        $rows = $this->attendanceService->exportRows($request->user()->tenant_id, $request->only(['date', 'status', 'shift', 'department', 'designation', 'search']));
        $filename = 'attendance-'.($request->query('date') ?: now()->toDateString()).'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Employee', 'Employee ID', 'Department', 'Designation', 'Shift', 'Date', 'Check In', 'Check Out', 'Break', 'Working Hours', 'Overtime', 'Status', 'Remarks']);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /* ─────────────── Helpers ─────────────── */

    private function operate(Request $request, string $method)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'employee_id' => 'required|integer',
            'date'        => 'nullable|date',
            'at'          => 'nullable|date',
            'shift'       => 'nullable|in:'.implode(',', array_keys(HrAttendance::SHIFTS)),
        ]);

        $employee = $this->resolveEmployee($request, $data['employee_id']);
        $date     = $data['date'] ?? now()->toDateString();
        $record   = $this->attendanceService->ensureRecord($employee, $date, $data['shift'] ?? null);

        return response()->json($this->attendanceService->{$method}($record, $data['at'] ?? null));
    }

    private function resolveEmployee(Request $request, int $employeeId): HrEmployee
    {
        $employee = HrEmployee::where('tenant_id', $request->user()->tenant_id)->find($employeeId);
        abort_unless($employee, 404, 'Employee not found');

        return $employee;
    }

    private function assertTenant(Request $request, HrAttendance $attendance): void
    {
        abort_unless((int) $attendance->tenant_id === (int) $request->user()->tenant_id, 404, 'Attendance record not found');
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage attendance');
    }
}
