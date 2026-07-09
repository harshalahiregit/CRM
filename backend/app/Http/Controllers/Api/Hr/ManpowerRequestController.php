<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Hr\ApproveManpowerRequest;
use App\Http\Requests\Hr\AssignManpowerManagerRequest;
use App\Http\Requests\Hr\ConvertToJdRequest;
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

    /* GET /api/hr/manpower-requests */
    public function index(Request $request)
    {
        $results = $this->manpowerRequestService->list($request->user(), $request->only(['status', 'department']));

        return $this->success($results);
    }

    /* GET /api/hr/manpower-requests/queue — fully-approved HR queue */
    public function queue(Request $request)
    {
        $results = $this->manpowerRequestService->list(
            $request->user(),
            ['scope' => 'hr_queue'] + $request->only(['department'])
        );

        return $this->success($results);
    }

    /* GET /api/hr/manpower-requests/pending-approvals */
    public function pendingApprovals(Request $request)
    {
        return $this->success($this->manpowerRequestService->pendingApprovals($request->user()));
    }

    /* POST /api/hr/manpower-requests */
    public function store(StoreManpowerRequest $request)
    {
        $mr = $this->manpowerRequestService->create($request->validated(), $request->user());

        return $this->success($mr, 'Request created', 201);
    }

    /* GET /api/hr/manpower-requests/{id} */
    public function show(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $this->assertTenant($request, $manpowerRequest);

        return $this->success(
            $manpowerRequest->load(['requester', 'assignedManager', 'l1Approver', 'l2Approver', 'jobPosting', 'approvalHistory.actor'])
        );
    }

    /* POST /api/hr/manpower-requests/{id}/submit */
    public function submit(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->submit($manpowerRequest, $request->user());

        return $this->success($result, 'Submitted for L1 approval');
    }

    /* POST /api/hr/manpower-requests/{id}/approve-l1 */
    public function approveL1(ApproveManpowerRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->approveL1($manpowerRequest, $request->user(), $request->validated('remarks'));

        return $this->success($result, 'L1 Approved — now pending Management (L2) approval');
    }

    /* POST /api/hr/manpower-requests/{id}/reject-l1 */
    public function rejectL1(RejectManpowerRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->rejectL1($manpowerRequest, $request->user(), $request->validated('remarks'));

        return $this->success($result, 'Request rejected at L1');
    }

    /* POST /api/hr/manpower-requests/{id}/approve-l2 */
    public function approveL2(ApproveManpowerRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->approveL2($manpowerRequest, $request->user(), $request->validated('remarks'));

        return $this->success($result, 'Fully Approved — request is now in the HR queue');
    }

    /* POST /api/hr/manpower-requests/{id}/reject-l2 */
    public function rejectL2(RejectManpowerRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->rejectL2($manpowerRequest, $request->user(), $request->validated('remarks'));

        return $this->success($result, 'Request rejected at L2');
    }

    /* POST /api/hr/manpower-requests/{id}/convert-to-jd */
    public function convertToJd(ConvertToJdRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->convertToJd($manpowerRequest, $request->user(), $request->validated());

        return $this->success($result, 'Converted to Job Description — review and publish');
    }

    /* POST /api/hr/manpower-requests/{id}/publish */
    public function publish(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->publishJob($manpowerRequest, $request->user());

        return $this->success($result, 'Job published');
    }

    /* POST /api/hr/manpower-requests/{id}/start-hiring */
    public function startHiring(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->startHiring($manpowerRequest, $request->user());

        return $this->success($result, 'Hiring in progress');
    }

    /* POST /api/hr/manpower-requests/{id}/close */
    public function close(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->close($manpowerRequest, $request->user(), $request->input('remarks'));

        return $this->success($result, 'Position closed');
    }

    /* PUT /api/hr/manpower-requests/{id} */
    public function update(UpdateManpowerRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->update($manpowerRequest, $request->validated(), $request->user());

        return $this->success($result, 'Updated successfully');
    }

    /* DELETE /api/hr/manpower-requests/{id} */
    public function destroy(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $this->manpowerRequestService->destroy($manpowerRequest, $request->user());

        return $this->success(null, 'Deleted successfully');
    }

    /* GET /api/hr/manpower-requests/stats */
    public function stats(Request $request)
    {
        return $this->success($this->manpowerRequestService->stats($request->user()->tenant_id));
    }

    /* GET /api/hr/manpower-requests/pending-count */
    public function pendingCount(Request $request)
    {
        return $this->success(['count' => $this->manpowerRequestService->pendingCount($request->user()->tenant_id)]);
    }

    /* PATCH /api/hr/manpower-requests/{id}/assign-manager */
    public function assignManager(AssignManpowerManagerRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->assignManager($manpowerRequest, $request->validated('manager_id'), $request->user());

        return $this->success($result, 'Manager assigned');
    }

    /**
     * Tenant guard for route-model-bound reads. Implicit binding resolves by
     * global id; this ensures a user can only read their own tenant's records.
     */
    private function assertTenant(Request $request, HrManpowerRequest $manpowerRequest): void
    {
        abort_unless((int) $manpowerRequest->tenant_id === (int) $request->user()->tenant_id, 404, 'Request not found');
    }
}
