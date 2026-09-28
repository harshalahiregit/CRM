<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrAttendanceCorrection;
use App\Repositories\Hr\Concerns\ScopesEmployeeData;
use App\Services\Hr\AttendanceCorrectionService;
use App\Services\Hr\RequestThreadService;
use Illuminate\Http\Request;

/**
 * Attendance corrections, approver side.
 *
 * PERMISSION is gated on the route group (hr_attendance:view_global), for the
 * reason the reimbursement controller records: a check inside each method is one
 * somebody eventually forgets to add.
 *
 * SCOPE is a separate question, and this controller used to answer it with
 * "tenant". A permission says whether you may work on attendance corrections at
 * all; it does not say whose. A department-scoped HR user held the permission
 * and therefore saw — and could decide — every correction in the workspace,
 * because index() filtered on tenant_id alone and find() did the same. This is
 * the eighth and last operational surface to close that gap.
 *
 * The boundary lives HERE rather than in AttendanceCorrectionService, and
 * deliberately: the attendance app decides corrections through that same
 * service (HrmAdminController::decideCorrection), and a check inside it would
 * change what the phone does. The app already scopes its own decisions by
 * reporting line, through denyDecisionFor(). Guarding the CRM's own controller
 * leaves the app's path untouched — the rule ScopeResolver states and the other
 * seven surfaces follow.
 *
 * Both halves are needed. Narrowing the list while leaving show/approve open
 * holds only until somebody types an id into the URL, which is the first thing
 * anyone tries — so every id-based action goes through find(), and find()
 * asserts.
 *
 * Employees reach their OWN corrections through MyAttendanceCorrectionController,
 * which pins employee_id to the caller's own record and is not scoped by this.
 */
class AttendanceCorrectionController extends Controller
{
    use ScopesEmployeeData;

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
            // Whose corrections this person may see. A global actor resolves to
            // null and the query is left exactly as it was.
            ->tap(fn ($q) => $this->scopeToEmployees($q, $request->user()))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            // No separate guard on the filter: it intersects with the scope
            // above, so an out-of-scope employee_id returns nothing rather than
            // somebody else's rows.
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

    /**
     * The one door every id-based action goes through — show, approve, reject,
     * hold and note. The scope assertion sits here rather than in each of them,
     * so a sixth action added later inherits it.
     *
     * 404, not 403. Refusing with "you may not see this" confirms the record
     * exists and that it belongs to somebody outside your department, which is
     * the thing being withheld. Out of scope reads the same as not there —
     * matching the tenant guard immediately above it.
     */
    private function find(Request $request, int $id): HrAttendanceCorrection
    {
        $correction = HrAttendanceCorrection::where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);

        $this->assertEmployeeInScope($request->user(), $correction->employee_id);

        return $correction;
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
