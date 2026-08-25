<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Support\Task\VendorTaskLink;
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
use App\Services\Tpv\PpeInventoryService;
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
        private \App\Services\Purchase\PurchaseComplianceService $complianceService,
    ) {
    }

    /**
     * §32 "View compliance" — the Purchase vendor's own compliance register
     * (read-only), scoped to the caller.
     */
    public function compliance(Request $request)
    {
        $vendor = $this->purchaseVendor($request);

        return response()->json([
            'matrix' => $this->complianceService->vendorMatrix((int) $vendor->tenant_id, (int) $vendor->id),
            'score'  => $this->complianceService->scoreFor((int) $vendor->tenant_id, (int) $vendor->id),
        ]);
    }

    /** The caller's own vendor profile. */
    public function me(Request $request)
    {
        $vendor = $this->purchaseVendor($request);
        $vendor->loadMissing(['contacts', 'accountManager']);
        // Drives the one-time post-activation welcome banner. Persisted
        // server-side, so dismissing it on one device dismisses it everywhere.
        $vendor->setAttribute('show_welcome_banner', $vendor->shouldShowWelcomeBanner());

        return response()->json(['vendor' => $vendor]);
    }

    /** Dismiss the welcome banner permanently for this vendor. */
    /**
     * Tasks raised against this Purchase vendor.
     *
     * The ambient identity is a PurchaseVendor, never a User, so there is no
     * assignee row to match on -- the link is tasks.rel_type='purchase_vendor'.
     * That is why this endpoint exists here rather than reusing the shared
     * "My Work" portal, which is gated on a User role.
     */
    public function tasks(Request $request)
    {
        $vendor = $this->purchaseVendor($request);

        return response()->json([
            'summary' => VendorTaskLink::summary(VendorTaskLink::PURCHASE, $vendor->id, (int) $vendor->tenant_id),
            'tasks'   => VendorTaskLink::forVendor(VendorTaskLink::PURCHASE, $vendor->id, (int) $vendor->tenant_id),
        ]);
    }

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
    /**
     * GET /portal/purchase/ppe/summary — stock visibility for a Purchase vendor.
     *
     * Availability stays tenant-wide: there is one central store and the point of
     * this page is seeing what is on the shelf. The ISSUED figures do not, and
     * that is the whole reason this method exists — the route used to call
     * PpeInventoryService::summary(), which counts every tpv_worker_ppe_issue in
     * the tenant, so a Purchase vendor was shown how much PPE other vendors were
     * holding. PpeInventoryService's own docblock states that must not be
     * tenant-wide.
     *
     * Purchase vendors have no PPE issuance of their own (no
     * purchase_worker_ppe_issues table and no issue/return route), so their own
     * issued figures are zero rather than someone else's.
     */
    public function ppeSummary(Request $request, PpeInventoryService $ppe)
    {
        $vendor = $this->purchaseVendor($request);
        $rows   = $ppe->catalogue((int) $vendor->tenant_id);

        return response()->json([
            'total_items'        => $rows->count(),
            'total_available'    => (float) $rows->sum('available'),
            'low_stock_items'    => $rows->where('status', 'low_stock')->count(),
            'out_of_stock_items' => $rows->where('status', 'out_of_stock')->count(),
            // Purchase issues no PPE — these are its own totals, not the tenant's.
            'total_issued'       => 0.0,
            'issued_today'       => 0.0,
            'returned_today'     => 0.0,
        ]);
    }

    private function purchaseVendor(Request $request): PurchaseVendor
    {
        $vendor = $request->user();
        // #45 — 403, not 401: an authenticated identity of the WRONG TYPE is a
        // permission failure, and a 401 here would clear the caller's session.
        // EnsurePurchaseVendorPortalAccess already answers this case with 403;
        // this is the same answer for the defence-in-depth check.
        abort_unless($vendor instanceof PurchaseVendor, 403, 'This area is for Purchase vendor accounts only.');

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
