<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\SavePurchaseOnboardingProfileRequest;
use App\Http\Requests\Vendor\ResubmitVendorDocumentRequest;
use App\Http\Requests\Vendor\UploadVendorDocumentRequest;
use App\Models\Purchase\PurchaseOnboarding;
use App\Models\Shared\KickoffMeeting;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorDocument;
use App\Services\Purchase\PurchaseOnboardingService;
use App\Services\Shared\KickoffMeetingService;
use App\Services\Vendor\VendorDocumentService;
use Illuminate\Http\Request;

/**
 * Purchase Vendor Portal — the procurement mirror of VendorPortalController's
 * self-service section. The caller's vendor is ALWAYS resolved from the token
 * (portalVendor), never a URL param; sub-resources are guarded by assertOwned()
 * which 404s anything that isn't the caller's (existence-hiding). Admin-only
 * actions (approve/reject/hold) are deliberately absent from the portal.
 *
 * Reuses the shared engines unchanged: PurchaseOnboardingService (Phase A),
 * VendorDocumentService (private-disk documents) and KickoffMeetingService.
 */
class PurchasePortalController extends Controller
{
    use ResolvesPortalVendor;

    public function __construct(
        private PurchaseOnboardingService $onboardingService,
        private VendorDocumentService $documentService,
        private KickoffMeetingService $kickoffService,
    ) {
    }

    /** The caller's own vendor profile. */
    public function me(Request $request)
    {
        $vendor = $this->portalVendor($request);
        $vendor->loadMissing(['contacts', 'bankAccount']);

        return response()->json(['vendor' => $vendor]);
    }

    /* ── Onboarding (own vendor only) ───────────────────────────────────── */

    /** Own onboarding record + progress; started on first access. */
    public function onboarding(Request $request)
    {
        $vendor = $this->portalVendor($request);

        $onboarding = PurchaseOnboarding::forTenant($vendor->tenant_id)
            ->where('vendor_id', $vendor->id)->first()
            ?? $this->onboardingService->create(['vendor_id' => $vendor->id], $request->user());

        $onboarding->load(['vendor.contacts', 'vendor.documents', 'approver:id,name', 'auditLogs']);

        return response()->json([
            'onboarding' => $onboarding,
            'progress'   => $this->onboardingService->stepStatus($onboarding),
        ]);
    }

    public function onboardingShow(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertOwned($request, $onboarding, 'Onboarding');
        $onboarding->load(['vendor.contacts', 'vendor.documents', 'approver:id,name', 'auditLogs']);

        return response()->json([
            'onboarding' => $onboarding,
            'progress'   => $this->onboardingService->stepStatus($onboarding),
        ]);
    }

    public function onboardingProgress(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertOwned($request, $onboarding, 'Onboarding');

        return response()->json($this->onboardingService->stepStatus($onboarding));
    }

    public function saveProfile(SavePurchaseOnboardingProfileRequest $request, PurchaseOnboarding $onboarding)
    {
        $this->assertOwned($request, $onboarding, 'Onboarding');

        return response()->json(
            $this->onboardingService->saveProfile($onboarding, $request->validated()['profile'], $request->user())
        );
    }

    public function setStep(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertOwned($request, $onboarding, 'Onboarding');
        $data = $request->validate(['step' => 'required|integer|min:1|max:6']);

        return response()->json($this->onboardingService->setStep($onboarding, $data['step'], $request->user()));
    }

    public function submitOnboarding(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertOwned($request, $onboarding, 'Onboarding');

        return response()->json($this->onboardingService->submit($onboarding, $request->user()));
    }

    /* ── Onboarding kickoff (Step 1 — own onboarding only) ──────────────── */

    /** Stream the kickoff MOM PDF for the caller's own onboarding. */
    public function onboardingKickoffPdf(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertOwned($request, $onboarding, 'Onboarding');

        $meeting = $this->onboardingService->resolveKickoffMeeting($onboarding);
        abort_unless($meeting, 404, 'Kickoff MOM not available yet.');

        if (! $meeting->mom_path) {
            try {
                $this->kickoffService->generateMom($meeting, $request->user());
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

    /** Vendor acknowledges their own kickoff MOM (by onboarding). */
    public function onboardingAcceptKickoff(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertOwned($request, $onboarding, 'Onboarding');
        $ua = \App\Support\UserAgentInfo::parse($request->userAgent());

        return response()->json($this->onboardingService->acknowledgeKickoff($onboarding, $request->user(), [
            'ip' => $request->ip(), 'browser' => $ua['browser'], 'device' => $ua['device'],
        ]));
    }

    /** Audit a kickoff PDF interaction (viewed / downloaded / printed) by onboarding. */
    public function onboardingLogKickoffEvent(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertOwned($request, $onboarding, 'Onboarding');
        $data = $request->validate(['event' => 'required|in:viewed,downloaded,printed']);
        $ua = \App\Support\UserAgentInfo::parse($request->userAgent());

        $this->onboardingService->logKickoffEvent($onboarding, $data['event'], $request->user(), [
            'ip' => $request->ip(), 'browser' => $ua['browser'], 'device' => $ua['device'],
        ]);

        return response()->json(['status' => 'logged']);
    }

    /* ── Documents (own vendor only) ────────────────────────────────────── */

    public function documents(Request $request)
    {
        return response()->json($this->documentService->checklist($this->portalVendor($request)));
    }

    public function uploadDocument(UploadVendorDocumentRequest $request)
    {
        $vendor = $this->portalVendor($request);
        $doc = $this->documentService->upload($vendor, $request->input('type'), $request->file('file'), $request->user());

        return response()->json($doc, 201);
    }

    public function resubmitDocument(ResubmitVendorDocumentRequest $request, VendorDocument $document)
    {
        $this->assertOwned($request, $document, 'Document');

        return response()->json($this->documentService->resubmit($document, $request->file('file'), $request->user()));
    }

    public function downloadDocument(Request $request, VendorDocument $document)
    {
        $this->assertOwned($request, $document, 'Document');

        $file = $this->documentService->resolveDownload($document);

        return response()->download($file['path'], $file['filename'], [
            'Content-Type'        => $file['mime'],
            'Content-Disposition' => 'inline; filename="'.$file['filename'].'"',
        ]);
    }

    /* ── Kickoff (own vendor's meeting only) ────────────────────────────── */

    /** The caller's own kickoff meeting summary (resolved from the vendor subject). */
    public function kickoff(Request $request)
    {
        $meeting = $this->ownKickoff($request);
        if (! $meeting) {
            return response()->json(['meeting' => null]);
        }

        return response()->json(['meeting' => [
            'id'              => $meeting->id,
            'title'           => $meeting->title,
            'status'          => $meeting->status,
            'status_label'    => $meeting->status_label,
            'scheduled_at'    => optional($meeting->scheduled_at)->toIso8601String(),
            'mode'            => $meeting->mode,
            'location'        => $meeting->location,
            'meeting_link'    => $meeting->meeting_link,
            'mom_available'   => (bool) $meeting->mom_path,
            'acknowledged_at' => optional($meeting->acknowledged_at)->toIso8601String(),
        ]]);
    }

    /** Vendor acknowledges their own kickoff MOM (reuses KickoffMeetingService). */
    public function acceptKickoff(Request $request)
    {
        $meeting = $this->ownKickoff($request);
        abort_unless($meeting, 404, 'Kickoff not found');

        $vendor = $this->portalVendor($request);
        $ua = \App\Support\UserAgentInfo::parse($request->userAgent());

        $this->kickoffService->acknowledge($meeting, [
            'name' => $request->user()->name ?? $vendor->company_name,
        ], ['ip' => $request->ip(), 'browser' => $ua['browser'], 'device' => $ua['device']]);

        return response()->json(['status' => 'acknowledged']);
    }

    /** Resolve the kickoff meeting attached to the caller's own vendor (no URL id). */
    private function ownKickoff(Request $request): ?KickoffMeeting
    {
        $vendor = $this->portalVendor($request);

        return KickoffMeeting::forTenant($vendor->tenant_id)
            ->whereIn('kickoffable_type', [Vendor::class, 'vendor'])
            ->where('kickoffable_id', $vendor->id)
            ->latest()
            ->first();
    }
}
