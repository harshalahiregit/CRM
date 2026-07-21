<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitOnboardingRequest;
use App\Models\Hr\HrEmployeeOnboarding;
use App\Models\Hr\HrOnboarding;
use App\Services\Hr\EmployeeOnboardingService;
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
    public function __construct(
        private OnboardingService $onboardingService,
        private EmployeeOnboardingService $formService,
    ) {
    }

    /**
     * The onboarding-form record for this candidate. Reuses the existing
     * hr_employee_onboardings row when one exists (linked by candidate), otherwise
     * opens one against the candidate — employee_id is filled later, on joining.
     */
    private function formRecord(HrOnboarding $onboarding): HrEmployeeOnboarding
    {
        return HrEmployeeOnboarding::firstOrCreate(
            ['candidate_id' => $onboarding->candidate_id, 'tenant_id' => $onboarding->tenant_id],
            [
                'onboarding_id' => $onboarding->id,
                'status'        => 'Pending',
                'current_stage' => 'Employee_Created',
                'joining_date'  => $onboarding->joining_date,
            ]
        );
    }

    /** Approved onboarding is read-only for the candidate. */
    private function assertEditable(HrOnboarding $onboarding): void
    {
        abort_if($onboarding->verification_status === 'Approved', 422, 'Your onboarding is already approved and can no longer be edited.');
    }

    /* PATCH /api/onboarding/{token}/form/section/{section} — save/submit one section */
    public function saveFormSection(Request $request, string $token, string $section)
    {
        $onboarding = $this->onboardingService->byToken($token);
        $this->assertEditable($onboarding);

        // Actor is null: the service records the trail as "Candidate (Portal)" and
        // marks the section Submitted, leaving HR verification untouched.
        $record = $this->formService->saveSection($this->formRecord($onboarding), $section, $request->all(), null);

        return response()->json(['success' => true, 'form' => $this->formService->show($record)]);
    }

    /* POST /api/onboarding/{token}/form/{collection} — education | experience | references */
    public function saveFormChild(Request $request, string $token, string $collection)
    {
        $onboarding = $this->onboardingService->byToken($token);
        $this->assertEditable($onboarding);

        $method = ['education' => 'saveEducation', 'experience' => 'saveExperience', 'references' => 'saveReference'][$collection] ?? null;
        abort_if(! $method, 422, "Unknown section: {$collection}");

        $record = $this->formRecord($onboarding);
        $this->formService->{$method}($record, $request->except(['id']), null, $request->input('id'));

        return response()->json(['success' => true, 'form' => $this->formService->show($record->fresh())]);
    }

    /* DELETE /api/onboarding/{token}/form/{collection}/{id} */
    public function deleteFormChild(string $token, string $collection, int $id)
    {
        $onboarding = $this->onboardingService->byToken($token);
        $this->assertEditable($onboarding);
        abort_if(! in_array($collection, ['education', 'experience', 'references'], true), 422, "Unknown section: {$collection}");

        $record = $this->formRecord($onboarding);
        $this->formService->deleteChild($record, $collection, $id, null);

        return response()->json(['success' => true, 'form' => $this->formService->show($record->fresh())]);
    }

    /* GET /api/onboarding/{token} — full portal dashboard */
    public function show(string $token)
    {
        $onboarding = $this->onboardingService->byToken($token);

        return response()->json(array_merge(
            $this->onboardingService->portalDashboard($onboarding),
            ['form' => $this->formService->show($this->formRecord($onboarding))],
        ));
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
