<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\TrainingAttendanceService;
use Illuminate\Http\Request;

/**
 * L&D → Training Attendance (Phase 5). Separate from office attendance / SangoeTrack.
 * Thin: validate, delegate, return JSON. Writes require HR-queue management.
 * Tenant-scoped, audited.
 */
class TrainingAttendanceController extends Controller
{
    public function __construct(private TrainingAttendanceService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['training_session_id', 'employee_id', 'attendance_status', 'department'])));
    }

    public function roster(Request $request, int $session)
    {
        return response()->json($this->service->roster($session, $this->tenant($request)));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request)));
    }

    public function store(Request $request)
    {
        $this->can($request);
        $data = $request->validate([
            'employee_training_id' => 'required_without:records|integer',
            'attendance_status'    => 'required_without:records|in:Present,Absent',
            'check_in'             => 'nullable|date',
            'check_out'            => 'nullable|date',
            'remarks'              => 'nullable|string',
            'training_session_id'  => 'nullable|integer',
            'records'              => 'nullable|array',
            'records.*.employee_training_id' => 'required_with:records|integer',
            'records.*.attendance_status'    => 'required_with:records|in:Present,Absent',
            'records.*.remarks'              => 'nullable|string',
        ]);

        return response()->json($this->service->mark($data, $this->tenant($request), $request->user()), 201);
    }

    public function update(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate([
            'attendance_status' => 'sometimes|required|in:Present,Absent',
            'check_in'          => 'nullable|date',
            'check_out'         => 'nullable|date',
            'remarks'           => 'nullable|string',
        ]);

        return response()->json($this->service->update($id, $data, $this->tenant($request), $request->user()));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage training attendance');
    }
}
