<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseApprovalRequest;
use App\Services\Purchase\PurchaseApprovalRequestService;
use App\Support\Purchase\PurchaseApprovalType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Purchase central approval register (Sangoe TPV §12) — the staff surface over
 * PurchaseApprovalRequestService (purchase_approval_requests). Purchase-owned,
 * independent of the shared/TPV approvals controller and of the Purchase
 * onboarding stage-chain controller. Deciding is admin-only (mirror of TPV).
 */
class PurchaseApprovalRequestController extends Controller
{
    public function __construct(private PurchaseApprovalRequestService $service)
    {
    }

    public function index(Request $request)
    {
        $data = $this->service->list(
            $request->user()->tenant_id,
            $request->only(['status', 'approval_type', 'purchase_vendor_id'])
        );

        return response()->json([
            'data'  => $data,
            'types' => PurchaseApprovalType::options(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'approval_type'      => ['required', Rule::in(PurchaseApprovalType::ALL)],
            'title'              => 'required|string|max:200',
            'description'        => 'nullable|string|max:2000',
            'purchase_vendor_id' => 'nullable|integer',
            'subject_type'       => 'nullable|string|max:150',
            'subject_id'         => 'nullable|integer',
            'priority'           => ['nullable', Rule::in(PurchaseApprovalRequest::PRIORITIES)],
        ]);

        $approval = $this->service->raise($data, $request->user()->tenant_id, $request->user()->id);

        return response()->json($approval, 201);
    }

    public function decide(Request $request, PurchaseApprovalRequest $approvalRequest)
    {
        $this->assertTenant($request, $approvalRequest);
        abort_unless($request->user()->role === 'admin', 403, 'Only an admin can decide approvals.');

        $data = $request->validate([
            'decision' => 'required|in:approve,reject,cancel',
            'remarks'  => 'nullable|string|max:2000',
        ]);

        return response()->json(
            $this->service->decide($approvalRequest, $data['decision'], $data['remarks'] ?? null, $request->user())
        );
    }

    private function assertTenant(Request $request, PurchaseApprovalRequest $approvalRequest): void
    {
        abort_unless((int) $approvalRequest->tenant_id === (int) $request->user()->tenant_id, 404, 'Approval not found');
    }
}
