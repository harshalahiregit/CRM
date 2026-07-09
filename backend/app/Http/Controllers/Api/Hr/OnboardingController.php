<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreOnboardingRequest;
use App\Models\HrOnboarding;
use App\Services\Hr\OnboardingService;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function __construct(private OnboardingService $onboardingService)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->onboardingService->list($request->user()->tenant_id, $request->only('status'))
        );
    }

    public function store(StoreOnboardingRequest $request)
    {
        $record = $this->onboardingService->create($request->validated(), $request->user()->tenant_id);

        return response()->json($record, 201);
    }

    public function show(HrOnboarding $onboarding)
    {
        return response()->json($onboarding);
    }

    public function toggleStep(Request $request, HrOnboarding $onboarding)
    {
        // Handle document checklist updates
        if ($request->has('checklist')) {
            $updated = $this->onboardingService->toggleChecklist($onboarding, $request->input('checklist'));

            return response()->json($updated);
        }

        // Handle step toggles
        $validated = $request->validate([
            'step' => 'required|in:doc_verification,joining_confirmed,emp_id_generated,dept_assigned,manager_assigned,record_created',
        ]);

        $updated = $this->onboardingService->toggleStep($onboarding, $validated['step']);

        return response()->json($updated);
    }

    public function destroy(HrOnboarding $onboarding)
    {
        $this->onboardingService->destroy($onboarding);

        return response()->json(['message' => 'Deleted']);
    }
}
