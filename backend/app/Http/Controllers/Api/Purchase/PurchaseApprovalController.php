<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseOnboarding;
use App\Services\Purchase\PurchaseApprovalService;
use Illuminate\Http\Request;

/**
 * Purchase onboarding approval chain — the staff/admin surface over the
 * Purchase-owned approval engine (PurchaseApprovalService, purchase_approvals).
 * Independent of the shared/TPV approval workflow. Every bound onboarding is
 * tenant-guarded (404 on mismatch).
 */
class PurchaseApprovalController extends Controller
{
    public function __construct(private PurchaseApprovalService $approvals)
    {
    }

    /** The five-stage chain + decisions for an onboarding. */
    public function index(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        return response()->json([
            'onboarding_id'  => $onboarding->id,
            'fully_approved' => $this->approvals->isFullyApproved($onboarding),
            'chain'          => $this->approvals->chainFor($onboarding)->values(),
        ]);
    }

    /** Approve a single stage (role:admin). */
    public function approve(Request $request, PurchaseOnboarding $onboarding, string $stage)
    {
        $this->assertTenant($request, $onboarding);
        $data = $request->validate(['remarks' => 'nullable|string|max:2000']);

        return response()->json(
            $this->approvals->decide($onboarding, $stage, 'approve', $request->user(), $data['remarks'] ?? null)
        );
    }

    /** Reject a single stage with a required reason (role:admin). */
    public function reject(Request $request, PurchaseOnboarding $onboarding, string $stage)
    {
        $this->assertTenant($request, $onboarding);
        $data = $request->validate(['remarks' => 'required|string|max:2000']);

        return response()->json(
            $this->approvals->decide($onboarding, $stage, 'reject', $request->user(), $data['remarks'])
        );
    }

    private function assertTenant(Request $request, PurchaseOnboarding $onboarding): void
    {
        abort_unless((int) $onboarding->tenant_id === (int) $request->user()->tenant_id, 404, 'Onboarding not found');
    }
}
