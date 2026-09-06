<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrAttendanceCorrection;
use App\Models\Hr\HrEmployee;
use App\Services\Hr\AttendanceCorrectionService;
use App\Services\Hr\EmployeeIdentityService;
use App\Services\Hr\RequestThreadService;
use Illuminate\Http\Request;

/**
 * An employee asking for their own punch to be fixed.
 *
 * No employee_id anywhere, same as every other `me` controller here: a guessed
 * id is a 404 rather than somebody else's timesheet.
 */
class MyAttendanceCorrectionController extends Controller
{
    public function __construct(
        private AttendanceCorrectionService $corrections,
        private RequestThreadService $thread,
        private EmployeeIdentityService $identity,
    ) {
    }

    public function index(Request $request)
    {
        $me = $this->me($request);

        return response()->json([
            'status' => 'success',
            'data'   => HrAttendanceCorrection::where('tenant_id', $me->tenant_id)
                ->where('employee_id', $me->id)
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    /**
     * The day as it currently stands, so somebody can see what they are correcting.
     *
     * Asking for a fix without being shown the existing times invites requests
     * that change nothing.
     */
    public function day(Request $request)
    {
        $me = $this->me($request);
        $data = $request->validate(['date' => 'required|date|before_or_equal:today']);

        $row = HrAttendance::where('tenant_id', $me->tenant_id)
            ->where('employee_id', $me->id)
            ->whereDate('date', $data['date'])
            ->first();

        return response()->json([
            'status' => 'success',
            'data'   => ['date' => $data['date'], 'attendance' => $row],
        ]);
    }

    public function store(Request $request)
    {
        $me = $this->me($request);

        $data = $request->validate([
            'attendance_date'       => 'required|date|before_or_equal:today',
            'requested_check_in'    => 'nullable|date_format:H:i',
            'requested_check_out'   => 'nullable|date_format:H:i',
            'requested_break_start' => 'nullable|date_format:H:i',
            'requested_break_end'   => 'nullable|date_format:H:i',
            'reason'                => 'required|string|max:2000',
        ]);

        $correction = $this->corrections->request($me, $data, $request->user());

        return response()->json([
            'status'  => 'success',
            'message' => 'Correction requested.',
            'data'    => $correction,
        ], 201);
    }

    public function show(Request $request, int $id)
    {
        $c = $this->own($request, $id);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'correction' => $c,
                'thread'     => $this->thread->forSubject($c, asEmployee: true),
                'can'        => [
                    'reply'    => ! $c->is_decided,
                    'withdraw' => ! $c->is_decided,
                ],
            ],
        ]);
    }

    public function reply(Request $request, int $id)
    {
        $data = $request->validate(['body' => 'required|string|max:2000']);
        $c = $this->corrections->reply($this->own($request, $id), $request->user(), $data['body']);

        return $this->view($c, 'Sent.');
    }

    public function withdraw(Request $request, int $id)
    {
        return $this->view(
            $this->corrections->withdraw($this->own($request, $id), $request->user()),
            'Request withdrawn.'
        );
    }

    /* ── internals ───────────────────────────────────────────────────── */

    private function me(Request $request): HrEmployee
    {
        $employee = $this->identity->employeeFor($request->user());

        abort_unless($employee, 403, 'Your login is not linked to an employee record. Contact HR.');

        return $employee;
    }

    private function own(Request $request, int $id): HrAttendanceCorrection
    {
        $me = $this->me($request);

        return HrAttendanceCorrection::where('tenant_id', $me->tenant_id)
            ->where('employee_id', $me->id)
            ->findOrFail($id);
    }

    private function view(HrAttendanceCorrection $c, string $message)
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => ['correction' => $c, 'thread' => $this->thread->forSubject($c, asEmployee: true)],
        ]);
    }
}
