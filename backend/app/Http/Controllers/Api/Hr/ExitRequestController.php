<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrExitRequest;
use App\Services\Hr\ExitRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Exit Management → Exit Requests (Phase 2). Thin: validate, delegate, return JSON.
 * Reads open to HR users; writes require HR-queue management. Tenant-scoped, audited.
 */
class ExitRequestController extends Controller
{
    public const DOC_DISK = 'hr_documents';

    public function __construct(private ExitRequestService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['employee_id', 'exit_type_id', 'status', 'search'])));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request), $request->user()));
    }

    /** Employee Profile → Exit tab: read-only current (non-withdrawn) exit request. */
    public function forEmployee(Request $request, int $employee)
    {
        return response()->json($this->service->currentForEmployee($employee, $this->tenant($request)));
    }

    public function store(Request $request)
    {
        $this->can($request);
        $data = $this->validated($request);
        $data = $this->withAttachment($request, $data);

        return response()->json($this->service->create($data, $this->tenant($request), $request->user()), 201);
    }

    public function update(Request $request, int $id)
    {
        $this->can($request);
        $data = $this->validated($request, true);
        $data = $this->withAttachment($request, $data);

        return response()->json($this->service->update($id, $data, $this->tenant($request), $request->user()));
    }

    public function submit(Request $request, int $id)
    {
        $this->can($request);

        return response()->json($this->service->submit($id, $this->tenant($request), $request->user()));
    }

    public function withdraw(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate([
            'reason'     => 'nullable|string',
            'hr_remarks' => 'nullable|string',
        ]);

        return response()->json($this->service->withdraw($id, $data, $this->tenant($request), $request->user()));
    }

    public function attachment(Request $request, int $id)
    {
        $exit = HrExitRequest::where('tenant_id', $this->tenant($request))->findOrFail($id);
        abort_if(empty($exit->attachment_path) || ! Storage::disk(self::DOC_DISK)->exists($exit->attachment_path), 404, 'No attachment');

        return Storage::disk(self::DOC_DISK)->download($exit->attachment_path, 'exit-'.$exit->id.'.'.pathinfo($exit->attachment_path, PATHINFO_EXTENSION));
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'employee_id'       => "$req|integer",
            'exit_type_id'      => "$req|integer",
            'exit_policy_id'    => 'nullable|integer',
            'request_date'      => "$req|date",
            'last_working_date' => 'nullable|date',
            'notice_start_date' => 'nullable|date',
            'notice_end_date'   => 'nullable|date',
            'notice_days'       => 'nullable|integer|min:0|max:365',
            'reason'            => 'nullable|string',
            'employee_remarks'  => 'nullable|string',
            'hr_remarks'        => 'nullable|string',
            'status'            => 'nullable|in:Draft,Submitted',
            'attachment'        => 'nullable|file|max:10240',
        ]);
    }

    private function withAttachment(Request $request, array $data): array
    {
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $data['attachment_path'] = $file->storeAs(
                "hr/documents/exit/tenant_{$this->tenant($request)}",
                Str::random(8).'_'.time().'.'.strtolower($file->getClientOriginalExtension()),
                self::DOC_DISK
            );
        }
        unset($data['attachment']);

        return $data;
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage exit requests');
    }
}
