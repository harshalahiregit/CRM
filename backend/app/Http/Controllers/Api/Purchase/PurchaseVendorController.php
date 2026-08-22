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

    private function assertTenant(Request $request, PurchaseVendor $purchaseVendor): void
    {
        abort_unless((int) $purchaseVendor->tenant_id === (int) $request->user()->tenant_id, 404, 'Purchase vendor not found');
    }
}
