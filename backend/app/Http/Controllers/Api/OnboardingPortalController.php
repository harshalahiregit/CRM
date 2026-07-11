<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitOnboardingRequest;
use App\Services\Hr\OnboardingService;

/**
 * Public, unauthenticated candidate Onboarding portal. Access is scoped by the
 * secret {token} in the URL — there is no logged-in user. The service resolves
 * the tenant/onboarding from the token and hard-scopes every write to it.
 */
class OnboardingPortalController extends Controller
{
    public function __construct(private OnboardingService $onboardingService)
    {
    }

    /* GET /api/onboarding/{token} */
    public function show(string $token)
    {
        $onboarding = $this->onboardingService->byToken($token);

        return response()->json($this->onboardingService->publicView($onboarding));
    }

    /* POST /api/onboarding/{token}/submit */
    public function submit(SubmitOnboardingRequest $request, string $token)
    {
        $onboarding = $this->onboardingService->byToken($token);

        // Store any uploaded documents first, then the structured details.
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
            'status'  => $this->onboardingService->publicView($onboarding->fresh(['documents'])),
        ]);
    }
}
