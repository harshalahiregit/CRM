<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\ProposalService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PublicProposalController extends Controller
{
    public function __construct(private ProposalService $proposalService)
    {
    }

    /**
     * GET /api/public/proposals/{token} — unauthenticated, for the
     * client-facing portal view. See ProposalService::findByPortalToken
     * for why public_view_otp_enabled isn't enforced here.
     */
    public function show(string $token)
    {
        return response()->json($this->proposalService->findByPortalToken($token));
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
