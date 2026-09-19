<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\StorePurchaseVendorRequest;
use App\Http\Requests\Purchase\UpdatePurchaseVendorRequest;
use App\Http\Requests\Vendor\StoreVendorNoteRequest;
use App\Http\Requests\Vendor\StoreVendorReminderRequest;
use App\Models\Customer\Client;
use App\Models\Purchase\PurchaseContact;
use App\Models\Purchase\PurchaseDebitNote;
use App\Models\Purchase\PurchaseDocument;
use App\Models\Purchase\PurchaseInvoice;
use App\Models\Purchase\PurchaseInvoicePayment;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Sales\Appointment;
use App\Models\Sales\Reminder;
use App\Models\Shared\Attachment;
use App\Models\Shared\AttachmentFolder;
use App\Models\Shared\Note;
use App\Services\Purchase\PurchaseVendorService;
use App\Services\Sales\AppointmentService;
use App\Services\Sales\ReminderService;
use App\Services\Shared\AttachmentService;
use App\Models\Purchase\PurchaseVendorAward;
use App\Models\Purchase\PurchaseVendorReferral;
use App\Services\Purchase\PurchaseAccessService;
use App\Services\Shared\NoteService;
use App\Support\Purchase\PurchaseVendorStatus;
use App\Support\Task\VendorTaskLink;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Purchase Vendor master — the staff/admin surface over the Purchase-owned vendor
 * entity (PurchaseVendorService, purchase_vendors). Completely independent of the
 * shared VendorController and TPV. Every bound vendor is tenant-guarded (404).
 */
class PurchaseVendorController extends Controller
{
    public function __construct(
        private PurchaseVendorService $vendors,
        // The three SHARED polymorphic engines, pointed at PurchaseVendor. Not
        // Purchase-owned copies — one notes table, one reminders table, one file
        // store, addressed by model class.
        private NoteService $notes,
        private ReminderService $reminders,
        private AttachmentService $attachments,
    ) {
    }

    public function stats(Request $request)
    {
        return response()->json($this->vendors->stats($request->user()->tenant_id));
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->vendors->list($request->user()->tenant_id, $request->only(['status', 'category', 'vendor_type', 'search']))
        );
    }

    public function store(StorePurchaseVendorRequest $request)
    {
        return response()->json($this->vendors->create($request->validated(), $request->user()), 201);
    }

    /**
     * Tasks linked to this Purchase vendor (tasks.rel_type = 'purchase_vendor').
     *
     * Separate from the TPV endpoint on purpose -- the two vendor modules share no
     * table, no controller and no route, only the shape of the task link.
     */
    public function tasks(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);
        $tenantId = (int) $request->user()->tenant_id;

        // Most Purchase Vendors have no User at all (they authenticate as a
        // PurchaseVendor), so this is usually null and only the relation link applies.
        $portalUserId = $purchaseVendor->user_id;

        return response()->json([
            'summary' => VendorTaskLink::summary(VendorTaskLink::PURCHASE, $purchaseVendor->id, $tenantId, $portalUserId),
            'tasks'   => VendorTaskLink::forVendor(VendorTaskLink::PURCHASE, $purchaseVendor->id, $tenantId, $portalUserId),
        ]);
    }

    public function show(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        $vendor = $this->vendors->find($purchaseVendor->id, $request->user()->tenant_id);
        // Vendor Detail dashboard: last activation e-mail, full notification
        // timeline and portal login stats. All read from existing stores.
        $vendor->setAttribute('last_notification', $this->vendors->lastNotification($purchaseVendor));
        $vendor->setAttribute('notification_timeline', $this->vendors->notificationTimeline($purchaseVendor));
        $vendor->setAttribute('login_stats', $this->vendors->loginStats($purchaseVendor));

        return response()->json($vendor);
    }

    /* ── Overview dashboard ─────────────────────────────────────────────────
     *
     * Live per-vendor summary counts for the workspace Overview tab, the
     * Purchase-side mirror of VendorController::overview. Each count matches
     * exactly what its own tab lists, so the numbers never disagree.
     */
    public function overview(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);
        $tenantId = (int) $purchaseVendor->tenant_id;

        return response()->json([
            'status'       => $purchaseVendor->status,
            'status_label' => $purchaseVendor->status_label,
            'is_active'    => $purchaseVendor->status === PurchaseVendorStatus::ACTIVE,
            'vendor_code'  => $purchaseVendor->purchase_vendor_code,
            'company_name' => $purchaseVendor->company_name,
            'counts' => [
                'customers'   => $purchaseVendor->customers()->count(),
                'contacts'    => PurchaseContact::where('purchase_vendor_id', $purchaseVendor->id)->count(),
                'workers'     => PurchaseWorker::where('purchase_vendor_id', $purchaseVendor->id)->count(),
                'notes'       => count($this->notes->listForSubject(PurchaseVendor::class, $purchaseVendor->id, $tenantId)),
                'attachments' => Attachment::where('attachable_type', PurchaseVendor::class)->where('attachable_id', $purchaseVendor->id)->count(),
                'documents'   => PurchaseDocument::where('purchase_vendor_id', $purchaseVendor->id)->count(),
            ],
        ]);
    }

    /* ── Customers directly linked to this vendor (clients.purchase_vendor_id) ─
     *
     * The Purchase-side mirror of VendorController::customers/storeCustomer.
     * Reuses the Customer module's Client model on its OWN link column, so a
     * client can belong to a TPV vendor, a Purchase vendor, both or neither.
     */
    public function customers(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json(
            $purchaseVendor->customers()
                ->orderByDesc('id')
                ->get(['id', 'company', 'phone', 'website', 'gst_number', 'city', 'state', 'country', 'active', 'created_at'])
        );
    }

    /**
     * Existing customers this vendor could be linked to.
     *
     * The Customer tab renders the shared VendorCustomersPanel, which calls
     * customers.search() before it can offer anything. Purchase's client had no
     * such method and no endpoint behind it, so the panel threw inside its
     * promise chain and sat on "Searching..." for ever -- no toast, nothing in
     * the network tab, just a spinner. TPV had both, which is why it worked
     * there and not here.
     *
     * Offers unlinked customers, plus ones already on THIS vendor so the search
     * is idempotent and does not hide what is already attached.
     */
    public function searchCustomers(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        $data = $request->validate(['q' => 'nullable|string|max:120']);
        $q = trim((string) ($data['q'] ?? ''));

        $rows = \App\Models\Customer\Client::query()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->where(function ($w) use ($purchaseVendor) {
                $w->whereNull('purchase_vendor_id')
                    ->orWhere('purchase_vendor_id', $purchaseVendor->id);
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
            ->get(['id', 'company', 'phone', 'gst_number', 'city', 'state', 'country', 'purchase_vendor_id']);

        return response()->json($rows);
    }

    /**
     * Link an existing customer to this vendor.
     *
     * Idempotent when it is already this vendor's, and refuses to take one that
     * belongs to another -- a customer silently moving between vendors is worse
     * than being told to unlink it first.
     *
     * Note this sets purchase_vendor_id, not vendor_id: a customer may be linked
     * to a TPV vendor and a Purchase vendor at once, and they are different
     * relationships on different columns.
     */
    public function linkCustomer(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        $data = $request->validate(['client_id' => 'required|integer']);

        $client = \App\Models\Customer\Client::query()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->find($data['client_id']);

        abort_unless($client, 404, 'Customer not found.');

        if ($client->purchase_vendor_id
            && (int) $client->purchase_vendor_id !== (int) $purchaseVendor->id) {
            abort(422, 'That customer is already linked to another purchase vendor.');
        }

        if ((int) $client->purchase_vendor_id !== (int) $purchaseVendor->id) {
            $client->update(['purchase_vendor_id' => $purchaseVendor->id]);
        }

        return response()->json($client->fresh() ?? $client, 200);
    }

    public function storeCustomer(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

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

        // Reuse the Customer module's Client model. tenant_id is set explicitly
        // from the caller (like every other vendor-scoped create here) rather than
        // relying on the BelongsToTenant auto-stamp, so it never depends on ambient
        // auth() state.
        $client = Client::create(array_merge($data, [
            'tenant_id'          => (int) $request->user()->tenant_id,
            'purchase_vendor_id' => $purchaseVendor->id,
            'added_by'           => (int) $request->user()->id,
            'active'             => true,
        ]));

        return response()->json($client, 201);
    }

    /**
     * Correct a customer already linked to this vendor.
     *
     * The tab could add a customer and then never touch it again, so a typo in a
     * company name meant opening the Customer module to find the record
     * (SIR-000012). Same fields the Add form collects -- no more -- and the
     * client must ALREADY be linked to this vendor, so this is a correction
     * route, not a way to reach into the Customer module at large.
     */
    public function updateCustomer(Request $request, PurchaseVendor $purchaseVendor, int $client)
    {
        $this->assertTenant($request, $purchaseVendor);

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

        $record = Client::query()
            ->where('tenant_id', (int) $request->user()->tenant_id)
            ->where('purchase_vendor_id', $purchaseVendor->id)
            ->find($client);

        abort_unless($record, 404, 'That customer is not linked to this vendor.');

        $record->update($data);

        return response()->json($record->fresh() ?? $record, 200);
    }

    /** Resend the activation e-mail. Active vendors only; every send is logged. */
    public function resendActivation(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json($this->vendors->resendActivationEmail($purchaseVendor, $request->user()));
    }

    public function update(UpdatePurchaseVendorRequest $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json($this->vendors->update($purchaseVendor, $request->validated(), $request->user()));
    }

    public function updateStatus(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);
        $data = $request->validate([
            'status'  => ['required', Rule::in(PurchaseVendorStatus::ALL)],
            'remarks' => 'nullable|string|max:2000',
        ]);

        return response()->json($this->vendors->updateStatus($purchaseVendor, $data['status'], $request->user(), $data['remarks'] ?? null));
    }

    /** Activate a vendor for procurement (role:admin). */
    public function approve(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json($this->vendors->approve($purchaseVendor, $request->user()));
    }

    /**
     * Promote a temporary vendor to permanent (role:admin).
     *
     * Admin authority for the same reason approve() is: it removes an expiry
     * somebody deliberately set, and after this the account transacts with no
     * end date. The counterpart of TPV's /vendors/{vendor}/access/convert.
     */
    public function convertToPermanent(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json([
            'message' => 'This vendor is now permanent.',
            'vendor'  => $this->vendors->convertToPermanent($purchaseVendor, $request->user()),
        ]);
    }

    /**
     * Move a temporary window (role:admin).
     *
     * The option Purchase never had. Promotion and expiry both existed and
     * nothing sat between them, so "three more days" meant choosing between
     * making a contractor permanent for ever and letting them be locked out on
     * the day. TPV has had this since its temporary work landed.
     */
    public function extendAccess(Request $request, PurchaseVendor $purchaseVendor, PurchaseAccessService $access)
    {
        $this->assertTenant($request, $purchaseVendor);

        $data = $request->validate([
            // One or the other: an explicit date, or a number of days from now.
            'access_expires_at' => 'nullable|date|required_without:validity_days',
            'validity_days'     => 'nullable|integer|min:1|max:365|required_without:access_expires_at',
            // Mandatory. An extension that records only a new date cannot answer
            // why the window moved, which is the question asked months later.
            'extension_reason'  => 'required|string|min:3|max:500',
        ]);

        return response()->json([
            'message' => 'The access window has been extended.',
            'vendor'  => $access->extend($purchaseVendor, $request->user(), $data),
        ]);
    }

    /** Close a temporary window now (role:admin). */
    public function expireAccess(Request $request, PurchaseVendor $purchaseVendor, PurchaseAccessService $access)
    {
        $this->assertTenant($request, $purchaseVendor);

        if (! $purchaseVendor->isTemporary()) {
            throw new \App\Exceptions\BusinessException(
                'This vendor is permanent — there is no window to close.', 422);
        }

        return response()->json([
            'message' => 'The access window has been closed.',
            'vendor'  => $access->expire($purchaseVendor, $request->user()),
        ]);
    }

    /** The countdown, who moved it and why, and the trail behind it. */
    public function accessStatus(Request $request, PurchaseVendor $purchaseVendor, PurchaseAccessService $access)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json($access->status($purchaseVendor));
    }

    /* ── Recognition ───────────────────────────────────────────────────
     *
     * Awards and referrals: the last two entries in this workspace's
     * Performance group that had nothing behind them. Purchase-owned tables —
     * TPV's vendor_awards and vendor_referrals are keyed to its own master, and
     * hanging a second vendor id off those would put two unrelated populations
     * in one table with half its rows null either way.
     */

    public function awards(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json([
            'data' => PurchaseVendorAward::forTenant($purchaseVendor->tenant_id)
                ->where('purchase_vendor_id', $purchaseVendor->id)
                ->with('grantedBy:id,name')
                ->orderByDesc('awarded_on')
                ->get(),
        ]);
    }

    public function grantAward(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        $data = $request->validate([
            'title'       => 'required|string|max:200',
            'category'    => 'nullable|string|max:60',
            'description' => 'nullable|string|max:2000',
            'awarded_on'  => 'nullable|date',
        ]);

        $award = PurchaseVendorAward::create($data + [
            'tenant_id'          => $purchaseVendor->tenant_id,
            'purchase_vendor_id' => $purchaseVendor->id,
            // Recognition is dated the day it is given unless somebody is
            // recording one from the past.
            'awarded_on'         => $data['awarded_on'] ?? now()->toDateString(),
            'granted_by'         => $request->user()->id,
        ]);

        $purchaseVendor->recordAudit('Award Granted', $request->user(), null, ['title' => $award->title]);

        return response()->json(['data' => $award->fresh('grantedBy')], 201);
    }

    public function deleteAward(Request $request, PurchaseVendor $purchaseVendor, int $award)
    {
        $this->assertTenant($request, $purchaseVendor);

        // Scoped by vendor as well as id: an award id from another vendor must
        // 404 rather than delete.
        $row = PurchaseVendorAward::forTenant($purchaseVendor->tenant_id)
            ->where('purchase_vendor_id', $purchaseVendor->id)
            ->findOrFail($award);

        $row->delete();
        $purchaseVendor->recordAudit('Award Removed', $request->user(), null, ['title' => $row->title]);

        return response()->json(['message' => 'Award removed.']);
    }

    public function referrals(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json([
            'data' => PurchaseVendorReferral::forTenant($purchaseVendor->tenant_id)
                ->where('referred_by_purchase_vendor_id', $purchaseVendor->id)
                ->orderByDesc('id')
                ->get(),
            'statuses' => PurchaseVendorReferral::STATUSES,
        ]);
    }

    public function storeReferral(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        $data = $request->validate([
            'company_name'  => 'required|string|max:200',
            'contact_name'  => 'nullable|string|max:150',
            'contact_email' => 'nullable|email|max:200',
            'contact_phone' => 'nullable|string|max:40',
            'note'          => 'nullable|string|max:2000',
            'status'        => ['nullable', Rule::in(PurchaseVendorReferral::STATUSES)],
        ]);

        $referral = PurchaseVendorReferral::create($data + [
            'tenant_id' => $purchaseVendor->tenant_id,
            'referred_by_purchase_vendor_id' => $purchaseVendor->id,
            'status'    => $data['status'] ?? 'Pending',
        ]);

        return response()->json(['data' => $referral], 201);
    }

    public function setReferralStatus(Request $request, PurchaseVendor $purchaseVendor, int $referral)
    {
        $this->assertTenant($request, $purchaseVendor);

        $data = $request->validate([
            'status' => ['required', Rule::in(PurchaseVendorReferral::STATUSES)],
        ]);

        $row = PurchaseVendorReferral::forTenant($purchaseVendor->tenant_id)
            ->where('referred_by_purchase_vendor_id', $purchaseVendor->id)
            ->findOrFail($referral);

        $row->update(['status' => $data['status']]);

        return response()->json(['data' => $row->fresh()]);
    }

    public function destroy(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);
        $this->vendors->delete($purchaseVendor, $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    /* ── Notes ─────────────────────────────────────────────────────────
     *
     * The SHARED polymorphic `notes` table, addressed by model class — the same
     * engine the TPV vendor uses, pointed at PurchaseVendor. No second table and
     * no Purchase-owned copy of the service.
     *
     * The subject comes from the ROUTE. NoteService checks the tenant but never
     * the subject, so assertNoteBelongs() below is the only thing stopping a note
     * id from elsewhere in the tenant being read or edited through this vendor.
     */

    public function notes(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json(
            $this->notes->listForSubject(PurchaseVendor::class, $purchaseVendor->id, (int) $purchaseVendor->tenant_id)
        );
    }

    public function storeNote(StoreVendorNoteRequest $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json(
            $this->notes->createForSubject(
                PurchaseVendor::class, $purchaseVendor->id, $request->validated(),
                (int) $purchaseVendor->tenant_id, (int) $request->user()->id,
            ),
            201,
        );
    }

    public function updateNote(StoreVendorNoteRequest $request, PurchaseVendor $purchaseVendor, Note $note)
    {
        $this->assertNoteBelongs($request, $purchaseVendor, $note);

        return response()->json(
            $this->notes->update($note, $request->validated(), (int) $purchaseVendor->tenant_id)
        );
    }

    public function destroyNote(Request $request, PurchaseVendor $purchaseVendor, Note $note)
    {
        $this->assertNoteBelongs($request, $purchaseVendor, $note);

        $this->notes->delete($note, (int) $purchaseVendor->tenant_id);

        return response()->json(['message' => 'Note deleted']);
    }

    /* ── Reminders ─────────────────────────────────────────────────────
     *
     * The shared polymorphic `reminders` table. Mounted here rather than under
     * /api/sales/reminders: that group carries no role gate, so vendor follow-ups
     * behind it would be readable by every authenticated login.
     *
     * There is deliberately no update action. ReminderService::update() re-resolves
     * a client-supplied remindable_type, so exposing it would let a reminder be
     * retargeted at another record. Complete and delete are enough for the tab.
     */

    public function reminders(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json(
            $this->reminders->listForSubject(PurchaseVendor::class, $purchaseVendor->id, (int) $purchaseVendor->tenant_id)
        );
    }

    public function storeReminder(StoreVendorReminderRequest $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json(
            $this->reminders->createForSubject(
                PurchaseVendor::class, $purchaseVendor->id, $request->validated(),
                (int) $purchaseVendor->tenant_id, (int) $request->user()->id,
            ),
            201,
        );
    }

    public function completeReminder(Request $request, PurchaseVendor $purchaseVendor, Reminder $reminder)
    {
        $this->assertReminderBelongs($request, $purchaseVendor, $reminder);

        $data = $request->validate([
            'outcome'        => 'nullable|string',
            'next_follow_up' => 'nullable|date',
        ]);

        return response()->json(
            $this->reminders->complete($reminder, $data, (int) $purchaseVendor->tenant_id, (int) $request->user()->id)
        );
    }

    public function destroyReminder(Request $request, PurchaseVendor $purchaseVendor, Reminder $reminder)
    {
        $this->assertReminderBelongs($request, $purchaseVendor, $reminder);

        $this->reminders->delete($reminder, (int) $purchaseVendor->tenant_id);

        return response()->json(['message' => 'Reminder deleted']);
    }

    /* ── Attachments (folder-tree file area) ───────────────────────────
     *
     * The shared polymorphic attachment_folders/attachments tables, including the
     * Google Drive and OneDrive import path — those pickers download the bytes in
     * the browser and post them to storeAttachment like any local file.
     *
     * AttachmentService performs NO tenant and NO subject check on rename / move /
     * delete / download. The two assertions below are the entire barrier.
     */

    public function attachments(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json($this->attachments->browse(
            PurchaseVendor::class, $purchaseVendor->id, (int) $purchaseVendor->tenant_id,
            $request->filled('folder_id') ? (int) $request->query('folder_id') : null,
        ));
    }

    public function storeAttachmentFolder(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        $data = $request->validate([
            'name'      => 'required|string|max:200',
            'parent_id' => 'nullable|integer|min:1',
        ]);

        return response()->json(
            $this->attachments->createFolder(
                PurchaseVendor::class, $purchaseVendor->id, (int) $purchaseVendor->tenant_id, $data, $request->user(),
            ),
            201,
        );
    }

    public function updateAttachmentFolder(Request $request, PurchaseVendor $purchaseVendor, AttachmentFolder $folder)
    {
        $this->assertAttachmentFolderBelongs($request, $purchaseVendor, $folder);

        $data = $request->validate(['name' => 'required|string|max:200']);

        return response()->json($this->attachments->renameFolder($folder, $data['name']));
    }

    public function destroyAttachmentFolder(Request $request, PurchaseVendor $purchaseVendor, AttachmentFolder $folder)
    {
        $this->assertAttachmentFolderBelongs($request, $purchaseVendor, $folder);

        $this->attachments->deleteFolder($folder);

        return response()->json(['message' => 'Folder deleted']);
    }

    public function storeAttachment(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        $data = $request->validate([
            'file'       => 'required|file|max:51200',           // 50 MB
            'folder_id'  => 'nullable|integer|min:1',
            'name'       => 'nullable|string|max:200',
            'source'     => 'nullable|in:upload,google_drive,onedrive',
            'source_ref' => 'nullable|string|max:255',
        ]);

        return response()->json(
            $this->attachments->upload(
                PurchaseVendor::class, $purchaseVendor->id, (int) $purchaseVendor->tenant_id,
                $request->file('file'), $data['folder_id'] ?? null, $data, $request->user(),
            ),
            201,
        );
    }

    public function updateAttachment(Request $request, PurchaseVendor $purchaseVendor, Attachment $attachment)
    {
        $this->assertAttachmentBelongs($request, $purchaseVendor, $attachment);

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

    public function downloadAttachment(Request $request, PurchaseVendor $purchaseVendor, Attachment $attachment)
    {
        $this->assertAttachmentBelongs($request, $purchaseVendor, $attachment);

        $f = $this->attachments->download($attachment);

        return response()->download($f['path'], $f['filename'], ['Content-Type' => $f['mime']]);
    }

    public function destroyAttachment(Request $request, PurchaseVendor $purchaseVendor, Attachment $attachment)
    {
        $this->assertAttachmentBelongs($request, $purchaseVendor, $attachment);

        $this->attachments->deleteFile($attachment);

        return response()->json(['message' => 'File deleted']);
    }

    /* ── Appointments ──────────────────────────────────────────────────
     *
     * The shared `appointments` table (subject_type / subject_id), same engine
     * Sales uses for leads.
     *
     * Mirrored here rather than pointing the tab at /api/sales/appointments:
     * that group is gated on auth:sanctum ALONE with no role check, and its index
     * takes subject_type as a free string — so vendor appointments behind it
     * would be readable by every authenticated login, portal tokens included.
     * The subject is supplied by this controller, never by the caller.
     */
    public const APPOINTMENT_SUBJECT = 'purchase_vendor';

    public function appointments(Request $request, PurchaseVendor $purchaseVendor, AppointmentService $appointments)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json($appointments->listForSubject(
            (int) $purchaseVendor->tenant_id, self::APPOINTMENT_SUBJECT, $purchaseVendor->id,
        ));
    }

    public function storeAppointment(Request $request, PurchaseVendor $purchaseVendor, AppointmentService $appointments)
    {
        $this->assertTenant($request, $purchaseVendor);

        $data = $request->validate([
            'title'       => 'required|string|max:200',
            'description' => 'nullable|string',
            'location'    => 'nullable|string|max:200',
            'starts_at'   => 'required|date',
            'ends_at'     => 'nullable|date',
            'assigned_to' => 'nullable|integer|min:1',
        ]);

        // Subject forced from the route — a payload cannot file this against
        // another record.
        $data['subject_type'] = self::APPOINTMENT_SUBJECT;
        $data['subject_id']   = $purchaseVendor->id;

        return response()->json(
            $appointments->create($data, (int) $purchaseVendor->tenant_id, (int) $request->user()->id),
            201,
        );
    }

    public function completeAppointment(Request $request, PurchaseVendor $purchaseVendor, Appointment $appointment, AppointmentService $appointments)
    {
        $this->assertAppointmentBelongs($request, $purchaseVendor, $appointment);

        $data = $request->validate([
            'outcome' => 'required|string',
            'status'  => 'nullable|in:completed,cancelled,no_show',
        ]);

        return response()->json($appointments->complete($appointment, $data, (int) $purchaseVendor->tenant_id));
    }

    public function destroyAppointment(Request $request, PurchaseVendor $purchaseVendor, Appointment $appointment, AppointmentService $appointments)
    {
        $this->assertAppointmentBelongs($request, $purchaseVendor, $appointment);

        $appointments->delete($appointment, (int) $purchaseVendor->tenant_id);

        return response()->json(['message' => 'Appointment deleted']);
    }

    private function assertAppointmentBelongs(Request $request, PurchaseVendor $purchaseVendor, Appointment $appointment): void
    {
        $this->assertTenant($request, $purchaseVendor);

        abort_unless(
            $appointment->subject_type === self::APPOINTMENT_SUBJECT
                && (int) $appointment->subject_id === (int) $purchaseVendor->id,
            404,
            'Appointment not found',
        );
    }

    /* ── Commercial: payments + statement ──────────────────────────────
     *
     * Native here — every commercial document already keys to
     * purchase_vendors.purchase_vendor_id, so there is no link step and no
     * "not linked" state. Payments have no vendor column of their own: they hang
     * off invoices, so the vendor's invoices are the hop.
     */

    public function payments(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        $tid = (int) $purchaseVendor->tenant_id;

        return response()->json(
            PurchaseInvoicePayment::forTenant($tid)
                ->whereIn('purchase_invoice_id', PurchaseInvoice::forTenant($tid)
                    ->where('purchase_vendor_id', $purchaseVendor->id)->select('id'))
                ->with(['invoice:id,invoice_number,invoice_date', 'creator:id,name'])
                ->orderByDesc('payment_date')->orderByDesc('id')
                ->get()
        );
    }

    /**
     * Running account statement: invoices debit the account, payments and debit
     * notes credit it, oldest first with a running balance.
     *
     * There is no pre-existing vendor-statement engine in Purchase or in the
     * accounts module to reuse — `total` on purchase_invoices / purchase_debit_notes
     * plus purchase_invoice_payments.amount are the source of truth, and this is
     * the same derivation the TPV commercial tab uses.
     */
    public function statement(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        $tid = (int) $purchaseVendor->tenant_id;
        $pv  = (int) $purchaseVendor->id;

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

        return response()->json(['lines' => $lines, 'closing_balance' => round($balance, 2)]);
    }

    /* ── Ownership guards ──────────────────────────────────────────────
     *
     * Each compares against PurchaseVendor::class. Comparing against Vendor::class
     * here — the copy-paste hazard — would 404 every read-back of a row this
     * controller had just written, and read as "nothing saved" rather than a bug.
     */

    private function assertNoteBelongs(Request $request, PurchaseVendor $purchaseVendor, Note $note): void
    {
        $this->assertTenant($request, $purchaseVendor);

        abort_unless(
            $note->notable_type === PurchaseVendor::class && (int) $note->notable_id === (int) $purchaseVendor->id,
            404,
            'Note not found',
        );
    }

    private function assertReminderBelongs(Request $request, PurchaseVendor $purchaseVendor, Reminder $reminder): void
    {
        $this->assertTenant($request, $purchaseVendor);

        abort_unless(
            $reminder->remindable_type === PurchaseVendor::class
                && (int) $reminder->remindable_id === (int) $purchaseVendor->id,
            404,
            'Reminder not found',
        );
    }

    private function assertAttachmentFolderBelongs(Request $request, PurchaseVendor $purchaseVendor, AttachmentFolder $folder): void
    {
        $this->assertTenant($request, $purchaseVendor);

        abort_unless(
            $folder->attachable_type === PurchaseVendor::class
                && (int) $folder->attachable_id === (int) $purchaseVendor->id,
            404,
            'Folder not found',
        );
    }

    private function assertAttachmentBelongs(Request $request, PurchaseVendor $purchaseVendor, Attachment $attachment): void
    {
        $this->assertTenant($request, $purchaseVendor);

        abort_unless(
            $attachment->attachable_type === PurchaseVendor::class
                && (int) $attachment->attachable_id === (int) $purchaseVendor->id,
            404,
            'File not found',
        );
    }

    /* ── Compliance & HSSE / Performance mirror (Purchase-native) ────────── */

    public function permits(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json([
            'data' => \App\Models\Purchase\PurchaseWorkPermit::where('tenant_id', $purchaseVendor->tenant_id)
                ->where('purchase_vendor_id', $purchaseVendor->id)->latest('id')->get(),
        ]);
    }

    public function incidents(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json([
            'data' => \App\Models\Purchase\PurchaseHsseIncident::where('tenant_id', $purchaseVendor->tenant_id)
                ->where('purchase_vendor_id', $purchaseVendor->id)->latest('occurred_at')->get(),
        ]);
    }

    /** Admin sets the vendor's lean risk tier + score (the portal shows it read-only). */
    public function assessRisk(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        $data = $request->validate([
            'risk_level' => 'required|string|in:Low,Medium,High,Critical',
            'risk_score' => 'required|integer|min:0|max:100',
            'risk_notes' => 'nullable|string|max:2000',
        ]);

        $purchaseVendor->update(array_merge($data, ['risk_assessed_at' => now()]));

        return response()->json($purchaseVendor->only(['id', 'risk_level', 'risk_score', 'risk_notes', 'risk_assessed_at']));
    }

    private function assertTenant(Request $request, PurchaseVendor $purchaseVendor): void
    {
        abort_unless((int) $purchaseVendor->tenant_id === (int) $request->user()->tenant_id, 404, 'Purchase vendor not found');
    }
}
