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
        return response()->json($this->service->list($this->tenant($request), $request->only(['employee_id', 'leave_type_id', 'status', 'department', 'from', 'to'])));
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

        return response()->json($this->service->preview(
            (int) $data['employee_id'], (int) $request->user()->tenant_id,
            $data['from_date'], $data['to_date'],
            $data['leave_type_id'] ?? null, (bool) ($data['half_day'] ?? false),
        ));
    }

    public function show(Request $request, int $id)
    {
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

        return response()->json($this->service->submit($id, $this->tenant($request), $request->user()));
    }

    public function cancel(Request $request, int $id)
    {
        $this->can($request);

        return response()->json($this->service->cancel($id, $this->tenant($request), $request->user()));
    }

    public function attachment(Request $request, int $id)
    {
        $app = HrLeaveApplication::where('tenant_id', $this->tenant($request))->findOrFail($id);
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
