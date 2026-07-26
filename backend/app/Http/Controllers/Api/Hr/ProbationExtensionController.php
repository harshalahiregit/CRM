<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\ProbationExtensionService;
use Illuminate\Http\Request;

/**
 * Probation Management → Probation Extensions (Phase 4). Thin: validate, delegate,
 * return JSON. Reads open to HR users; writes require HR-queue management.
 * Tenant-scoped, audited.
 */
class ProbationExtensionController extends Controller
{
    public function __construct(private ProbationExtensionService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['employee_id', 'probation_id', 'department', 'status', 'from', 'to', 'search'])));
    }

    public function history(Request $request)
    {
        return response()->json($this->service->history($this->tenant($request), $request->only(['employee_id'])));
    }

    public function forEmployee(Request $request, int $employee)
    {
        return response()->json($this->service->forEmployee($employee, $this->tenant($request)));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request), $request->user()));
    }

    public function store(Request $request)
    {
        $this->can($request);
        $data = $request->validate([
            'probation_id'      => 'required|integer',
            'extension_days'    => 'required_without:extended_end_date|integer|min:1|max:1000',
            'extended_end_date' => 'required_without:extension_days|nullable|date',
            'requested_by'      => 'nullable|integer',
            'reason'            => 'nullable|string',
            'manager_comments'  => 'nullable|string',
            'hr_comments'       => 'nullable|string',
        ]);

        return response()->json($this->service->request($data, $this->tenant($request), $request->user()), 201);
    }

    public function update(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate([
            'extension_days'    => 'nullable|integer|min:1|max:1000',
            'extended_end_date' => 'nullable|date',
            'reason'            => 'nullable|string',
            'manager_comments'  => 'nullable|string',
            'hr_comments'       => 'nullable|string',
        ]);

        return response()->json($this->service->update($id, $data, $this->tenant($request), $request->user()));
    }

    public function approve(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['hr_comments' => 'nullable|string']);

        return response()->json($this->service->approve($id, $data, $this->tenant($request), $request->user()));
    }

    public function reject(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['hr_comments' => 'nullable|string']);

        return response()->json($this->service->reject($id, $data, $this->tenant($request), $request->user()));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage probation extensions');
    }
}
