<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tpv\SaveOnboardingProfileRequest;
use App\Http\Requests\Tpv\StoreTpvOnboardingRequest;
use App\Models\Tpv\TpvOnboarding;
use App\Services\Tpv\TpvOnboardingService;
use Illuminate\Http\Request;

class TpvOnboardingController extends Controller
{
    public function __construct(private TpvOnboardingService $tpvOnboardingService)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->tpvOnboardingService->list(
                $request->user()->tenant_id,
                $request->only(['status', 'vendor_id'])
            )
        );
    }

    public function store(StoreTpvOnboardingRequest $request)
    {
        $onboarding = $this->tpvOnboardingService->create($request->validated(), $request->user()->tenant_id);

        return response()->json($onboarding, 201);
    }

    public function show(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        // auditLogs powers the wizard's timeline (serialized as `audit_logs`).
        $onboarding->load(['vendor.contacts', 'vendor.documents', 'approver:id,name', 'auditLogs']);

        return response()->json([
            'onboarding'  => $onboarding,
            'progress'    => $this->tpvOnboardingService->stepStatus($onboarding),
        ]);
    }

    /** Per-step completion + the document checklist (the wizard's live state). */
    public function progress(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        return response()->json($this->tpvOnboardingService->stepStatus($onboarding));
    }

    public function saveProfile(SaveOnboardingProfileRequest $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        return response()->json(
            $this->tpvOnboardingService->saveProfile($onboarding, $request->validated()['profile'], $request->user())
        );
    }

    public function setStep(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $data = $request->validate(['step' => 'required|integer|min:1|max:6']);

        return response()->json(
            $this->tpvOnboardingService->setStep($onboarding, $data['step'], $request->user())
        );
    }

    public function submit(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        return response()->json($this->tpvOnboardingService->submit($onboarding, $request->user()));
    }

    public function approve(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json(
            $this->tpvOnboardingService->approve($onboarding, $request->user(), $data['remarks'] ?? null)
        );
    }

    public function requestResubmit(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $data = $request->validate(['remarks' => 'required|string']);

        return response()->json(
            $this->tpvOnboardingService->requestResubmit($onboarding, $request->user(), $data['remarks'])
        );
    }

    public function destroy(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $this->tpvOnboardingService->destroy($onboarding);

        return response()->json(['message' => 'Deleted']);
    }

    public function stats(Request $request)
    {
        return response()->json($this->tpvOnboardingService->stats($request->user()->tenant_id));
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
