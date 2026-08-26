<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tpv\SaveOnboardingProfileRequest;
use App\Http\Requests\Tpv\StoreTpvOnboardingRequest;
use App\Http\Requests\Tpv\SubmitOnboardingRequest;
use App\Models\Tpv\TpvOnboarding;
use App\Services\Tpv\KickoffPdfService;
use App\Services\Tpv\TpvOnboardingService;
use App\Services\Tpv\WorkStartLetterService;
use App\Support\UserAgentInfo;
use Illuminate\Http\Request;

class TpvOnboardingController extends Controller
{
    public function __construct(
        private TpvOnboardingService $tpvOnboardingService,
        private KickoffPdfService $kickoffPdfService,
        private WorkStartLetterService $workStartLetterService,
    ) {
    }

    /** Step 1 — stream the Kickoff PDF (generated + cached on first access). */
    public function kickoffPdf(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        return $this->kickoffPdfService->stream($onboarding);
    }

    /** Stream the HSSE Work Start Letter (issued on approval; lazily generated). */
    public function workStartLetter(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        return $this->workStartLetterService->stream($onboarding);
    }

    /** Step 1 — record the vendor's acknowledgement with captured context. */
    public function acceptKickoff(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        // Optional — mirrors the portal endpoint so both doors store the same thing.
        $data = $request->validate(['comment' => 'nullable|string|max:5000']);
        $ua   = UserAgentInfo::parse($request->userAgent());

        return response()->json(
            $this->tpvOnboardingService->acknowledgeKickoff($onboarding, $request->user(), [
                'ip' => $request->ip(), 'browser' => $ua['browser'], 'device' => $ua['device'],
                'comment' => $data['comment'] ?? null,
            ])
        );
    }

    /** Step 1 — audit a Kickoff PDF interaction (viewed / downloaded / printed). */
    public function logKickoffEvent(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $data = $request->validate(['event' => 'required|in:viewed,downloaded,printed']);
        $ua = UserAgentInfo::parse($request->userAgent());

        $this->tpvOnboardingService->logKickoffEvent($onboarding, $data['event'], $request->user(), [
            'ip' => $request->ip(), 'browser' => $ua['browser'], 'device' => $ua['device'],
        ]);

        return response()->json(['status' => 'logged']);
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

    public function submit(SubmitOnboardingRequest $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $ua = UserAgentInfo::parse($request->userAgent());

        return response()->json($this->tpvOnboardingService->submit($onboarding, $request->user(), [
            'ip' => $request->ip(), 'browser' => $ua['browser'], 'device' => $ua['device'],
        ]));
    }

    public function approve(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json(
            $this->tpvOnboardingService->approve($onboarding, $request->user(), $data['remarks'] ?? null)
        );
    }

    public function reject(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $data = $request->validate(['remarks' => 'required|string']);

        return response()->json(
            $this->tpvOnboardingService->reject($onboarding, $request->user(), $data['remarks'])
        );
    }

    public function hold(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $data = $request->validate([
            'reason'  => 'nullable|string',
            'remarks' => 'nullable|string',
        ]);
        $reason = $data['reason'] ?? $data['remarks'] ?? 'Onboarding placed on hold';

        return response()->json(
            $this->tpvOnboardingService->hold($onboarding, $request->user(), $reason)
        );
    }

    public function release(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        return response()->json(
            $this->tpvOnboardingService->release($onboarding, $request->user())
        );
    }

    /** §10 — the activation-gating checklist for this onboarding (resolved + ticked state). */
    public function checklist(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        return response()->json($this->tpvOnboardingService->checklist($onboarding));
    }

    /** §10 — admin ticks/unticks checklist items ({ item: bool, ... }). */
    public function saveChecklist(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $data = $request->validate([
            'state'   => 'required|array|min:1',
            'state.*' => 'boolean',
        ]);

        $this->tpvOnboardingService->setChecklist($onboarding, $data['state']);

        return response()->json($this->tpvOnboardingService->checklist($onboarding));
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
