<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\SavePurchaseOnboardingProfileRequest;
use App\Http\Requests\Purchase\StorePurchaseOnboardingRequest;
use App\Models\Purchase\PurchaseOnboarding;
use App\Services\Purchase\PurchaseOnboardingService;
use App\Support\UserAgentInfo;
use Illuminate\Http\Request;

/**
 * Purchase-vendor onboarding — the procurement mirror of TpvOnboardingController.
 * Every route-model-bound method is tenant-guarded (404). Approve/reject/hold are
 * admin-only (route group). No TPV controller/service is touched.
 */
class PurchaseOnboardingController extends Controller
{
    public function __construct(private PurchaseOnboardingService $service)
    {
    }

    public function stats(Request $request)
    {
        return response()->json($this->service->stats($request->user()->tenant_id));
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->service->list($request->user()->tenant_id, $request->only(['status', 'vendor_id']))
        );
    }

    public function store(StorePurchaseOnboardingRequest $request)
    {
        return response()->json($this->service->create($request->validated(), $request->user()), 201);
    }

    public function show(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $onboarding->load(['vendor.contacts', 'vendor.documents', 'approver:id,name', 'auditLogs']);

        return response()->json([
            'onboarding' => $onboarding,
            'progress'   => $this->service->stepStatus($onboarding),
        ]);
    }

    public function progress(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        return response()->json($this->service->stepStatus($onboarding));
    }

    public function saveProfile(SavePurchaseOnboardingProfileRequest $request, PurchaseOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        return response()->json(
            $this->service->saveProfile($onboarding, $request->validated()['profile'], $request->user())
        );
    }

    public function setStep(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $data = $request->validate(['step' => 'required|integer|min:1|max:6']);

        return response()->json($this->service->setStep($onboarding, $data['step'], $request->user()));
    }

    public function submit(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $ua = UserAgentInfo::parse($request->userAgent());

        return response()->json($this->service->submit($onboarding, $request->user(), [
            'ip' => $request->ip(), 'browser' => $ua['browser'], 'device' => $ua['device'],
        ]));
    }

    public function destroy(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $this->service->destroy($onboarding);

        return response()->json(['message' => 'Deleted']);
    }

    /* ── Step 1 — kickoff (reuses the shared kickoff MOM engine) ─────────── */

    public function kickoffPdf(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $meeting = $this->service->resolveKickoffMeeting($onboarding);
        abort_unless($meeting, 404, 'Kickoff MOM not available yet.');

        if (! $meeting->mom_path) {
            try {
                app(\App\Services\Shared\KickoffMeetingService::class)->generateMom($meeting, $request->user());
                $meeting->refresh();
            } catch (\Throwable $e) {
                abort(404, 'Kickoff MOM not available yet.');
            }
        }
        abort_unless(
            $meeting->mom_path && \Illuminate\Support\Facades\Storage::disk('kickoff_docs')->exists($meeting->mom_path),
            404, 'Kickoff MOM not available yet.'
        );

        return \Illuminate\Support\Facades\Storage::disk('kickoff_docs')->download(
            $meeting->mom_path, 'kickoff-mom.pdf',
            ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="kickoff-mom.pdf"']
        );
    }

    public function acceptKickoff(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);
        $ua = \App\Support\UserAgentInfo::parse($request->userAgent());

        return response()->json($this->service->acknowledgeKickoff($onboarding, $request->user(), [
            'ip' => $request->ip(), 'browser' => $ua['browser'], 'device' => $ua['device'],
        ]));
    }

    public function logKickoffEvent(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);
        $data = $request->validate(['event' => 'required|in:viewed,downloaded,printed']);
        $ua = \App\Support\UserAgentInfo::parse($request->userAgent());

        $this->service->logKickoffEvent($onboarding, $data['event'], $request->user(), [
            'ip' => $request->ip(), 'browser' => $ua['browser'], 'device' => $ua['device'],
        ]);

        return response()->json(['status' => 'logged']);
    }

    /* ── Admin decisions (route group is role:admin) ────────────────────── */

    public function approve(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json($this->service->approve($onboarding, $request->user(), $data['remarks'] ?? null));
    }

    public function reject(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $data = $request->validate(['remarks' => 'required|string']);

        return response()->json($this->service->reject($onboarding, $request->user(), $data['remarks']));
    }

    public function hold(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $data = $request->validate(['reason' => 'required|string']);

        return response()->json($this->service->hold($onboarding, $request->user(), $data['reason']));
    }

    public function release(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        return response()->json($this->service->release($onboarding, $request->user()));
    }

    public function requestResubmit(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertTenant($request, $onboarding);

        $data = $request->validate(['remarks' => 'required|string']);

        return response()->json($this->service->requestResubmit($onboarding, $request->user(), $data['remarks']));
    }

    /** 404 on any cross-tenant access — boundaries stay invisible. */
    private function assertTenant(Request $request, PurchaseOnboarding $onboarding): void
    {
        abort_unless((int) $onboarding->tenant_id === (int) $request->user()->tenant_id, 404, 'Onboarding not found');
    }
}
