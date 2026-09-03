<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrLeaveApplication;
use App\Services\Hr\EmployeeIdentityService;
use App\Services\Hr\EmployeeLeaveBalanceService;
use App\Services\Hr\LeaveApplicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * An employee's own leave.
 *
 * The existing leave screens are HR's: LeaveApplicationController@store takes an
 * employee_id from the request body and is gated on managing the HR queue, so it
 * is HR filing leave on somebody's behalf. There was no way for a person to
 * apply for their OWN leave in the CRM at all — that only existed in the app,
 * against SangoeTrack.
 *
 * Same structural guarantee as the other `me` controllers: no employee_id is
 * accepted anywhere, so these can only ever reach the caller's own leave, and a
 * guessed id returns 404 rather than somebody else's record.
 *
 * The services are reused unchanged. Nothing here re-implements balance
 * arithmetic or the working-day count — a second implementation of "how many
 * days is this" is a second answer.
 */
class MyLeaveController extends Controller
{
    public function __construct(
        private LeaveApplicationService $leave,
        private EmployeeLeaveBalanceService $balances,
        private EmployeeIdentityService $identity,
    ) {
    }

    /** Everything this person has applied for. */
    public function index(Request $request)
    {
        $me = $this->me($request);

        return response()->json([
            'status' => 'success',
            'data'   => $this->leave->forEmployee($me->id, (int) $me->tenant_id),
        ]);
    }

    /** What they have left to take. */
    public function balances(Request $request)
    {
        $me = $this->me($request);

        return response()->json([
            'status' => 'success',
            'data'   => $this->balances->forEmployee($me->id, (int) $me->tenant_id),
        ]);
    }

    /**
     * How many days a range actually costs, before committing to it.
     *
     * The app shows this while somebody picks dates, and it has to agree with
     * what the server will charge — so it is the same preview() the HR screen
     * uses, not a count done in the client.
     */
    public function preview(Request $request)
    {
        $me = $this->me($request);

        $data = $request->validate([
            'from_date'     => 'required|date',
            'to_date'       => 'required|date|after_or_equal:from_date',
            'leave_type_id' => 'nullable|integer',
            'half_day'      => 'boolean',
        ]);

        return response()->json([
            'status' => 'success',
            'data'   => $this->leave->preview(
                $me->id,
                (int) $me->tenant_id,
                $data['from_date'],
                $data['to_date'],
                $data['leave_type_id'] ?? null,
                (bool) ($data['half_day'] ?? false)
            ),
        ]);
    }

    public function store(Request $request)
    {
        $me = $this->me($request);

        $data = $request->validate([
            'leave_type_id' => 'required|integer',
            'from_date'     => 'required|date',
            'to_date'       => 'required|date|after_or_equal:from_date',
            'half_day'      => 'boolean',
            'reason'        => 'nullable|string|max:2000',
            'attachment'    => 'nullable|file|max:10240|mimes:pdf,png,jpg,jpeg,webp,heic',
        ]);

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $data['attachment_path'] = $file->storeAs(
                "hr/documents/leave/tenant_{$me->tenant_id}",
                Str::random(8).'_'.time().'.'.strtolower($file->getClientOriginalExtension()),
                LeaveApplicationController::DOC_DISK
            );
        }

        // The employee is the caller, full stop. Anything the request said about
        // whose leave this is, is discarded rather than validated — a field that
        // is never read cannot be exploited.
        $data['employee_id'] = $me->id;
        // Submitted, not Draft: somebody applying from a phone means to apply.
        $data['status'] = 'Submitted';

        return response()->json([
            'status'  => 'success',
            'message' => 'Leave applied for.',
            'data'    => $this->leave->apply($data, (int) $me->tenant_id, $request->user()),
        ], 201);
    }

    public function show(Request $request, int $id)
    {
        $this->own($request, $id);

        return response()->json([
            'status' => 'success',
            'data'   => $this->leave->show($id, (int) $this->me($request)->tenant_id),
        ]);
    }

    /** Withdrawing an application of one's own. */
    public function cancel(Request $request, int $id)
    {
        $me = $this->own($request, $id);

        return response()->json([
            'status'  => 'success',
            'message' => 'Leave application withdrawn.',
            'data'    => $this->leave->cancel($id, (int) $me->tenant_id, $request->user()),
        ]);
    }

    /**
     * The supporting document on one's own application.
     *
     * A route rather than a URL for the same reason as every other attachment
     * here: these sit behind a token, and a link the browser follows carries none.
     */
    public function attachment(Request $request, int $id)
    {
        $me  = $this->own($request, $id);
        $row = HrLeaveApplication::where('tenant_id', $me->tenant_id)
            ->where('employee_id', $me->id)
            ->findOrFail($id);

        abort_unless($row->attachment_path, 404, 'No document was attached to this application.');

        return response()->download(
            \Illuminate\Support\Facades\Storage::disk(LeaveApplicationController::DOC_DISK)
                ->path($row->attachment_path)
        );
    }

    /* ── internals ───────────────────────────────────────────────────── */

    private function me(Request $request): HrEmployee
    {
        $employee = $this->identity->employeeFor($request->user());

        abort_unless($employee, 403, 'Your login is not linked to an employee record. Contact HR.');

        return $employee;
    }

    /**
     * Assert the application belongs to the caller, and hand back the employee.
     *
     * Scoped by employee as well as tenant, so a guessed id is a 404 rather than
     * somebody else's medical leave.
     */
    private function own(Request $request, int $id): HrEmployee
    {
        $me = $this->me($request);

        HrLeaveApplication::where('tenant_id', $me->tenant_id)
            ->where('employee_id', $me->id)
            ->findOrFail($id);

        return $me;
    }
}
