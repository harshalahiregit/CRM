<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tpv\IssueWorkerPpeRequest;
use App\Http\Requests\Tpv\SaveOnboardingProfileRequest;
use App\Http\Requests\Tpv\SaveWorkerInductionRequest;
use App\Http\Requests\Tpv\SaveWorkerMedicalRequest;
use App\Http\Requests\Tpv\StoreTpvWorkerRequest;
use App\Http\Requests\Tpv\SubmitOnboardingRequest;
use App\Http\Requests\Tpv\UpdateTpvWorkerRequest;
use App\Http\Requests\Vendor\ResubmitVendorDocumentRequest;
use App\Http\Requests\Vendor\UploadVendorDocumentRequest;
use App\Models\Purchase\PurchaseInvoice;
use App\Models\Purchase\PurchaseOrder;
use App\Models\Shared\KickoffMeeting;
use App\Models\Tpv\TpvOnboarding;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerPpeIssue;
use App\Models\Vendor\TpvContact;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorDocument;
use App\Services\Tpv\KickoffPdfService;
use App\Services\Tpv\WorkStartLetterService;
use App\Services\Tpv\PpeInventoryService;
use App\Services\Tpv\TpvOnboardingService;
use App\Services\Tpv\TpvWorkerService;
use App\Services\Vendor\VendorDocumentService;
use App\Support\Purchase\PurchaseInvoiceStatus as InvStatus;
use App\Support\Purchase\PurchaseOrderStatus as PoStatus;
use App\Support\UserAgentInfo;
use Illuminate\Http\Request;

/**
 * Vendor self-service portal — read-only for v1 (purchase-side),
 * full TPV self-service for third_party_vendor logins.
 *
 * Every method reads its subject from the ambient portalVendor (resolved by the
 * EnsureVendorPortalAccess middleware from the token), never from a route
 * parameter. Sub-resource lookups go through assertOwned(), which 404s anything
 * that isn't the caller's.
 *
 * Vendor portal TPV methods delegate to the SAME services used by the admin
 * controllers — no business logic is duplicated.
 */
class VendorPortalController extends Controller
{
    use ResolvesPortalVendor;

    public function __construct(
        private TpvOnboardingService $onboardingService,
        private TpvWorkerService     $workerService,
        private VendorDocumentService $documentService,
        private KickoffPdfService    $kickoffPdfService,
        private PpeInventoryService  $ppeService,
        private \App\Services\Tpv\TpvComplianceService $complianceService,
    ) {
    }

    /**
     * §32 "View compliance" — the vendor's own compliance register (read-only).
     * Scoped to the caller's vendor; the vendor never sees anyone else's status.
     */
    public function compliance(Request $request)
    {
        $vendor = $this->portalVendor($request);
        if (! $vendor) {
            return response()->json(['status' => 'error', 'message' => 'Vendor profile not found'], 404);
        }

        return response()->json([
            'matrix' => $this->complianceService->vendorMatrix((int) $vendor->tenant_id, (int) $vendor->id),
            'score'  => $this->complianceService->scoreFor((int) $vendor->tenant_id, (int) $vendor->id),
        ]);
    }

    /**
     * Performance › Risk Score — the vendor's OWN risk classification (read-only).
     * A vendor may see its score, tier and the factor breakdown, but never set it
     * (assessment is an admin authority decision). Internal config (catalogue/bands)
     * is stripped from the vendor-facing payload.
     */
    public function risk(Request $request, \App\Services\Vendor\VendorRiskService $riskService)
    {
        $vendor = $this->portalVendor($request);
        if (! $vendor) {
            return response()->json(['status' => 'error', 'message' => 'Vendor profile not found'], 404);
        }

        $snap = $riskService->snapshot($vendor);

        return response()->json([
            'assessed'    => $snap['assessed'],
            'level'       => $snap['level'],
            'score'       => $snap['score'],
            'monitoring'  => $snap['monitoring'],
            'breakdown'   => $snap['breakdown'],
            'assessed_at' => $snap['assessed_at'],
        ]);
    }

    /**
     * Performance › Penalty — the vendor's OWN violations/strikes (read-only), with
     * the running penalty-point total. Raising/voiding a violation is admin-only.
     */
    public function violations(Request $request)
    {
        $vendor = $this->portalVendor($request);
        if (! $vendor) {
            return response()->json(['status' => 'error', 'message' => 'Vendor profile not found'], 404);
        }

        $rows = \App\Models\Tpv\TpvVendorViolation::where('tenant_id', $vendor->tenant_id)
            ->where('vendor_id', $vendor->id)
            ->latest('occurred_at')
            ->get(['id', 'reference', 'type', 'severity', 'description', 'occurred_at', 'points', 'status']);

        return response()->json([
            'data'         => $rows,
            'total_points' => (int) $rows->sum('points'),
            'open_count'   => $rows->where('status', '!=', 'Closed')->count(),
        ]);
    }

    /** The caller's own vendor profile + headline account state. */
    /** Dismiss the post-activation welcome banner permanently for this vendor. */
    public function dismissWelcomeBanner(Request $request)
    {
        $vendor = $this->portalVendor($request);
        if (! $vendor) {
            return response()->json(['status' => 'error', 'message' => 'Vendor profile not found'], 404);
        }
        $vendor->dismissWelcomeBanner();

        return response()->json(['dismissed' => true]);
    }

    public function me(Request $request)
    {
        try {
            $vendor = $this->portalVendor($request);
            if (! $vendor) {
                return response()->json(['status' => 'error', 'message' => 'Vendor profile not found'], 404);
            }
            $vendor->loadMissing(['contacts', 'accountManager:id,name,email']);
            // Drives the one-time post-activation welcome banner. Persisted
            // server-side, so dismissing it on one device dismisses it everywhere.
            $vendor->setAttribute('show_welcome_banner', $vendor->shouldShowWelcomeBanner());
            // Same key, same type -- the CORRECT value. The raw is_temporary column
            // is unset on every row the admin/self-registration paths write, so the
            // payload said false for genuine temporary vendors and the portal's
            // countdown banner hid itself. isTemporary() reads registration_type,
            // which is the stored choice and the source of truth.
            $vendor->setAttribute('is_temporary', $vendor->isTemporary());

            $openOrders = 0;
            $unpaidInvoices = 0;
            $outstandingBalance = 0.0;

            try {
                $openOrders = PurchaseOrder::forTenant($vendor->tenant_id)
                                ->where('vendor_id', $vendor->id)
                                ->whereIn('status', [PoStatus::ISSUED, PoStatus::PARTIALLY_RECEIVED])->count();
            } catch (\Throwable $e) { /* ignore schema differences */ }

            try {
                $unpaidInvoices = PurchaseInvoice::forTenant($vendor->tenant_id)
                                    ->where('vendor_id', $vendor->id)
                                    ->whereIn('status', InvStatus::PAYABLE)->count();
                $outstandingBalance = (float) PurchaseInvoice::forTenant($vendor->tenant_id)
                                        ->where('vendor_id', $vendor->id)
                                        ->whereIn('status', InvStatus::PAYABLE)->sum('balance');
            } catch (\Throwable $e) { /* ignore schema differences */ }

            return response()->json([
                'vendor' => $vendor,
                'summary' => [
                    'open_orders'         => $openOrders,
                    'unpaid_invoices'     => $unpaidInvoices,
                    'outstanding_balance' => $outstandingBalance,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Portal me error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /* ── Onboarding (TPV self-service) ─────────────────────────────────── */

    /**
     * The caller's own onboarding record list — returns an array of at most one
     * record so the frontend onboarding-list page works without modification.
     */
    public function onboarding(Request $request)
    {
        try {
            $vendor = $this->portalVendor($request);
            if (! $vendor) {
                return response()->json(['status' => 'error', 'message' => 'Vendor profile not found'], 404);
            }

            $onboarding = TpvOnboarding::forTenant($vendor->tenant_id)
                ->where('vendor_id', $vendor->id)
                ->latest()
                ->first();

            if (! $onboarding) {
                $onboarding = TpvOnboarding::create([
                    'tenant_id'    => $vendor->tenant_id,
                    'vendor_id'    => $vendor->id,
                    'status'       => 'In_Progress',
                    'current_step' => 1,
                ]);
            }

            if (! $onboarding->kickoff_meeting_id) {
                try {
                    // One resolver for the whole module. This used to match
                    // kickoffable_id alone, which names only ONE vendor on a
                    // multi-vendor kickoff — every secondary linked to nothing here
                    // while the PDF stream (already pivot-aware) resolved fine, so
                    // the pointer and the document disagreed. findKickoffMeeting()
                    // also requires Completed + a stored MOM, so an in-progress
                    // meeting is no longer written to the pointer.
                    $kickoff = $this->kickoffPdfService->findKickoffMeeting($onboarding);

                    if ($kickoff) {
                        $onboarding->update(['kickoff_meeting_id' => $kickoff->id]);
                    }
                } catch (\Throwable $e) { /* non-fatal kickoff lookup */ }
            }

            $onboarding->load(['vendor.documents']);

            $progress = null;
            try {
                $progress = $this->onboardingService->stepStatus($onboarding);
            } catch (\Throwable $e) { /* non-fatal stepStatus calculation */ }

            return response()->json([
                'onboarding' => $onboarding,
                'progress'   => $progress,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Portal onboarding error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /** Single onboarding record — own only. */
    public function onboardingShow(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertOwned($request, $onboarding, 'Onboarding');

        $onboarding->load(['vendor.contacts', 'vendor.documents', 'approver:id,name', 'auditLogs']);

        return response()->json([
            'onboarding' => $onboarding,
            'progress'   => $this->onboardingService->stepStatus($onboarding),
        ]);
    }

    /** Live step progress for the wizard sidebar. */
    public function onboardingProgress(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertOwned($request, $onboarding, 'Onboarding');

        return response()->json($this->onboardingService->stepStatus($onboarding));
    }

    /** Stream the Kickoff PDF. */
    public function kickoffPdf(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertOwned($request, $onboarding, 'Onboarding');

        return $this->kickoffPdfService->stream($onboarding);
    }

    /** Stream this vendor's own HSSE Work Start Letter (issued on approval). */
    public function workStartLetter(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertOwned($request, $onboarding, 'Onboarding');

        return app(WorkStartLetterService::class)->stream($onboarding);
    }

    /**
     * Published Knowledge Base articles for the vendor's tenant — the KB the
     * dashboard surfaces alongside Projects/Tasks/Tickets (enhancement #6).
     * Read-only, published-only, tenant-scoped; authoring stays admin-side.
     */
    public function kbArticles(Request $request)
    {
        $tid = $request->user()->tenant_id;

        $articles = \App\Models\Helpdesk\KbArticle::where('tenant_id', $tid)
            ->published()
            ->with('category:id,name')
            ->latest('published_at')
            ->limit((int) $request->integer('limit', 20))
            ->get(['id', 'category_id', 'title', 'excerpt', 'public_slug', 'published_at']);

        return response()->json(['data' => $articles->map(fn ($a) => [
            'id'           => $a->id,
            'title'        => $a->title,
            'excerpt'      => $a->excerpt,
            'slug'         => $a->public_slug,
            'category'     => $a->category?->name,
            'published_at' => optional($a->published_at)->toDateString(),
        ])]);
    }

    /** Full body of one published KB article (tenant-scoped, published-only). */
    public function kbArticle(Request $request, string $slug)
    {
        $tid = $request->user()->tenant_id;

        $article = \App\Models\Helpdesk\KbArticle::where('tenant_id', $tid)
            ->published()
            ->with('category:id,name')
            ->where('public_slug', $slug)
            ->firstOrFail(['id', 'category_id', 'title', 'excerpt', 'content', 'public_slug', 'published_at']);

        return response()->json(['data' => [
            'id'       => $article->id,
            'title'    => $article->title,
            'excerpt'  => $article->excerpt,
            'content'  => $article->content,
            'category' => $article->category?->name,
        ]]);
    }

    /** Record the vendor's acknowledgement of the Kickoff PDF. */
    public function acceptKickoff(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertOwned($request, $onboarding, 'Onboarding');

        // Optional — every existing caller posts an empty body, and must keep
        // working unchanged.
        $data = $request->validate(['comment' => 'nullable|string|max:5000']);
        $ua   = UserAgentInfo::parse($request->userAgent());

        return response()->json(
            $this->onboardingService->acknowledgeKickoff($onboarding, $request->user(), [
                'ip' => $request->ip(), 'browser' => $ua['browser'], 'device' => $ua['device'],
                'comment' => $data['comment'] ?? null,
            ])
        );
    }

    /** Audit a Kickoff PDF interaction (viewed / downloaded / printed). */
    public function logKickoffEvent(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertOwned($request, $onboarding, 'Onboarding');

        $data = $request->validate(['event' => 'required|in:viewed,downloaded,printed']);
        $ua   = UserAgentInfo::parse($request->userAgent());

        $this->onboardingService->logKickoffEvent($onboarding, $data['event'], $request->user(), [
            'ip' => $request->ip(), 'browser' => $ua['browser'], 'device' => $ua['device'],
        ]);

        return response()->json(['status' => 'logged']);
    }

    /** Save the vendor company profile (Step 2). */
    public function saveProfile(SaveOnboardingProfileRequest $request, TpvOnboarding $onboarding)
    {
        $this->assertOwned($request, $onboarding, 'Onboarding');

        return response()->json(
            $this->onboardingService->saveProfile($onboarding, $request->validated()['profile'], $request->user())
        );
    }

    /** Move the wizard to a different step (persists the navigation pointer). */
    public function setStep(Request $request, TpvOnboarding $onboarding)
    {
        $this->assertOwned($request, $onboarding, 'Onboarding');

        $data = $request->validate(['step' => 'required|integer|min:1|max:6']);

        return response()->json(
            $this->onboardingService->setStep($onboarding, $data['step'], $request->user())
        );
    }

    /**
     * Vendor submits for admin review (Step 6).
     * Status → Submitted. The vendor CANNOT approve themselves; approve() is
     * admin-only and deliberately absent from this portal route group.
     */
    public function submitOnboarding(SubmitOnboardingRequest $request, TpvOnboarding $onboarding)
    {
        $this->assertOwned($request, $onboarding, 'Onboarding');

        $ua = UserAgentInfo::parse($request->userAgent());

        return response()->json($this->onboardingService->submit($onboarding, $request->user(), [
            'ip' => $request->ip(), 'browser' => $ua['browser'], 'device' => $ua['device'],
        ]));
    }

    /* ── Statutory documents (the portal's write actions) ───────────────── */

    /** The caller's own required-vs-uploaded document checklist. */
    public function documents(Request $request)
    {
        $vendor = $this->portalVendor($request);

        return response()->json($this->documentService->checklist($vendor));
    }

    /** Upload one of the caller's own compliance documents. */
    public function uploadDocument(UploadVendorDocumentRequest $request)
    {
        $vendor = $this->portalVendor($request);

        $doc = $this->documentService->upload(
            $vendor,
            $request->input('type'),
            $request->file('file'),
            $request->user(),
        );

        return response()->json($doc, 201);
    }

    /** Replace the file on a rejected document — only if it's the caller's own. */
    public function resubmitDocument(ResubmitVendorDocumentRequest $request, VendorDocument $document)
    {
        $this->assertOwned($request, $document, 'Document');

        return response()->json(
            $this->documentService->resubmit($document, $request->file('file'), $request->user())
        );
    }

    /** Download the caller's own document. */
    public function downloadDocument(Request $request, VendorDocument $document)
    {
        $this->assertOwned($request, $document, 'Document');

        $file = $this->documentService->resolveDownload($document);

        return response()->download($file['path'], $file['filename'], ['Content-Type' => $file['mime']]);
    }

    /* ── Contacts (own vendor only) ──────────────────────────────────────── */

    public function contacts(Request $request)
    {
        $vendor = $this->portalVendor($request);

        return response()->json(
            TpvContact::where('vendor_id', $vendor->id)
                ->orderByDesc('is_primary')
                ->orderBy('name')
                ->get()
        );
    }

    public function storeContact(Request $request)
    {
        $vendor = $this->portalVendor($request);

        $data = $request->validate([
            'name'        => 'required|string|max:120',
            'designation' => 'nullable|string|max:120',
            'email'       => 'nullable|email|max:180',
            'phone'       => 'nullable|string|max:30',
            'is_primary'  => 'boolean',
        ]);

        $contact = TpvContact::create(array_merge($data, ['vendor_id' => $vendor->id]));

        return response()->json($contact, 201);
    }

    public function updateContact(Request $request, TpvContact $contact)
    {
        $vendor = $this->portalVendor($request);
        abort_unless((int) $contact->vendor_id === (int) $vendor->id, 404, 'Contact not found');

        $data = $request->validate([
            'name'        => 'sometimes|string|max:120',
            'designation' => 'nullable|string|max:120',
            'email'       => 'nullable|email|max:180',
            'phone'       => 'nullable|string|max:30',
            'is_primary'  => 'boolean',
        ]);

        $contact->update($data);

        return response()->json($contact->fresh());
    }

    public function setContactStatus(Request $request, TpvContact $contact)
    {
        $vendor = $this->portalVendor($request);
        abort_unless((int) $contact->vendor_id === (int) $vendor->id, 404, 'Contact not found');

        $data = $request->validate(['status' => 'required|in:Active,Inactive']);
        $contact->update(['status' => $data['status']]);

        return response()->json($contact->fresh());
    }

    /* ── Workers (own vendor only) ───────────────────────────────────────── */

    /**
     * Worker list — always scoped to the authenticated vendor from the token.
     * The client may pass filter params but never a vendor_id override.
     */
    public function workers(Request $request)
    {
        $vendor = $this->portalVendor($request);

        return response()->json(
            $this->workerService->list(
                $vendor->tenant_id,
                // Force vendor_id from token; ignore any client-supplied vendor_id.
                array_merge($request->only(['status', 'skill_category', 'search']), ['vendor_id' => $vendor->id])
            )
        );
    }

    /** Worker stats derived from the vendor's own workers only. */
    public function workerStats(Request $request)
    {
        $vendor = $this->portalVendor($request);

        // The admin stats endpoint is tenant-wide; for the portal we scope it.
        return response()->json(
            $this->workerService->statsForVendor($vendor->id, $vendor->tenant_id)
        );
    }

    public function storeWorker(StoreTpvWorkerRequest $request)
    {
        $vendor = $this->portalVendor($request);

        // Inject vendor_id from the portal context — the request may carry any
        // vendor_id the client sends, but the service must use the authenticated one.
        $data = array_merge($request->validated(), ['vendor_id' => $vendor->id]);

        return response()->json($this->workerService->create($data, $request->user()), 201);
    }

    public function showWorker(Request $request, TpvWorker $worker)
    {
        $this->assertWorkerOwned($request, $worker);

        $worker->load(['vendor', 'medical.recorder:id,name', 'induction.recorder:id,name',
                       'ppeIssues.issuer:id,name', 'creator:id,name', 'auditLogs']);

        return response()->json([
            'worker'   => $worker,
            'progress' => $this->workerService->stepStatus($worker),
        ]);
    }

    public function workerProgress(Request $request, TpvWorker $worker)
    {
        $this->assertWorkerOwned($request, $worker);

        return response()->json($this->workerService->stepStatus($worker));
    }

    public function updateWorker(Request $request, TpvWorker $worker, UpdateTpvWorkerRequest $updateRequest)
    {
        $this->assertWorkerOwned($request, $worker);

        return response()->json($this->workerService->update($worker, $updateRequest->validated(), $request->user()));
    }

    public function saveMedical(SaveWorkerMedicalRequest $request, TpvWorker $worker)
    {
        $this->assertWorkerOwned($request, $worker);

        return response()->json($this->workerService->saveMedical($worker, $request->validated(), $request->user()));
    }

    public function saveInduction(SaveWorkerInductionRequest $request, TpvWorker $worker)
    {
        $this->assertWorkerOwned($request, $worker);

        return response()->json($this->workerService->saveInduction($worker, $request->validated(), $request->user()));
    }

    /* ── PPE (Workforce Step 4) ────────────────────────────────────────
     *
     * These four used to be the ADMIN PpeController and PpeRequirementController,
     * mounted straight into this portal group. Those controllers guard on tenant
     * alone, so any vendor could read another vendor's PPE — and, worse, issue
     * and write off kit against another vendor's worker, moving shared Inventory
     * stock. Same services, ownership-checked, so there is one stock ledger and
     * one set of business rules.
     */

    /** One of the caller's own workers' PPE history. */
    public function workerPpe(Request $request, TpvWorker $worker)
    {
        $this->assertWorkerOwned($request, $worker);

        return response()->json(
            $this->ppeService->forWorker($worker->id, (int) $worker->tenant_id)->values()
        );
    }

    /** The role-based PPE checklist for one of the caller's own workers. */
    public function workerPpeCompliance(Request $request, TpvWorker $worker)
    {
        $this->assertWorkerOwned($request, $worker);

        return response()->json($this->ppeService->complianceFor($worker));
    }

    /**
     * Issue PPE to the caller's own worker.
     *
     * `warehouse_id` is deliberately NOT accepted: the site is resolved
     * server-side from the tenant's default so a vendor can never name another
     * tenant's warehouse. Stock rules (availability, no-negative) stay in
     * PpeInventoryService / StockService — none of them are re-implemented here.
     */
    public function issueWorkerPpe(Request $request, TpvWorker $worker)
    {
        $this->assertWorkerOwned($request, $worker);

        $data = $request->validate([
            'inventory_item_id' => 'required|integer|min:1',
            'qty'               => 'required|numeric|min:0.001',
            'size'              => 'nullable|string|max:40',
            'issued_date'       => 'nullable|date',
            'notes'             => 'nullable|string|max:500',
        ]);

        return response()->json($this->ppeService->issue($worker, $data, $request->user()), 201);
    }

    /** Return or write off PPE held by one of the caller's own workers. */
    public function returnWorkerPpe(Request $request, TpvWorkerPpeIssue $issue)
    {
        // The issue row has no vendor_id of its own — ownership is inherited from
        // the worker holding it, so that is what gets checked. An issue whose
        // worker is missing is treated as not found rather than as unowned.
        $this->assertWorkerOwned($request, $issue->worker);

        $data = $request->validate([
            'qty'       => 'nullable|numeric|min:0.001',
            'condition' => 'required|in:returned,lost,damaged',
            'notes'     => 'nullable|string|max:500',
        ]);

        return response()->json($this->ppeService->returnIssue($issue, $data, $request->user()));
    }

    /** PPE dashboard figures, with the "issued" side scoped to this vendor. */
    public function ppeSummary(Request $request)
    {
        $vendor = $this->portalVendor($request);

        return response()->json(
            $this->ppeService->summaryForVendor($vendor->id, (int) $vendor->tenant_id)
        );
    }

    /**
     * Apply a safety punch to the caller's own worker.
     *
     * The portal used to call the ADMIN route /tpv/workers/{worker}/mark-punch,
     * which is why `third_party_vendor` had been added to the admin role gate —
     * and that gate covers the whole TPV module, including every vendor's
     * onboarding. This endpoint exists so the portal has its own ownership-
     * checked door and the admin gate can go back to admin,staff.
     *
     * Same validation and the same TpvWorkerService::applyPunch the admin
     * controller uses; only the authorisation differs.
     */
    public function markWorkerPunch(Request $request, TpvWorker $worker)
    {
        $this->assertWorkerOwned($request, $worker);

        $data = $request->validate([
            'punch_count'  => 'required|integer|in:1,2,3',
            'punch_reason' => 'required|string',
        ]);

        $updated = $this->workerService->applyPunch(
            $worker,
            (int) $data['punch_count'],
            (string) $data['punch_reason'],
            $request->user(),
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Punch '.$updated->punch_count.' applied successfully.',
            'worker'  => $updated,
        ]);
    }

    /** Record the entry-card decision for the caller's own worker. */
    public function markWorkerCardStatus(Request $request, TpvWorker $worker)
    {
        $this->assertWorkerOwned($request, $worker);

        $this->workerService->markCard($worker, (int) $request->input('card_status', 1));

        return response()->json([
            'status'  => 'success',
            'message' => 'Entry card status updated successfully.',
        ]);
    }

    public function workerAttendance(Request $request, TpvWorker $worker)
    {
        $this->assertWorkerOwned($request, $worker);

        $days = (int) $request->query('days', 30);

        return response()->json(
            \App\Models\Tpv\TpvGateLog::where('worker_id', $worker->id)
                ->where('scanned_at', '>=', now()->subDays($days))
                ->orderByDesc('scanned_at')
                ->get()
        );
    }

    public function workerStrikes(Request $request, TpvWorker $worker)
    {
        $this->assertWorkerOwned($request, $worker);

        return response()->json(
            $worker->strikes()->orderByDesc('issued_at')->get()
        );
    }

    /* ── Gate / Attendance / Strikes (read-only, own vendor scoped) ──────── */

    public function gateStats(Request $request)
    {
        $vendor = $this->portalVendor($request);

        // Derive stats from own workers only.
        $workerIds = TpvWorker::where('vendor_id', $vendor->id)->pluck('id');

        return response()->json([
            'on_site'   => \App\Models\Tpv\TpvGateLog::whereIn('worker_id', $workerIds)
                               ->whereNull('check_out_at')->count(),
            'total_today' => \App\Models\Tpv\TpvGateLog::whereIn('worker_id', $workerIds)
                               ->whereDate('scanned_at', today())->count(),
        ]);
    }

    public function gateLog(Request $request)
    {
        $vendor    = $this->portalVendor($request);
        $workerIds = TpvWorker::where('vendor_id', $vendor->id)->pluck('id');

        $query = \App\Models\Tpv\TpvGateLog::with('worker:id,name,worker_code,designation')
            ->whereIn('worker_id', $workerIds)
            ->orderByDesc('scanned_at');

        if ($request->filled('date')) {
            $query->whereDate('scanned_at', $request->date);
        }
        if ($request->filled('decision')) {
            $query->where('decision', $request->decision);
        }

        return response()->json($query->get());
    }

    public function attendance(Request $request)
    {
        $vendor    = $this->portalVendor($request);
        $workerIds = TpvWorker::where('vendor_id', $vendor->id)->pluck('id');

        $date = $request->query('date', today()->toDateString());

        $rows = \App\Models\Tpv\TpvGateLog::with('worker:id,name,worker_code,designation')
            ->whereIn('worker_id', $workerIds)
            ->whereDate('scanned_at', $date)
            ->orderBy('check_in_at')
            ->get();

        $onSite = $rows->whereNull('check_out_at')->count();

        return response()->json([
            'date'    => $date,
            'summary' => [
                'total'     => $rows->count(),
                'on_site'   => $onSite,
                'departed'  => $rows->count() - $onSite,
                'total_minutes' => $rows->sum('duration_minutes'),
            ],
            'rows' => $rows,
        ]);
    }

    public function strikes(Request $request)
    {
        $vendor    = $this->portalVendor($request);
        $workerIds = TpvWorker::where('vendor_id', $vendor->id)->pluck('id');

        $query = \App\Models\Tpv\TpvSafetyStrike::with('worker:id,name,worker_code')
            ->whereIn('worker_id', $workerIds)
            ->orderByDesc('issued_at');

        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }
        if ($request->boolean('active')) {
            $query->whereNull('voided_at');
        }

        return response()->json($query->get());
    }

    /* ── Purchase orders (read-only) ────────────────────────────────────── */

    public function orders(Request $request)
    {
        $vendor = $this->portalVendor($request);

        // Draft POs are internal — a vendor sees an order only once it's issued.
        $orders = PurchaseOrder::forTenant($vendor->tenant_id)
            ->where('vendor_id', $vendor->id)
            ->where('status', '!=', PoStatus::DRAFT)
            ->latest()
            ->get(['id', 'po_number', 'title', 'status', 'total', 'currency', 'order_date', 'expected_delivery_date']);

        return response()->json($orders);
    }

    public function order(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->assertOwned($request, $purchaseOrder, 'Purchase order');
        // Even owned, a Draft is not yet shared with the vendor.
        abort_if($purchaseOrder->status === PoStatus::DRAFT, 404, 'Purchase order not found');

        // Items only — the internal buyer (creator) is not vendor-facing.
        return response()->json($purchaseOrder->load(['items']));
    }

    /* ── Invoices (read-only) ────────────────────────────────────────────── */

    public function invoices(Request $request)
    {
        $vendor = $this->portalVendor($request);

        // Only approved invoices — a Draft (unapproved) bill is internal.
        $invoices = PurchaseInvoice::forTenant($vendor->tenant_id)
            ->where('vendor_id', $vendor->id)
            ->where('status', '!=', InvStatus::DRAFT)
            ->latest()
            ->get(['id', 'invoice_number', 'title', 'status', 'total', 'amount_paid', 'amount_credited', 'balance', 'currency', 'invoice_date', 'due_date']);

        return response()->json($invoices);
    }

    public function invoice(Request $request, PurchaseInvoice $purchaseInvoice)
    {
        $this->assertOwned($request, $purchaseInvoice, 'Invoice');
        abort_if($purchaseInvoice->status === InvStatus::DRAFT, 404, 'Invoice not found');

        return response()->json($purchaseInvoice->load([
            'items',
            'payments:id,purchase_invoice_id,amount,payment_date,payment_mode,reference',
            'creditApplications.debitNote:id,debit_number',
        ]));
    }

    /* ── Private helpers ─────────────────────────────────────────────────── */

    /**
     * 404 if the TpvWorker doesn't belong to the portal caller's vendor.
     * Deliberate 404 (not 403) — existence-hiding for cross-vendor isolation.
     *
     * Nullable so a sub-resource can hand over its owning worker directly: a PPE
     * issue whose worker has gone missing is "not found", not a 500.
     */
    private function assertWorkerOwned(Request $request, ?TpvWorker $worker): void
    {
        $this->assertOwned($request, $worker, 'Worker');
    }
}
