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
    ) {
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
                    $kickoff = KickoffMeeting::forTenant($vendor->tenant_id)
                        ->where(function ($q) use ($vendor) {
                            $q->where('kickoffable_type', Vendor::class)
                              ->orWhere('kickoffable_type', 'vendor');
                        })
                        ->where('kickoffable_id', $vendor->id)
                        ->latest()
                        ->first();

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
     */
    private function assertWorkerOwned(Request $request, TpvWorker $worker): void
    {
        $this->assertOwned($request, $worker, 'Worker');
    }
}
