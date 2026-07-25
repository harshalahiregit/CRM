<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\ProposalOtpService;
use App\Services\Sales\ProposalService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Unauthenticated portal endpoints. Tenant context comes exclusively from
 * the portal token — no user input decides the tenant. When the proposal
 * has the OTP gate enabled, everything but the teaser requires a valid
 * X-Portal-Access token issued by verify-otp.
 */
class PublicProposalController extends Controller
{
    public function __construct(
        private ProposalService $proposalService,
        private ProposalOtpService $otpService,
    ) {
    }

    public function show(Request $request, string $token)
    {
        $proposal = $this->proposalService->findByPortalToken($token);

        if (! $this->otpService->hasAccess($proposal, $request->header('X-Portal-Access'))) {
            return response()->json([
                'locked'   => true,
                'proposal' => $this->proposalService->teaserPayload($proposal),
            ], 401);
        }

        $this->proposalService->recordPortalView($proposal);

        return response()->json([
            'locked'   => false,
            'proposal' => $this->proposalService->publicPayload($proposal),
        ]);
    }

    public function requestOtp(string $token)
    {
        $proposal = $this->proposalService->findByPortalToken($token);
        abort_unless($proposal->public_view_otp_enabled, 422, 'Verification is not required for this proposal.');

        return response()->json($this->otpService->request($proposal));
    }

    public function verifyOtp(Request $request, string $token)
    {
        $data = $request->validate(['code' => 'required|digits:6']);
        $proposal = $this->proposalService->findByPortalToken($token);

        return response()->json($this->otpService->verify($proposal, $data['code']));
    }

    public function accept(Request $request, string $token)
    {
        return $this->respond($request, $token, 'accept');
    }

    public function decline(Request $request, string $token)
    {
        return $this->respond($request, $token, 'decline');
    }

    private function respond(Request $request, string $token, string $action)
    {
        $proposal = $this->proposalService->findByPortalToken($token);
        abort_unless($this->otpService->hasAccess($proposal, $request->header('X-Portal-Access')), 401);

        $updated = $this->proposalService->publicRespond($proposal, $action, $request->ip(), $request->userAgent());

        return response()->json(['status' => $updated->status]);
    }

    /**
     * GET /api/public/proposals/{token}/track — 1x1 transparent pixel,
     * embedded in the proposal email. Loading it records an open.
     */
    public function trackOpen(Request $request, string $token)
    {
        $this->proposalService->trackEmailOpen($token, $request->userAgent());

        // 1x1 transparent GIF
        $pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7');

        return new Response($pixel, 200, ['Content-Type' => 'image/gif']);
    }
}
