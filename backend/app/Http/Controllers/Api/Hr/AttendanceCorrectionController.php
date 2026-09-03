<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrAttendanceCorrection;
use App\Services\Hr\AttendanceCorrectionService;
use App\Services\Hr\RequestThreadService;
use Illuminate\Http\Request;

/**
 * Attendance corrections, approver side.
 *
 * Gated on the route GROUP rather than per method, for the reason the
 * reimbursement controller records: index() lists the whole tenant, and a check
 * inside each method is one somebody eventually forgets to add.
 */
class AttendanceCorrectionController extends Controller
{
    public function __construct(
        private AttendanceCorrectionService $corrections,
        private RequestThreadService $thread,
    ) {
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'status'      => 'nullable|in:' . implode(',', HrAttendanceCorrection::ALL),
            'employee_id' => 'nullable|integer',
            'from'        => 'nullable|date',
            'to'          => 'nullable|date',
        ]);

        $rows = HrAttendanceCorrection::where('tenant_id', $request->user()->tenant_id)
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['employee_id'] ?? null, fn ($q, $e) => $q->where('employee_id', $e))
            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('attendance_date', '>=', $d))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('attendance_date', '<=', $d))
            ->with(['employee:id,name,employee_code,department', 'attendance'])
            // Anything still waiting on a person first.
            ->orderByRaw("CASE WHEN status IN ('pending','on_hold') THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get();

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function show(Request $request, int $id)
    {
        $c = $this->find($request, $id);

        return response()->json([
            'status' => 'success',
            'data'   => [
                // The day as it stands, beside what is being asked for — deciding
                // without seeing the current times is deciding blind.
                'correction' => $c->load(['employee:id,name,employee_code,department', 'attendance']),
                'thread'     => $this->thread->forSubject($c, asEmployee: false),
                'can'        => [
                    'approve' => ! $c->is_decided,
                    'reject'  => ! $c->is_decided,
                    'hold'    => ! $c->is_decided,
                ],
            ],
        ]);
    }

    public function approve(Request $request, int $id)
    {
        $data = $request->validate(['remarks' => 'nullable|string|max:1000']);

        return $this->acted(
            $this->corrections->approve($this->find($request, $id), $request->user(), $data['remarks'] ?? null),
            'Approved, and the day has been updated.'
        );
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['remarks' => 'required|string|max:1000']);

        return $this->acted(
            $this->corrections->reject($this->find($request, $id), $request->user(), $data['remarks']),
            'Correction rejected.'
        );
    }

    public function hold(Request $request, int $id)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);

        return $this->acted(
            $this->corrections->hold($this->find($request, $id), $request->user(), $data['reason']),
            'Put on hold. The employee has been asked to respond.'
        );
    }

    public function note(Request $request, int $id)
    {
        $data = $request->validate(['body' => 'required|string|max:2000']);

        $c = $this->find($request, $id);
        $this->corrections->note($c, $request->user(), $data['body']);

        return response()->json([
            'status'  => 'success',
            'message' => 'Note added. The employee cannot see it.',
            'data'    => ['thread' => $this->thread->forSubject($c, asEmployee: false)],
        ]);
    }

    private function find(Request $request, int $id): HrAttendanceCorrection
    {
        return HrAttendanceCorrection::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }

    private function acted(HrAttendanceCorrection $c, string $message)
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => ['correction' => $c, 'thread' => $this->thread->forSubject($c, asEmployee: false)],
        ]);
    }
}
