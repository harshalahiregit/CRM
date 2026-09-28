<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrLeaveApplication;
use App\Services\Hr\LeaveApplicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Leave → Applications (Phase 3). Thin: validate, delegate, return JSON.
 * Reads open to HR users; writes require HR-queue management. Tenant-scoped, audited.
 */
class LeaveApplicationController extends Controller
{
    public const DOC_DISK = 'hr_documents';

    public function __construct(private LeaveApplicationService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list(
            $this->tenant($request),
            $request->only(['employee_id', 'leave_type_id', 'status', 'department', 'from', 'to']),
            $request->user(),
        ));
    }

    /**
     * How many leave days a range works out to, before applying.
     *
     * The count depends on the employee's shift — someone whose weekly off is
     * Tuesday gets a different answer from someone on Sat/Sun — so the breakdown
     * says which days were excluded and why.
     */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'employee_id'   => 'required|integer',
            'from_date'     => 'required|date',
            'to_date'       => 'required|date',
            'leave_type_id' => 'nullable|integer',
            'half_day'      => 'nullable|boolean',
        ]);

        // The employee id arrives in the request body, so it is checked here,
        // like show()/submit()/cancel() below. A preview answers with the
        // employee's shift pattern, their weekly offs and their policy name —
        // small, but it is somebody's working pattern, and an unchecked id also
        // confirms which employee ids exist.
        //
        // On the controller rather than in the service, for the reason given on
        // assertInScope(): the attendance app calls this same service.
        app(\App\Services\Auth\ScopeResolver::class)
            ->assertCanActOnEmployee($request->user(), (int) $data['employee_id']);

        return response()->json($this->service->preview(
            (int) $data['employee_id'], (int) $request->user()->tenant_id,
            $data['from_date'], $data['to_date'],
            $data['leave_type_id'] ?? null, (bool) ($data['half_day'] ?? false),
        ));
    }

    public function show(Request $request, int $id)
    {
        $this->assertInScope($request, $id);

        return response()->json($this->service->show($id, $this->tenant($request)));
    }

    public function store(Request $request)
    {
        $this->can($request);
        $data = $request->validate([
            'employee_id'   => 'required|integer',
            'leave_type_id' => 'required|integer',
            'from_date'     => 'required|date',
            'to_date'       => 'required|date',
            'half_day'      => 'boolean',
            'reason'        => 'nullable|string',
            'status'        => 'nullable|in:Draft,Submitted',
            'attachment'    => 'nullable|file|max:10240',
        ]);

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $data['attachment_path'] = $file->storeAs(
                "hr/documents/leave/tenant_{$this->tenant($request)}",
                Str::random(8).'_'.time().'.'.strtolower($file->getClientOriginalExtension()),
                self::DOC_DISK
            );
        }

        return response()->json($this->service->apply($data, $this->tenant($request), $request->user()), 201);
    }

    public function submit(Request $request, int $id)
    {
        $this->can($request);
        $this->assertInScope($request, $id);

        return response()->json($this->service->submit($id, $this->tenant($request), $request->user()));
    }

    public function cancel(Request $request, int $id)
    {
        $this->can($request);
        $this->assertInScope($request, $id);

        return response()->json($this->service->cancel($id, $this->tenant($request), $request->user()));
    }

    /**
     * Whose application is this, and may this actor touch it?
     *
     * Same reasoning as LeaveApprovalController: the check lives on the CRM's
     * controller rather than inside LeaveApplicationService, because the
     * attendance app calls apply() on that same service and must not change.
     *
     * Permission has already run — this asks only whose record it is.
     */
    private function assertInScope(Request $request, int $id): void
    {
        $employeeId = HrLeaveApplication::where('tenant_id', $this->tenant($request))
            ->whereKey($id)
            ->value('employee_id');

        app(\App\Services\Auth\ScopeResolver::class)
            ->assertCanActOnEmployee($request->user(), $employeeId);
    }

    public function attachment(Request $request, int $id)
    {
        $app = HrLeaveApplication::where('tenant_id', $this->tenant($request))->findOrFail($id);
        // A download is a read, and the file is the most sensitive part of the
        // record — a medical certificate more often than not.
        app(\App\Services\Auth\ScopeResolver::class)
            ->assertCanActOnEmployee($request->user(), $app->employee_id);
        abort_if(empty($app->attachment_path) || ! Storage::disk(self::DOC_DISK)->exists($app->attachment_path), 404, 'No attachment');

        return Storage::disk(self::DOC_DISK)->download($app->attachment_path, 'leave-'.$app->id.'.'.pathinfo($app->attachment_path, PATHINFO_EXTENSION));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage leave applications');
    }
}
