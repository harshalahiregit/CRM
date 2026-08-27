<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\StoreVendorNoteRequest;
use App\Http\Requests\Vendor\StoreVendorReminderRequest;
use App\Http\Requests\Vendor\StoreVendorRequest;
use App\Http\Requests\Vendor\UpdateVendorRequest;
use App\Models\Purchase\PurchaseDebitNote;
use App\Models\Purchase\PurchaseInvoice;
use App\Models\Purchase\PurchaseInvoicePayment;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Sales\Reminder;
use App\Models\Customer\Client;
use App\Models\Shared\Attachment;
use App\Models\Shared\AttachmentFolder;
use App\Models\Shared\Note;
use App\Models\Tpv\TpvContact;
use App\Models\Tpv\TpvWorker;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorDocument;
use App\Support\Vendor\VendorStatus;
use App\Services\Purchase\PurchaseVendorService;
use App\Services\Sales\ReminderService;
use App\Services\Shared\AttachmentService;
use App\Services\Shared\NoteService;
use App\Services\Vendor\VendorService;
use App\Support\Task\VendorTaskLink;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    public function __construct(
        private VendorService $vendorService,
        private ReminderService $reminders,
        private NoteService $notes,
        private AttachmentService $attachments,
    ) {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->vendorService->list(
                $request->user()->tenant_id,
                // per_page/page/sort_* are additive: omit them and the response
                // is the same bare array every existing caller already reads.
                $request->only([
                    'status', 'vendor_type', 'category', 'engagement', 'search',
                    'per_page', 'sort_column', 'sort_direction',
                ])
            )
        );
    }

    public function store(StoreVendorRequest $request)
    {
        $vendor = $this->vendorService->create($request->validated(), $request->user()->tenant_id);

        return response()->json($vendor, 201);
    }

    /* ── Reminders ─────────────────────────────────────────────────────
     *
     * Follow-ups against a vendor, on the SHARED polymorphic `reminders` table —
     * the same engine Sales uses, addressed by model class rather than by the
     * Sales subject key. Deliberately NOT mounted on /api/sales/reminders: that
     * group carries no role gate, so putting vendor data behind it would expose
     * it to every authenticated login. These sit in the TPV admin group instead.
     */

    public function reminders(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json(
            $this->reminders->listForSubject(Vendor::class, $vendor->id, (int) $request->user()->tenant_id)
        );
    }

    public function storeReminder(StoreVendorReminderRequest $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json(
            $this->reminders->createForSubject(
                Vendor::class, $vendor->id, $request->validated(),
                (int) $request->user()->tenant_id, (int) $request->user()->id,
            ),
            201,
        );
    }

    public function completeReminder(Request $request, Vendor $vendor, Reminder $reminder)
    {
        $this->assertReminderBelongs($request, $vendor, $reminder);

        $data = $request->validate([
            'outcome'        => 'nullable|string',
            'next_follow_up' => 'nullable|date',
        ]);

        return response()->json(
            $this->reminders->complete($reminder, $data, (int) $request->user()->tenant_id, (int) $request->user()->id)
        );
    }

    public function destroyReminder(Request $request, Vendor $vendor, Reminder $reminder)
    {
        $this->assertReminderBelongs($request, $vendor, $reminder);

        $this->reminders->delete($reminder, (int) $request->user()->tenant_id);

        return response()->json(['message' => 'Reminder deleted']);
    }

    /**
     * The reminder must be this vendor's, not merely this tenant's — otherwise a
     * vendor id in the URL would be decoration and any reminder id would do.
     */
    private function assertReminderBelongs(Request $request, Vendor $vendor, Reminder $reminder): void
    {
        $this->assertTenant($request, $vendor);

        abort_unless(
            $reminder->remindable_type === Vendor::class && (int) $reminder->remindable_id === (int) $vendor->id,
            404,
            'Reminder not found',
        );
    }

    /* ── Commercial (Purchase counterpart) ─────────────────────────────
     *
     * Commercial documents key to purchase_vendors.purchase_vendor_id and stay
     * entirely Purchase-owned. When the same company exists in both modules the
     * TPV screen can SHOW them, through an explicit link an admin sets — never a
     * name match, because putting the wrong company's invoices on a vendor page
     * is worse than showing none.
     *
     * Orders, invoices, debit notes, contracts and quotations are read straight
     * from the Purchase list endpoints (all already filter by purchase_vendor_id).
     * Only Payments and Statement need anything here: neither has a vendor-level
     * endpoint of its own.
     */

    /** Candidate Purchase records for the link picker, best match first. */
    public function purchaseMatches(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $rows = PurchaseVendor::forTenant($vendor->tenant_id)
            ->select('id', 'company_name', 'purchase_vendor_code', 'email', 'status')
            ->orderBy('company_name')
            ->get();

        // Exact email, then exact name — surfaced as a hint for the human, who
        // still has to choose. Nothing is auto-linked.
        $suggested = $rows->first(fn ($p) => $vendor->email && strcasecmp((string) $p->email, (string) $vendor->email) === 0)
            ?? $rows->first(fn ($p) => strcasecmp((string) $p->company_name, (string) $vendor->company_name) === 0);

        return response()->json([
            'linked_id'    => $vendor->purchase_vendor_id,
            'suggested_id' => $suggested?->id,
            'candidates'   => $rows,
        ]);
    }

    /**
     * Register this company in Purchase and link it, in one step.
     *
     * Commercial work cannot begin without a purchase_vendors row — an RFQ's
     * recipient list, a PO and an invoice all key to one. Retyping the company
     * into the Purchase module is the only alternative, and it invites a typo
     * that quietly produces two records for one supplier.
     *
     * Purchase's OWN service does the creating, so the code sequence, registration
     * type, audit entry and Draft status are exactly what /purchase/vendors would
     * have produced. Nothing about Purchase's ownership changes: this writes one
     * ordinary Purchase record and stores its id on the TPV side.
     */
    public function createPurchaseRecord(Request $request, Vendor $vendor, PurchaseVendorService $purchaseVendors)
    {
        $this->assertTenant($request, $vendor);

        abort_if($vendor->purchase_vendor_id !== null, 422, 'This vendor is already linked to a Purchase record.');

        // A second record for a company already in Purchase is the exact mistake
        // this is meant to prevent, so refuse and let them link instead.
        $clash = PurchaseVendor::forTenant($vendor->tenant_id)
            ->where(fn ($q) => $q
                ->when($vendor->email, fn ($w) => $w->orWhereRaw('LOWER(email) = ?', [strtolower($vendor->email)]))
                ->orWhereRaw('LOWER(company_name) = ?', [strtolower((string) $vendor->company_name)]))
            ->first();

        abort_if(
            (bool) $clash,
            422,
            "A Purchase record already exists for this company ({$clash?->company_name}). Link it instead of creating a second one.",
        );

        $created = $purchaseVendors->create(array_filter([
            'company_name'        => $vendor->company_name,
            'legal_name'          => $vendor->legal_name,
            'email'               => $vendor->email,
            'phone'               => $vendor->phone,
            'website'             => $vendor->website,
            'category'            => $vendor->category,
            'gst_number'          => $vendor->gst_number,
            'pan_number'          => $vendor->pan_number,
            'address'             => $vendor->address,
            'city'                => $vendor->city,
            'state'               => $vendor->state,
            'country'             => $vendor->country,
            'pincode'             => $vendor->pincode,
            'vendor_type'         => $vendor->vendor_type,
        ], fn ($v) => $v !== null && $v !== ''), $request->user());

        $vendor->update(['purchase_vendor_id' => $created->id]);
        $vendor->recordAudit('Purchase Record Created', $request->user(), null, [
            'purchase_vendor_id' => $created->id, 'purchase_vendor_code' => $created->purchase_vendor_code,
        ]);

        return response()->json($created, 201);
    }

    /** Set or clear the Purchase link. Passing null unlinks. */
    public function linkPurchaseVendor(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate([
            'purchase_vendor_id' => 'nullable|integer|min:1',
        ]);

        $id = $data['purchase_vendor_id'] ?? null;

        if ($id !== null) {
            // Tenant-scoped: an id from another workspace must not resolve.
            abort_unless(
                PurchaseVendor::forTenant($vendor->tenant_id)->whereKey($id)->exists(),
                404,
                'Purchase vendor not found',
            );
        }

        $vendor->update(['purchase_vendor_id' => $id]);
        $vendor->recordAudit($id ? 'Purchase Record Linked' : 'Purchase Record Unlinked', $request->user(), null, [
            'purchase_vendor_id' => $id,
        ]);

        return response()->json($vendor->fresh()->load('purchaseVendor:id,company_name,purchase_vendor_code'));
    }

    /** Payments made against this vendor's purchase invoices. */
    public function purchasePayments(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        if (! $vendor->purchase_vendor_id) {
            return response()->json([]);
        }

        return response()->json(
            PurchaseInvoicePayment::forTenant($vendor->tenant_id)
                ->whereIn('purchase_invoice_id', PurchaseInvoice::forTenant($vendor->tenant_id)
                    ->where('purchase_vendor_id', $vendor->purchase_vendor_id)->select('id'))
                ->with(['invoice:id,invoice_number,invoice_date', 'creator:id,name'])
                ->orderByDesc('payment_date')->orderByDesc('id')
                ->get()
        );
    }

    /**
     * Running account statement: every invoice as a debit, every payment and
     * debit note as a credit, oldest first with a running balance.
     */
    public function purchaseStatement(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        if (! $vendor->purchase_vendor_id) {
            return response()->json(['lines' => [], 'closing_balance' => 0]);
        }

        $tid = (int) $vendor->tenant_id;
        $pv  = (int) $vendor->purchase_vendor_id;

        // `total` is the document value on both purchase_invoices and
        // purchase_debit_notes — there is no grand_total column on either.
        $invoices = PurchaseInvoice::forTenant($tid)->where('purchase_vendor_id', $pv)
            ->get(['id', 'invoice_number', 'invoice_date', 'total'])
            ->map(fn ($i) => [
                'date' => optional($i->invoice_date)->toDateString(),
                'type' => 'Invoice', 'reference' => $i->invoice_number,
                'debit' => (float) $i->total, 'credit' => 0.0,
            ]);

        $payments = PurchaseInvoicePayment::forTenant($tid)
            ->whereIn('purchase_invoice_id', PurchaseInvoice::forTenant($tid)->where('purchase_vendor_id', $pv)->select('id'))
            ->with('invoice:id,invoice_number')
            ->get()
            ->map(fn ($p) => [
                'date' => optional($p->payment_date)->toDateString(),
                'type' => 'Payment', 'reference' => $p->reference ?: $p->invoice?->invoice_number,
                'debit' => 0.0, 'credit' => (float) $p->amount,
            ]);

        $debitNotes = PurchaseDebitNote::forTenant($tid)->where('purchase_vendor_id', $pv)
            ->get(['id', 'debit_number', 'debit_date', 'total'])
            ->map(fn ($d) => [
                'date' => optional($d->debit_date)->toDateString(),
                'type' => 'Debit Note', 'reference' => $d->debit_number,
                'debit' => 0.0, 'credit' => (float) $d->total,
            ]);

        $balance = 0.0;
        $lines = $invoices->concat($payments)->concat($debitNotes)
            ->sortBy(fn ($l) => $l['date'] ?? '')
            ->values()
            ->map(function ($l) use (&$balance) {
                $balance += $l['debit'] - $l['credit'];

                return [...$l, 'balance' => round($balance, 2)];
            });

        return response()->json([
            'lines'           => $lines,
            'closing_balance' => round($balance, 2),
        ]);
    }

    /* ── Attachments (folder-tree file area) ───────────────────────────
     *
     * Free-form files, distinct from the statutory `documents` checklist. Google
     * Drive and OneDrive are import sources handled in the browser: the picker
     * downloads the file and posts it to the same upload endpoint, so the bytes
     * are always ours and there is one storage path.
     *
     * Every folder and file id is resolved against THIS vendor — an id belonging
     * to another vendor is a 404, not a place to file something.
     */

    public function attachments(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json($this->attachments->browse(
            Vendor::class, $vendor->id, (int) $vendor->tenant_id,
            $request->filled('folder_id') ? (int) $request->query('folder_id') : null,
        ));
    }

    public function storeAttachmentFolder(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate([
            'name'      => 'required|string|max:200',
            'parent_id' => 'nullable|integer|min:1',
        ]);

        return response()->json(
            $this->attachments->createFolder(Vendor::class, $vendor->id, (int) $vendor->tenant_id, $data, $request->user()),
            201,
        );
    }

    public function updateAttachmentFolder(Request $request, Vendor $vendor, AttachmentFolder $folder)
    {
        $this->assertAttachmentFolderBelongs($request, $vendor, $folder);

        $data = $request->validate(['name' => 'required|string|max:200']);

        return response()->json($this->attachments->renameFolder($folder, $data['name']));
    }

    public function destroyAttachmentFolder(Request $request, Vendor $vendor, AttachmentFolder $folder)
    {
        $this->assertAttachmentFolderBelongs($request, $vendor, $folder);

        $this->attachments->deleteFolder($folder);

        return response()->json(['message' => 'Folder deleted']);
    }

    public function storeAttachment(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate([
            'file'       => 'required|file|max:51200',           // 50 MB
            'folder_id'  => 'nullable|integer|min:1',
            'name'       => 'nullable|string|max:200',
            'source'     => 'nullable|in:upload,google_drive,onedrive',
            'source_ref' => 'nullable|string|max:255',
        ]);

        return response()->json(
            $this->attachments->upload(
                Vendor::class, $vendor->id, (int) $vendor->tenant_id,
                $request->file('file'),
                $data['folder_id'] ?? null,
                $data,
                $request->user(),
            ),
            201,
        );
    }

    public function updateAttachment(Request $request, Vendor $vendor, Attachment $attachment)
    {
        $this->assertAttachmentBelongs($request, $vendor, $attachment);

        $data = $request->validate([
            'name'      => 'sometimes|required|string|max:200',
            'folder_id' => 'sometimes|nullable|integer|min:1',
        ]);

        $file = $attachment;
        if (array_key_exists('folder_id', $data)) {
            $file = $this->attachments->moveFile($file, $data['folder_id'] ?: null);
        }
        if (isset($data['name'])) {
            $file = $this->attachments->renameFile($file, $data['name']);
        }

        return response()->json($file);
    }

    public function downloadAttachment(Request $request, Vendor $vendor, Attachment $attachment)
    {
        $this->assertAttachmentBelongs($request, $vendor, $attachment);

        $f = $this->attachments->download($attachment);

        return response()->download($f['path'], $f['filename'], ['Content-Type' => $f['mime']]);
    }

    public function destroyAttachment(Request $request, Vendor $vendor, Attachment $attachment)
    {
        $this->assertAttachmentBelongs($request, $vendor, $attachment);

        $this->attachments->deleteFile($attachment);

        return response()->json(['message' => 'File deleted']);
    }

    private function assertAttachmentFolderBelongs(Request $request, Vendor $vendor, AttachmentFolder $folder): void
    {
        $this->assertTenant($request, $vendor);

        abort_unless(
            $folder->attachable_type === Vendor::class && (int) $folder->attachable_id === (int) $vendor->id,
            404,
            'Folder not found',
        );
    }

    private function assertAttachmentBelongs(Request $request, Vendor $vendor, Attachment $attachment): void
    {
        $this->assertTenant($request, $vendor);

        abort_unless(
            $attachment->attachable_type === Vendor::class && (int) $attachment->attachable_id === (int) $vendor->id,
            404,
            'File not found',
        );
    }

    /* ── Notes ─────────────────────────────────────────────────────────
     *
     * The shared polymorphic `notes` table. Same shape as reminders above: the
     * subject is the vendor in the route, so a note id from another record is a
     * 404 rather than something the payload can retarget.
     */

    public function notes(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json(
            $this->notes->listForSubject(Vendor::class, $vendor->id, (int) $request->user()->tenant_id)
        );
    }

    /* ── Overview dashboard ─────────────────────────────────────────────────
     *
     * Live per-vendor summary counts for the workspace Overview tab. Each count
     * matches exactly what its own tab lists, so the numbers never disagree.
     */
    /** The five mandated onboarding gates (Doc 2/4) computed for this vendor. */
    public function gates(Request $request, Vendor $vendor, \App\Services\Tpv\GateStatusService $gates)
    {
        $this->assertTenant($request, $vendor);

        return response()->json($gates->compute($vendor));
    }

    /** Live VRS scorecard (Doc 5) computed from incidents/strikes/docs/workforce. */
    public function scorecard(Request $request, Vendor $vendor, \App\Services\Vendor\VendorScorecardService $vrs)
    {
        $this->assertTenant($request, $vendor);

        return response()->json([
            'live'    => $vrs->compute($vendor),
            'history' => $vrs->history($vendor),
        ]);
    }

    public function overview(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);
        $tenantId = (int) $vendor->tenant_id;

        return response()->json([
            'status'       => $vendor->status,
            'status_label' => $vendor->status_label,
            'is_active'    => $vendor->status === VendorStatus::ACTIVE,
            'vendor_code'  => $vendor->vendor_code,
            'company_name' => $vendor->company_name,
            'counts' => [
                'customers'   => $vendor->customers()->count(),
                'contacts'    => TpvContact::where('vendor_id', $vendor->id)->count(),
                'workers'     => TpvWorker::where('vendor_id', $vendor->id)->count(),
                'notes'       => count($this->notes->listForSubject(Vendor::class, $vendor->id, $tenantId)),
                'attachments' => Attachment::where('attachable_type', Vendor::class)->where('attachable_id', $vendor->id)->count(),
                'documents'   => VendorDocument::where('vendor_id', $vendor->id)->count(),
            ],
        ]);
    }

    /* ── Customers directly linked to this vendor (clients.vendor_id) ─────── */

    public function customers(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json(
            $vendor->customers()
                ->orderByDesc('id')
                ->get(['id', 'company', 'phone', 'website', 'gst_number', 'city', 'state', 'country', 'active', 'created_at'])
        );
    }

    public function storeCustomer(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate([
            'company'    => 'required|string|max:191',
            'phone'      => 'nullable|string|max:40',
            'website'    => 'nullable|string|max:191',
            'gst_number' => 'nullable|string|max:40',
            'address'    => 'nullable|string|max:255',
            'city'       => 'nullable|string|max:120',
            'state'      => 'nullable|string|max:120',
            'country'    => 'nullable|string|max:120',
        ]);

        // Reuse the Customer module's Client model. Set tenant_id explicitly from
        // the caller (same as every other vendor-scoped create here) rather than
        // relying on the BelongsToTenant auto-stamp, so it never depends on ambient
        // auth() state.
        $client = Client::create(array_merge($data, [
            'tenant_id' => (int) $request->user()->tenant_id,
            'vendor_id' => $vendor->id,
            'added_by'  => (int) $request->user()->id,
            'active'    => true,
        ]));

        return response()->json($client, 201);
    }

    /**
     * Search existing (registered) customers that can be linked to this vendor —
     * tenant-scoped clients not already tied to a vendor, matching q on company /
     * phone / GST. Lets the admin add an existing customer instead of re-creating.
     */
    public function searchCustomers(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate(['q' => 'nullable|string|max:120']);
        $q = trim((string) ($data['q'] ?? ''));

        $rows = Client::query()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            // Only unlinked customers, or ones already on THIS vendor (idempotent).
            ->where(function ($w) use ($vendor) {
                $w->whereNull('vendor_id')->orWhere('vendor_id', $vendor->id);
            })
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('company', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%")
                        ->orWhere('gst_number', 'like', "%{$q}%");
                });
            })
            ->orderBy('company')
            ->limit(20)
            ->get(['id', 'company', 'phone', 'gst_number', 'city', 'state', 'country', 'vendor_id']);

        return response()->json($rows);
    }

    /**
     * Link an existing customer to this vendor (set clients.vendor_id). Idempotent
     * if already linked to this vendor; refuses to steal a customer already tied to
     * a different vendor.
     */
    public function linkCustomer(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate(['client_id' => 'required|integer']);

        $client = Client::query()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->find($data['client_id']);

        abort_unless($client, 404, 'Customer not found.');

        if ($client->vendor_id && (int) $client->vendor_id !== (int) $vendor->id) {
            abort(422, 'That customer is already linked to another vendor.');
        }

        if ((int) $client->vendor_id !== (int) $vendor->id) {
            $client->update(['vendor_id' => $vendor->id]);
        }

        return response()->json($client->fresh() ?? $client, 200);
    }

    public function storeNote(StoreVendorNoteRequest $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json(
            $this->notes->createForSubject(
                Vendor::class, $vendor->id, $request->validated(),
                (int) $request->user()->tenant_id, (int) $request->user()->id,
            ),
            201,
        );
    }

    public function updateNote(StoreVendorNoteRequest $request, Vendor $vendor, Note $note)
    {
        $this->assertNoteBelongs($request, $vendor, $note);

        return response()->json(
            $this->notes->update($note, $request->validated(), (int) $request->user()->tenant_id)
        );
    }

    public function destroyNote(Request $request, Vendor $vendor, Note $note)
    {
        $this->assertNoteBelongs($request, $vendor, $note);

        $this->notes->delete($note, (int) $request->user()->tenant_id);

        return response()->json(['message' => 'Note deleted']);
    }

    private function assertNoteBelongs(Request $request, Vendor $vendor, Note $note): void
    {
        $this->assertTenant($request, $vendor);

        abort_unless(
            $note->notable_type === Vendor::class && (int) $note->notable_id === (int) $vendor->id,
            404,
            'Note not found',
        );
    }

    /**
     * Tasks linked to this TPV vendor (tasks.rel_type = 'tpv_vendor').
     *
     * Read-only. Tasks are created and edited in the Task module; this endpoint
     * only surfaces them on the vendor's Tasks tab.
     */
    public function tasks(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);
        $tenantId = (int) $request->user()->tenant_id;

        // A TPV signs in as a User, so work delegated to that login belongs on this
        // tab too -- not only tasks raised against the vendor as an organisation.
        $portalUserId = $vendor->user_id
            ?: ($vendor->email ? \App\Models\User::where('tenant_id', $tenantId)
                ->where('email', $vendor->email)->value('id') : null);

        return response()->json([
            'summary' => VendorTaskLink::summary(VendorTaskLink::TPV, $vendor->id, $tenantId, $portalUserId),
            'tasks'   => VendorTaskLink::forVendor(VendorTaskLink::TPV, $vendor->id, $tenantId, $portalUserId),
        ]);
    }

    public function show(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $relations = ['contacts', 'documents', 'tpvOnboarding', 'accountManager:id,name', 'user:id,name,email,status'];
        // Purchase vendors carry a separate onboarding record; load it so the shared
        // detail screen can show the onboarding badge. Pure-TPV payloads are unchanged.
        if ($vendor->hasEngagement('purchase')) {
            $relations[] = 'purchaseOnboarding';
        }

        $vendor->load($relations);
        // Vendor Detail dashboard: last activation e-mail, full notification
        // timeline and portal login stats. All read from existing stores.
        $vendor->setAttribute('last_notification', $this->vendorService->lastNotification($vendor));
        $vendor->setAttribute('notification_timeline', $this->vendorService->notificationTimeline($vendor));
        $vendor->setAttribute('login_stats', $this->vendorService->loginStats($vendor));

        return response()->json($vendor);
    }

    /** Resend the activation e-mail. Active vendors only; every send is logged. */
    public function resendActivation(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json($this->vendorService->resendActivationEmail($vendor, $request->user()));
    }

    public function update(Request $request, Vendor $vendor, UpdateVendorRequest $updateRequest)
    {
        $this->assertTenant($request, $vendor);

        return response()->json(
            $this->vendorService->update($vendor, $updateRequest->validated(), $request->user())
        );
    }

    /** Admin gate — makes the vendor transactable by Purchase and TPV. */
    public function approve(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json(
            $this->vendorService->approve($vendor, $request->user(), $data['remarks'] ?? null)
        );
    }

    public function updateStatus(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate([
            'status'  => 'required|string',
            'remarks' => 'nullable|string',
        ]);

        return response()->json(
            $this->vendorService->updateStatus($vendor, $data['status'], $request->user(), $data['remarks'] ?? null)
        );
    }

    /** Manually suspend a vendor for a compliance breach (mandatory reason). */
    public function suspend(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate(['reason' => 'required|string|max:500']);

        return response()->json(
            $this->vendorService->suspend($vendor, $data['reason'], $request->user(), false)
        );
    }

    /** Lift a suspension and return the vendor to Active. */
    public function reinstate(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json(
            $this->vendorService->reinstate($vendor, $request->user())
        );
    }

    /** Offboard a vendor — end the engagement (terminal). */
    public function offboard(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate(['reason' => 'required|string|max:500']);

        return response()->json(
            $this->vendorService->offboard($vendor, $data['reason'], $request->user())
        );
    }

    public function destroy(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $this->vendorService->destroy($vendor);

        return response()->json(['message' => 'Deleted']);
    }

    public function stats(Request $request)
    {
        return response()->json($this->vendorService->stats($request->user()->tenant_id));
    }

    /** Dashboard "Send Email" action — an ad-hoc message to the vendor. */
    public function sendEmail(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate([
            'subject' => 'required|string|max:200',
            'body'    => 'required|string|max:5000',
        ]);

        $result = $this->vendorService->sendEmail($vendor, $data['subject'], $data['body'], $request->user());

        return response()->json(['status' => 'success', 'result' => $result]);
    }

    /**
     * GET /api/vendors/{vendor}/login-link
     *
     * Mints a one-time set-password link and returns the pre-filled subject and
     * body for the Send Email dialog. It does NOT send anything — the admin sees
     * the draft, can edit it, and sends via the existing email endpoint. That
     * keeps one send path instead of two.
     */
    public function loginLink(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to issue portal logins');

        return response()->json($this->vendorService->buildLoginLink($vendor));
    }

    private function assertTenant(Request $request, Vendor $vendor): void
    {
        abort_unless((int) $vendor->tenant_id === (int) $request->user()->tenant_id, 404, 'Vendor not found');
    }
}
