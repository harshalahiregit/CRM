<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitOnboardingRequest;
use App\Models\Hr\HrOnboarding;
use App\Services\Hr\OnboardingService;
use Illuminate\Http\Request;

/**
 * Public, unauthenticated candidate self-service portal. Access is scoped by the
 * secret {token} in the URL — there is no logged-in user. The service resolves
 * the tenant/onboarding from the token and hard-scopes every read/write to it,
 * so no candidate can ever reach another candidate's or tenant's data.
 */
class OnboardingPortalController extends Controller
{
    public function __construct(private OnboardingService $onboardingService)
    {
    }

    /* GET /api/onboarding/{token} — full portal dashboard */
    public function show(string $token)
    {
        $onboarding = $this->onboardingService->byToken($token);

        return response()->json($this->onboardingService->portalDashboard($onboarding));
    }

    /* POST /api/onboarding/{token}/submit — profile + documents */
    public function submit(SubmitOnboardingRequest $request, string $token)
    {
        $onboarding = $this->onboardingService->byToken($token);

        foreach ($request->file('documents', []) as $type => $file) {
            if ($file) {
                $this->onboardingService->storeDocument($onboarding, $file, $type);
            }
        }

        $submission = json_decode($request->input('submission', '{}'), true);
        $this->onboardingService->submit($onboarding, is_array($submission) ? $submission : []);

        return response()->json([
            'success' => true,
            'message' => 'Your onboarding details have been submitted. Our HR team will verify them shortly.',
            'status'  => $this->onboardingService->portalDashboard($onboarding->fresh()),
        ]);
    }

    /* POST /api/onboarding/{token}/documents — upload / re-upload a single document */
    public function uploadDocument(Request $request, string $token)
    {
        $data = $request->validate([
            'type'     => 'required|in:'.implode(',', HrOnboarding::DOCUMENT_TYPES),
            'document' => 'required|file|mimes:'.implode(',', OnboardingService::ALLOWED_MIMES).'|max:'.OnboardingService::MAX_SIZE_KB,
        ]);

        $onboarding = $this->onboardingService->byToken($token);

        if ($onboarding->verification_status === 'Approved') {
            return response()->json(['message' => 'Your onboarding is already approved.'], 422);
        }

        $this->onboardingService->storeDocument($onboarding, $request->file('document'), $data['type']);

        return response()->json(['success' => true, 'status' => $this->onboardingService->portalDashboard($onboarding->fresh())]);
    }
}
