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
use App\Models\Purchase\PurchaseDocumentVersion;
use App\Models\Purchase\PurchaseInvoice;
use App\Models\Purchase\PurchaseInvoicePayment;
use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseOnboarding;
use App\Models\Purchase\PurchaseOrder;
use App\Models\Purchase\PurchaseQuotation;
use App\Models\Purchase\PurchaseVendor;
use App\Models\User;
use App\Services\Purchase\PurchaseDocumentService;
use App\Services\Purchase\PurchaseDocumentVersionService;
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
        private \App\Services\Purchase\PurchaseVendorNotificationService $notifications,
    ) {
    }

    /* ── In-app (bell) notifications ─────────────────────────────────────────
     * A Purchase vendor is its OWN Authenticatable (not a User), so its bell
     * lives in the Purchase-owned purchase_vendor_notifications store, scoped to
     * the caller resolved from the token. Modules drop rows for the vendor; here
     * the vendor reads its own.
     */

    public function notifications(Request $request)
    {
        $vendor = $this->purchaseVendor($request);

        return response()->json([
            'items'        => $this->notifications->listFor((int) $vendor->id, (int) $vendor->tenant_id),
            'unread_count' => $this->notifications->unreadCount((int) $vendor->id, (int) $vendor->tenant_id),
        ]);
    }

    public function markNotificationRead(Request $request, int $id)
    {
        $vendor = $this->purchaseVendor($request);
        $this->notifications->markRead($id, (int) $vendor->id, (int) $vendor->tenant_id);

        return response()->json(['status' => 'success']);
    }

    public function markAllNotificationsRead(Request $request)
    {
        $vendor = $this->purchaseVendor($request);
        $marked = $this->notifications->markAllRead((int) $vendor->id, (int) $vendor->tenant_id);

        return response()->json(['status' => 'success', 'marked' => $marked]);
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

    /**
     * Knowledge Base — the tenant's published articles (read-only). KB is
     * tenant-global; scoped here to the caller vendor's tenant. Mirrors the TPV
     * portal KB so both portals stay at parity.
     */
    public function kbArticles(Request $request)
    {
        $vendor = $this->purchaseVendor($request);

        $articles = \App\Models\Helpdesk\KbArticle::where('tenant_id', $vendor->tenant_id)
            ->published()
            ->with('category:id,name')
            ->latest('published_at')
            ->limit((int) $request->integer('limit', 50))
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

    public function kbArticle(Request $request, string $slug)
    {
        $vendor = $this->purchaseVendor($request);

        $article = \App\Models\Helpdesk\KbArticle::where('tenant_id', $vendor->tenant_id)
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

        $profile = $request->validated()['profile'] ?? [];

        // A draft can legitimately sift down to nothing — everything the vendor
        // had touched so far was half-typed. Writing an empty merge would only
        // add an audit row saying a profile was saved when none was.
        $saved = $profile === []
            ? $onboarding->fresh()
            : $this->onboardingService->saveProfile($onboarding, $profile, $request->user());

        // A draft keeps every field that stands on its own; anything half-finished
        // is set aside rather than failing the save, and is named here so the
        // wizard can say which box still needs work. Merged onto the model so the
        // response shape every caller already reads is unchanged.
        return response()->json(array_merge($saved->toArray(), [
            'skipped' => $request->skippedFields(),
        ]));
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
    /**
     * The vendor's own work-start letter — proof they are cleared to start.
     *
     * The letter has existed on the Purchase ADMIN side all along, and TPV has
     * offered it in the portal since the portal existed. Purchase simply never
     * got the portal route, so the one party the letter is actually for — the
     * vendor — had no way to reach their own copy.
     */
    public function workStartLetter(Request $request, PurchaseOnboarding $onboarding,
        \App\Services\Purchase\PurchaseWorkStartLetterService $letters)
    {
        $this->assertOwnedByVendor($request, $onboarding, 'Onboarding');

        return $letters->stream($onboarding);
    }

    /**
     * The minutes as data, resolved exactly as the PDF resolves them.
     *
     * Same resolver as onboardingKickoffPdf below — see the TPV twin for why
     * two resolvers is the bug this avoids.
     */
    public function onboardingKickoffData(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertOwnedByVendor($request, $onboarding, 'Onboarding');

        $meeting = $this->onboardingService->resolveKickoffMeeting($onboarding);
        if (! $meeting) {
            return response()->json(['meeting' => null]);
        }

        return response()->json(\App\Support\Shared\VendorMomView::for($meeting, (bool) $meeting->mom_path));
    }

    public function onboardingKickoffPdf(Request $request, PurchaseOnboarding $onboarding)
    {
        $this->assertOwnedByVendor($request, $onboarding, 'Onboarding');

        $meeting = $this->onboardingService->resolveKickoffMeeting($onboarding);
        abort_unless($meeting, 404, 'Kickoff MOM not available yet.');

        /*
         * The document as it stands. Deliberately NOT generated on demand here.
         *
         * This used to try, and could not: generateMom() takes a User and the
         * caller on this route is a PurchaseVendor, so every attempt raised a
         * TypeError that the catch below turned into "not available yet". The
         * vendor was told the minutes did not exist while the real reason was a
         * type mismatch nobody could see.
         *
         * Removing it is also the right behaviour. Issuing minutes is the
         * procurement team's act — a vendor pressing View must not mint the
         * document they are being asked to accept.
         */
        $file = $this->kickoffService->currentMomFile($meeting);
        abort_unless(
            $file && \App\Support\Purchase\PurchaseMomApprovalStatus::isDistributable($meeting->mom_status),
            404,
            'The minutes for this meeting have not been issued yet.'
        );

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

    /**
     * Remove a document the vendor uploaded by mistake.
     *
     * The vendor owns what it has not yet had approved: a wrong scan sat there
     * with no way to take it back, because delete existed only on the admin
     * route. The service refuses an approved document, and assertOwnedByVendor
     * refuses anybody else's, so the vendor can only ever undo its own pending
     * work.
     */
    public function deleteDocument(Request $request, PurchaseDocument $document)
    {
        $this->assertOwnedByVendor($request, $document, 'Document');

        $this->documentService->destroy($document);

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * The document's own version history.
     *
     * Every replacement archives the file it displaced, and the vendor is the
     * one who replaced it - so the vendor is exactly who needs to see what was
     * sent before. The admin surface has had this since Phase 3; the portal
     * showed a History button wired to a stub that always answered "empty".
     */
    public function documentVersions(Request $request, PurchaseDocument $document)
    {
        $this->assertOwnedByVendor($request, $document, 'Document');

        return response()->json($document->versions()->orderByDesc('version_no')->get());
    }

    public function downloadDocumentVersion(Request $request, PurchaseDocument $document, PurchaseDocumentVersion $version)
    {
        $this->assertOwnedByVendor($request, $document, 'Document');
        abort_unless((int) $version->purchase_document_id === (int) $document->id, 404, 'Version not found');

        // The portal authenticates as a PurchaseVendor, and resolveDownload's
        // audit actor is typed `?User` - handing it the vendor would be the same
        // TypeError that once broke portal upload. The download is still audited;
        // it simply records no User, which is the truth here.
        $actor = $request->user() instanceof User ? $request->user() : null;
        $file  = app(PurchaseDocumentVersionService::class)->resolveDownload($version, $actor);

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

        // MoM is the vendor's to see only once approved+distributed (parity with
        // the shared engine — acknowledgement removed).
        $momAvailable = $meeting->currentMom()->exists()
            && \App\Support\Purchase\PurchaseMomApprovalStatus::isDistributable($meeting->mom_status);

        return response()->json(['meeting' => [
            'id'              => $meeting->id,
            'title'           => $meeting->title,
            'status'          => $meeting->status,
            'status_label'    => $meeting->status_label,
            'scheduled_at'    => optional($meeting->scheduled_at)->toIso8601String(),
            'mode'            => $meeting->mode,
            'location'        => $meeting->location,
            // The join link is not here until the vendor marks attendance on
            // the meeting — see MeetingAttendanceGate, which also keeps the old
            // rule that a link is only offered while the meeting is still going
            // to happen (a CANCELLED meeting is not "expired", and used to keep
            // handing out a working link for a meeting nobody would attend).
            // Spread rather than named one by one, so this payload gains
            // attendance_marked and can_mark_attendance with the same shape the
            // two governance lists use.
            ...app(\App\Services\Shared\MeetingAttendanceGate::class)
                ->stateFor($meeting, $this->purchaseVendor($request)),
            'mom_available'   => $momAvailable,
            // This payload is hand-built, so the model's appended timing does
            // NOT ride along — it has to be named here. Without it the dashboard
            // read is_expired as undefined, and `!undefined` is true, so the
            // join popup offered a meeting that had already ended.
            'ends_at'         => optional($meeting->ends_at)->toIso8601String(),
            'duration_minutes' => $meeting->duration_minutes,
            'timing_state'    => $meeting->timing_state,
            'timing_label'    => $meeting->timing_label,
            'is_expired'      => $meeting->is_expired,
            'is_live'         => $meeting->is_live,
            // The record of the call itself. The dashboard card re-derives the
            // state from the clock between fetches, and without these it would
            // go on deriving "In progress" from the booked hour for a meeting
            // everyone had already left.
            'actual_start_at' => optional($meeting->actual_start_at)->toIso8601String(),
            'actual_end_at'   => optional($meeting->actual_end_at)->toIso8601String(),
            'held_minutes'    => $meeting->held_minutes,
            // The last heartbeat, so the card can tell a call that has gone
            // quiet from one still running — same rule as the server's.
            'presence_seen_at' => optional($meeting->presence_seen_at)->toIso8601String(),
        ]]);
    }

    /**
     * The one kickoff meeting worth showing the caller's own vendor.
     *
     * Two things were wrong with taking `latest()`:
     *
     *  - it ordered by CREATION, so a meeting booked later for an earlier date
     *    won over the one actually coming up;
     *  - it included DRAFTS, which are deliberately invisible to a vendor
     *    everywhere else — this endpoint showed them one anyway.
     *
     * What a vendor wants is the meeting that needs them: the one happening now,
     * else the next one due. Only when neither exists does the most recent past
     * meeting stand in, so the tab still has something to show.
     */
    /**
     * Accept the kickoff, from the standalone Kickoff tab.
     *
     * That tab resolves the meeting from the token and shows no ids, so its
     * Accept button had nowhere to post: the client called
     * /portal/purchase/kickoff/accept and no route served it. The button
     * existed, the client method existed, and every press 404'd.
     *
     * Resolves the vendor's own onboarding the way the rest of this controller
     * does, then hands off to the SAME service the id-carrying onboarding route
     * uses. Two ways to accept a kickoff that behave differently is a defect
     * this module has already been bitten by once -- see ownKickoff() below.
     */
    public function acceptKickoff(Request $request)
    {
        $vendor = $this->purchaseVendor($request);

        $onboarding = PurchaseOnboarding::forTenant($vendor->tenant_id)
            ->where('purchase_vendor_id', $vendor->id)
            ->latest('id')
            ->first();

        abort_unless($onboarding, 404, 'No onboarding found for this vendor.');

        $ua = \App\Support\UserAgentInfo::parse($request->userAgent());

        return response()->json($this->onboardingService->acknowledgeKickoff(
            $onboarding, $request->user(), [
                'ip' => $request->ip(), 'browser' => $ua['browser'], 'device' => $ua['device'],
            ],
        ));
    }

    private function ownKickoff(Request $request): ?PurchaseKickoffMeeting
    {
        $vendor = $this->purchaseVendor($request);

        /*
         * The onboarding's answer, when there is an onboarding.
         *
         * This method and PurchaseOnboardingService::resolveKickoffMeeting both
         * decided "the vendor's kickoff meeting", by different rules — and they
         * disagreed. Step 1 drew its card from this one and fetched its document
         * through the other, so the screen showed the minutes of a completed
         * meeting while every download asked for a cancelled one and came back
         * "not available yet". Two answers to one question is the defect; the
         * onboarding's is the one Step 1 is about.
         */
        $onboarding = PurchaseOnboarding::forTenant($vendor->tenant_id)
            ->where('purchase_vendor_id', $vendor->id)
            ->latest('id')->first();

        if ($onboarding) {
            $resolved = app(PurchaseOnboardingService::class)->resolveKickoffMeeting($onboarding);
            if ($resolved) {
                return $resolved;
            }
        }

        // No onboarding (or no kickoff on it): fall back to the vendor's own
        // meetings — what is happening now, then what is next, then the last one.
        $meetings = PurchaseKickoffMeeting::forTenant($vendor->tenant_id)
            ->where('purchase_vendor_id', $vendor->id)
            ->where('status', '!=', \App\Support\Purchase\PurchaseKickoffStatus::DRAFT)
            ->orderBy('scheduled_at')
            ->get();

        return $meetings->firstWhere('is_live', true)
            ?? $meetings->firstWhere('timing_state', 'upcoming')
            ?? $meetings->last();
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
