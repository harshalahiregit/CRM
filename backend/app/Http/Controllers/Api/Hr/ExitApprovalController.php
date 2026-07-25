<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\ExitApprovalService;
use Illuminate\Http\Request;

/**
 * Exit Management → Exit Approval (Phase 3). Thin: validate, delegate, return JSON.
 * Reads open to HR users; decisions require HR-queue management. Tenant-scoped, audited.
 */
class ExitApprovalController extends Controller
{
    public function __construct(private ExitApprovalService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->queue($this->tenant($request), $request->only(['employee_id', 'department', 'exit_type_id', 'status', 'search'])));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request), $request->user()));
    }

    public function history(Request $request)
    {
        return response()->json($this->service->history($this->tenant($request), $request->only(['employee_id'])));
    }

    public function startReview(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['review_remarks' => 'nullable|string']);

        return response()->json($this->service->startReview($id, $data, $this->tenant($request), $request->user()));
    }

    public function updateRemarks(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['review_remarks' => 'nullable|string']);

        return response()->json($this->service->updateReviewRemarks($id, $data, $this->tenant($request), $request->user()));
    }

    public function approve(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json($this->service->approve($id, $data, $this->tenant($request), $request->user()));
    }

    public function reject(Request $request, int $id)
    {
        $this->can($request);
        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json($this->service->reject($id, $data, $this->tenant($request), $request->user()));
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to action exit approvals');
    }
}
