<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Hr\ApproveManpowerRequest;
use App\Http\Requests\Hr\AssignManpowerManagerRequest;
use App\Http\Requests\Hr\RejectManpowerRequest;
use App\Http\Requests\Hr\StoreManpowerRequest;
use App\Http\Requests\Hr\UpdateManpowerRequest;
use App\Models\Hr\HrManpowerRequest;
use App\Services\Hr\ManpowerRequestService;
use Illuminate\Http\Request;

class ManpowerRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private ManpowerRequestService $manpowerRequestService)
    {
    }

    /* ─────────────────────────────────────────────
     | GET /api/hr/manpower
     ───────────────────────────────────────────── */
    public function index(Request $request)
    {
        $results = $this->manpowerRequestService->list($request->user(), $request->only(['status', 'department']));

        return $this->success($results);
    }

    /* ─────────────────────────────────────────────
     | GET /api/hr/manpower/pending-approvals
     ───────────────────────────────────────────── */
    public function pendingApprovals(Request $request)
    {
        return $this->success($this->manpowerRequestService->pendingApprovals($request->user()));
    }

    /* ─────────────────────────────────────────────
     | POST /api/hr/manpower
     ───────────────────────────────────────────── */
    public function store(StoreManpowerRequest $request)
    {
        $mr = $this->manpowerRequestService->create($request->validated(), $request->user());

        return $this->success($mr, 'Request created', 201);
    }

    /* ─────────────────────────────────────────────
     | GET /api/hr/manpower/{id}
     ───────────────────────────────────────────── */
    public function show(HrManpowerRequest $manpowerRequest)
    {
        return $this->success(
            $manpowerRequest->load(['requester', 'assignedManager', 'l1Approver', 'l2Approver', 'approvalHistory.actor'])
        );
    }

    /* ─────────────────────────────────────────────
     | POST /api/hr/manpower/{id}/submit
     | Submit for L1 review (by requester)
     ───────────────────────────────────────────── */
    public function submit(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->submit($manpowerRequest, $request->user());

        return $this->success($result, 'Submitted for L1 approval');
    }

    /* ─────────────────────────────────────────────
     | POST /api/hr/manpower/{id}/approve-l1
     | L1 — Department Head approves
     ───────────────────────────────────────────── */
    public function approveL1(ApproveManpowerRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->approveL1($manpowerRequest, $request->user(), $request->validated('remarks'));

        return $this->success($result, 'L1 Approved — now pending Management (L2) approval');
    }

    /* ─────────────────────────────────────────────
     | POST /api/hr/manpower/{id}/reject-l1
     ───────────────────────────────────────────── */
    public function rejectL1(RejectManpowerRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->rejectL1($manpowerRequest, $request->user(), $request->validated('remarks'));

        return $this->success($result, 'Request rejected at L1');
    }

    /* ─────────────────────────────────────────────
     | POST /api/hr/manpower/{id}/approve-l2
     | L2 — Management approves → HR can now post job
     ───────────────────────────────────────────── */
    public function approveL2(ApproveManpowerRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->approveL2($manpowerRequest, $request->user(), $request->validated('remarks'));

        return $this->success($result, '✅ Fully Approved — HR can now post the job');
    }

    /* ─────────────────────────────────────────────
     | POST /api/hr/manpower/{id}/reject-l2
     ───────────────────────────────────────────── */
    public function rejectL2(RejectManpowerRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->rejectL2($manpowerRequest, $request->user(), $request->validated('remarks'));

        return $this->success($result, 'Request rejected at L2');
    }

    /* ─────────────────────────────────────────────
     | PUT /api/hr/manpower/{id}
     ───────────────────────────────────────────── */
    public function update(UpdateManpowerRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->update($manpowerRequest, $request->validated());

        return $this->success($result, 'Updated successfully');
    }

    /* ─────────────────────────────────────────────
     | DELETE /api/hr/manpower/{id}
     ───────────────────────────────────────────── */
    public function destroy(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $this->manpowerRequestService->destroy($manpowerRequest, $request->user());

        return $this->success(null, 'Deleted successfully');
    }

    /* ─────────────────────────────────────────────
     | GET /api/hr/manpower/stats
     ───────────────────────────────────────────── */
    public function stats(Request $request)
    {
        return $this->success($this->manpowerRequestService->stats($request->user()->tenant_id));
    }

    /* ─────────────────────────────────────────────
     | Legacy: pendingCount + assignManager
     ───────────────────────────────────────────── */
    public function pendingCount(Request $request)
    {
        return $this->success(['count' => $this->manpowerRequestService->pendingCount($request->user()->tenant_id)]);
    }

    public function assignManager(AssignManpowerManagerRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->assignManager($manpowerRequest, $request->validated('manager_id'));

        return $this->success($result, 'Manager assigned');
    }
}
