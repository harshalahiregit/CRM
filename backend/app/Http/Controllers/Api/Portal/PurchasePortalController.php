<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\ResubmitPurchaseDocumentRequest;
use App\Http\Requests\Purchase\SavePurchaseOnboardingProfileRequest;
use App\Http\Requests\Purchase\UpdatePurchasePortalProfileRequest;
use App\Http\Requests\Purchase\UploadPurchaseDocumentRequest;
use App\Models\Purchase\PurchaseContract;
use App\Models\Purchase\PurchaseDebitNote;
use App\Models\Purchase\PurchaseDocument;
use App\Models\Purchase\PurchaseInvoice;
use App\Models\Purchase\PurchaseInvoicePayment;
use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseOnboarding;
use App\Models\Purchase\PurchaseOrder;
use App\Models\Purchase\PurchaseQuotation;
use App\Models\Purchase\PurchaseVendor;
use App\Services\Purchase\PurchaseDocumentService;
use App\Services\Purchase\PurchaseKickoffService;
use App\Services\Purchase\PurchaseOnboardingService;
use App\Services\Purchase\PurchaseWorkforceService;
use App\Support\Purchase\PurchaseVendorCategoryConfig;
use Illuminate\Http\Request;

/**
 * Purchase Vendor Portal — the self-service surface for a Purchase vendor. The
 * caller's Purchase vendor is resolved from the authenticated login
 * (purchase_vendors.user_id), never a URL param; sub-resources are guarded by
 * assertOwnedByVendor() which 404s anything that isn't the caller's
 * (existence-hiding). Admin-only actions are deliberately absent.
 *
 * Fully Purchase-owned: PurchaseOnboardingService, PurchaseDocumentService
 * (private `purchase_docs` disk) and PurchaseKickoffService. Independent of the
 * shared vendor portal trait and the shared Vendor master.
 */
class PurchasePortalController extends Controller
{
    public function __construct(
        private PurchaseOnboardingService $onboardingService,
        private PurchaseDocumentService $documentService,
        private PurchaseKickoffService $kickoffService,
    ) {
    }

    /** The caller's own vendor profile. */
    public function me(Request $request)
    {
        $vendor = $this->purchaseVendor($request);
        $vendor->loadMissing(['contacts']);
        // Drives the one-time post-activation welcome banner. Persisted
        // server-side, so dismissing it on one device dismisses it everywhere.
        $vendor->setAttribute('show_welcome_banner', $vendor->shouldShowWelcomeBanner());

        return response()->json(['vendor' => $vendor]);
    }

    /** Dismiss the welcome banner permanently for this vendor. */
    public function dismissWelcomeBanner(Request $request)
    {
        $vendor = $this->purchaseVendor($request);
        $vendor->dismissWelcomeBanner();

        return response()->json(['dismissed' => true]);
    }

    /** Rich dashboard for the caller's own Purchase vendor. */
    public function dashboard(Request $request)
    {
        $vendor = $this->purchaseVendor($request);

        $onboarding = PurchaseOnboarding::forTenant($vendor->tenant_id)->where('purchase_vendor_id', $vendor->id)->first()
            ?? $this->onboardingService->create(['purchase_vendor_id' => $vendor->id], $vendor);
        $progress = $this->onboardingService->stepStatus($onboarding);

        $steps       = $progress['steps'] ?? [];
        $onbPercent  = count($steps) ? (int) round(count(array_filter($steps, fn ($s) => $s['complete'])) / count($steps) * 100) : 0;
        $docSummary  = $progress['documents']['summary'] ?? [];
        $pendingDocs = max(0, (int) ($docSummary['required'] ?? 0) - (int) ($docSummary['approved'] ?? 0));

        $cfg       = PurchaseVendorCategoryConfig::resolve($vendor->category);
        $workforce = $cfg['requires_workforce'] ? app(PurchaseWorkforceService::class)->summary($vendor) : null;

        $meeting = $this->ownKickoff($request);

        $latest = fn (string $modelClass) => $modelClass::forTenant($vendor->tenant_id)
            ->where('purchase_vendor_id', $vendor->id)->latest('id')->limit(5)->get();

        $payments = PurchaseInvoicePayment::forTenant($vendor->tenant_id)
            ->whereHas('invoice', fn ($q) => $q->where('purchase_vendor_id', $vendor->id))
            ->with(['invoice:id,invoice_number,total,balance'])
            ->latest('payment_date')->limit(5)->get();

        return response()->json([
            'vendor' => [
                'id'            => $vendor->id,
                'company_name'  => $vendor->company_name,
                'vendor_code'   => $vendor->purchase_vendor_code,
                'category'      => $vendor->category,
                'portal_status' => $vendor->portal_status,
                'status'        => $vendor->status,
            ],
            'requires_workforce' => $cfg['requires_workforce'],
            'onboarding_steps'   => $cfg['onboarding_steps'],
            'onboarding' => [
                'status'              => $onboarding->status,
                'status_label'        => $onboarding->status_label ?? $onboarding->status,
                'percent'             => $onbPercent,
                'registration_number' => $onboarding->registration_number,
            ],
            'pending_documents' => $pendingDocs,
            'pending_approvals' => in_array($onboarding->status, ['Submitted', 'Under_Review'], true) ? 1 : 0,
            'upcoming_kickoff'  => $meeting ? [
                'id'           => $meeting->id,
                'title'        => $meeting->title,
                'status'       => $meeting->status,
                'scheduled_at' => optional($meeting->scheduled_at)->toIso8601String(),
            ] : null,
            'workforce' => $workforce,
            'latest' => [
                'orders'      => $latest(PurchaseOrder::class),
                'quotations'  => $latest(PurchaseQuotation::class),
                'contracts'   => $latest(PurchaseContract::class),
                'invoices'    => $latest(PurchaseInvoice::class),
                'debit_notes' => $latest(PurchaseDebitNote::class),
                'payments'    => $payments,
            ],
        ]);
    }

    /** Self-service profile update — business fields only (never code/category/status/auth). */
    public function updateProfile(UpdatePurchasePortalProfileRequest $request)
    {
        $vendor = $this->purchaseVendor($request);
        $vendor->update($request->validated());

        return response()->json(['vendor' => $vendor->fresh(['contacts'])]);
    }

    /* ── Onboarding (own vendor only) ───────────────────────────────────── */

    /** Own onboarding record + progress; started on first access. */
    public function onboarding(Request $request)
    {
        $vendor = $this->purchaseVendor($request);

        $onboarding = PurchaseOnboarding::forTenant($vendor->tenant_id)
            ->where('purchase_vendor_id', $vendor->id)->first()
            ?? $this->onboardingService->create(['purchase_vendor_id' => $vendor->id], $request->user());

        $onboarding->load(['vendor.contacts', 'vendor.documents', 'approver:id,name', 'auditLogs']);

        return response()->json([
            'onboarding' => $onboarding,
            'progress'   => $this->onboardingService->stepStatus($onboarding),
        ]);
    }

    public function onboardingShow(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertOwnedByVendor($request, $onboarding, 'Onboarding');
        $onboarding->load(['vendor.contacts', 'vendor.documents', 'approver:id,name', 'auditLogs']);

        return response()->json([
            'onboarding' => $onboarding,
            'progress'   => $this->onboardingService->stepStatus($onboarding),
        ]);
    }

    public function onboardingProgress(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertOwnedByVendor($request, $onboarding, 'Onboarding');

        return response()->json($this->onboardingService->stepStatus($onboarding));
    }

    public function saveProfile(SavePurchaseOnboardingProfileRequest $request, PurchaseOnboarding $onboarding)
    {
        $this->assertOwnedByVendor($request, $onboarding, 'Onboarding');

        return response()->json(
            $this->onboardingService->saveProfile($onboarding, $request->validated()['profile'], $request->user())
        );
    }

    public function setStep(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertOwnedByVendor($request, $onboarding, 'Onboarding');
        $data = $request->validate(['step' => 'required|integer|min:1|max:6']);

        return response()->json($this->onboardingService->setStep($onboarding, $data['step'], $request->user()));
    }

    public function submitOnboarding(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertOwnedByVendor($request, $onboarding, 'Onboarding');

        return response()->json($this->onboardingService->submit($onboarding, $request->user()));
    }

    /* ── Onboarding kickoff (Step 1 — own onboarding only) ──────────────── */

    /** Stream the kickoff MOM PDF for the caller's own onboarding. */
    public function onboardingKickoffPdf(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertOwnedByVendor($request, $onboarding, 'Onboarding');

        $meeting = $this->onboardingService->resolveKickoffMeeting($onboarding);
        abort_unless($meeting, 404, 'Kickoff MOM not available yet.');

        $file = $this->kickoffService->currentMomFile($meeting);
        if (! $file) {
            try {
                $meeting = $this->kickoffService->generateMom($meeting, $request->user());
                $file = $this->kickoffService->currentMomFile($meeting);
            } catch (\Throwable $e) {
                abort(404, 'Kickoff MOM not available yet.');
            }
        }
        abort_unless($file, 404, 'Kickoff MOM not available yet.');

        return response()->download($file['path'], 'kickoff-mom.pdf', [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="kickoff-mom.pdf"',
        ]);
    }

    /** Vendor acknowledges their own kickoff MOM (by onboarding). */
    public function onboardingAcceptKickoff(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertOwnedByVendor($request, $onboarding, 'Onboarding');
        $ua = \App\Support\UserAgentInfo::parse($request->userAgent());

        return response()->json($this->onboardingService->acknowledgeKickoff($onboarding, $request->user(), [
            'ip' => $request->ip(), 'browser' => $ua['browser'], 'device' => $ua['device'],
        ]));
    }

    /** Audit a kickoff PDF interaction (viewed / downloaded / printed) by onboarding. */
    public function onboardingLogKickoffEvent(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertOwnedByVendor($request, $onboarding, 'Onboarding');
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
        return response()->json($this->documentService->checklist($this->purchaseVendor($request)));
    }

    public function uploadDocument(UploadPurchaseDocumentRequest $request)
    {
        $vendor = $this->purchaseVendor($request);
        $doc = $this->documentService->upload($vendor, $request->input('type'), $request->file('file'), $request->user());

        return response()->json($doc, 201);
    }

    public function resubmitDocument(ResubmitPurchaseDocumentRequest $request, PurchaseDocument $document)
    {
        $this->assertOwnedByVendor($request, $document, 'Document');

        return response()->json($this->documentService->resubmit($document, $request->file('file'), $request->user()));
    }

    public function downloadDocument(Request $request, PurchaseDocument $document)
    {
        $this->assertOwnedByVendor($request, $document, 'Document');

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
            'mom_available'   => $meeting->currentMom()->exists(),
            'acknowledged_at' => optional($meeting->acknowledged_at)->toIso8601String(),
        ]]);
    }

    /** Vendor acknowledges their own kickoff MOM (Purchase-owned kickoff engine). */
    public function acceptKickoff(Request $request)
    {
        $meeting = $this->ownKickoff($request);
        abort_unless($meeting, 404, 'Kickoff not found');

        $vendor = $this->purchaseVendor($request);
        $ua = \App\Support\UserAgentInfo::parse($request->userAgent());

        $this->kickoffService->acknowledge($meeting, [
            'name' => $request->user()->name ?? $vendor->company_name,
        ], ['ip' => $request->ip(), 'browser' => $ua['browser'], 'device' => $ua['device']]);

        return response()->json(['status' => 'acknowledged']);
    }

    /** Resolve the Purchase kickoff meeting attached to the caller's own vendor (no URL id). */
    private function ownKickoff(Request $request): ?PurchaseKickoffMeeting
    {
        $vendor = $this->purchaseVendor($request);

        return PurchaseKickoffMeeting::forTenant($vendor->tenant_id)
            ->where('purchase_vendor_id', $vendor->id)
            ->latest()
            ->first();
    }

    /* ── Purchase-owned portal resolution (independent of the shared vendor
     * portal trait). The caller is the authenticated portal user; their Purchase
     * vendor is resolved by purchase_vendors.user_id. ── */

    /**
     * The caller's own Purchase vendor — the authenticated Sanctum identity itself
     * (tokenable = PurchaseVendor), guaranteed by the purchase.vendor.portal
     * middleware. No shared User/Vendor lookup; no vendor id in the URL.
     */
    private function purchaseVendor(Request $request): PurchaseVendor
    {
        $vendor = $request->user();
        abort_unless($vendor instanceof PurchaseVendor, 401, 'Unauthenticated');

        return $vendor;
    }

    /**
     * 404 if the model isn't owned by the caller's Purchase vendor. Deliberately
     * 404, not 403 (existence-hiding). Onboarding/document/etc. carry
     * purchase_vendor_id.
     */
    private function assertOwnedByVendor(Request $request, ?\Illuminate\Database\Eloquent\Model $model, string $label = 'Record'): void
    {
        $vendor = $this->purchaseVendor($request);

        abort_if(
            ! $model || (int) ($model->purchase_vendor_id ?? 0) !== (int) $vendor->id,
            404,
            "{$label} not found",
        );
    }
}
