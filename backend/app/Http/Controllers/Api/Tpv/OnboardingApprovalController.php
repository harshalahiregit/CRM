<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvOnboarding;
use App\Services\Tpv\OnboardingApprovalService;
use Illuminate\Http\Request;

/**
 * Advanced approval workflow endpoints (Phase 3). The legacy single-step
 * approve/reject/hold endpoints on TpvOnboardingController remain unchanged.
 */
class OnboardingApprovalController extends Controller
{
    public function __construct(private OnboardingApprovalService $approvals)
    {
    }

    /** Onboardings awaiting a decision + their current level/SLA/escalation state. */
    public function index(Request $request)
    {
        return response()->json($this->approvals->listAwaiting($request->user()->tenant_id));
    }

    /** Approval history/timeline for one onboarding. */
    public function history(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        return response()->json($this->approvals->history($onboarding));
    }

    /** Decide the current level: approve (advance/finalise), reject, or hold. */
    public function decide(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $data = $request->validate([
            'decision' => 'required|in:approve,reject,hold',
            'remarks'  => 'nullable|string',
        ]);

        return response()->json(
            $this->approvals->decide($onboarding, $request->user(), $data['decision'], $data['remarks'] ?? null)
        );
    }

    public function delegationsIndex(Request $request)
    {
        return response()->json($this->approvals->listDelegations($request->user()->tenant_id));
    }

    public function delegationsStore(Request $request)
    {
        $data = $request->validate([
            'delegator_id' => 'required|integer',
            'delegate_id'  => 'required|integer|different:delegator_id',
            'reason'       => 'nullable|string|max:255',
            'starts_at'    => 'required|date',
            'ends_at'      => 'required|date|after:starts_at',
        ]);

        return response()->json($this->approvals->createDelegation($data, $request->user()), 201);
    }

    /** Run the SLA escalation sweep on demand (also scheduled hourly). */
    public function escalate(Request $request)
    {
        return response()->json(['escalated' => $this->approvals->escalatePending()]);
    }

    private function assertTenant(Request $request, TpvOnboarding $onboarding): void
    {
        abort_unless(
            (int) $onboarding->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Onboarding not found'
        );
    }
}
