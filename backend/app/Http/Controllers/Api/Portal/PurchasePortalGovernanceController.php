<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseCapa;
use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Purchase\PurchaseMomActionItem;
use App\Models\Purchase\PurchaseNcr;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerTraining;
use App\Services\Purchase\PurchaseApprovalRequestService;
use App\Support\Purchase\PurchaseApprovalType;
use App\Support\RichText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Services\Purchase\PurchaseKickoffService;
use App\Services\Shared\MeetingAttendanceGate;
use App\Support\Shared\VendorMomView;

/**
 * §32 Purchase Vendor Portal — the governance-response half, mirroring the TPV
 * portal on the Purchase-owned models (separate DB/tables). The caller IS a
 * PurchaseVendor (Sanctum token); everything is scoped to it. Read + respond
 * only — approve/verify/close stay admin-side. (Purchase has no PPE requirement
 * matrix, so that TPV-only capability is deliberately absent.)
 */
class PurchasePortalGovernanceController extends Controller
{
    public function __construct(
        private PurchaseApprovalRequestService $approvals,
        // Required, not nullable-with-a-default: the container silently skips a
        // parameter that has one, and every call through it would be a no-op.
        private PurchaseKickoffService $kickoffService,
    ) {
    }

    /**
     * The PPE the vendor's own workers are required to hold.
     *
     * Read-only: the matrix is the site's rule, not the vendor's. Showing it
     * matters because a badge refused for "mandatory PPE not issued" is only
     * actionable if the vendor can see what the requirement actually is.
     *
     * Active rules only — an inactive rule is one the site has stood down, and
     * listing it would have vendors issuing kit nobody asks for.
     */
    public function ppeMatrix(Request $request)
    {
        $vendor = $this->vendor($request);

        $rules = \App\Models\Purchase\PurchasePpeRequirement::where('tenant_id', $vendor->tenant_id)
            ->where('is_active', true)
            ->with('product:id,name,sku')
            ->orderBy('scope_type')->orderBy('scope_value')
            ->get()
            ->map(fn ($r) => [
                'scope_type' => $r->scope_type,
                'scope_value' => $r->scope_value,
                'hazard' => $r->hazard,
                'activity' => $r->activity,
                'ppe_class' => $r->ppe_class ?? 'mandatory',
                'condition' => $r->condition,
                'product' => $r->product?->name,
                'qty' => $r->qty,
                'replacement_frequency_days' => $r->replacement_frequency_days,
                'verification_required' => (bool) $r->verification_required,
            ]);

        return response()->json(['rules' => $rules]);
    }

    private function vendor(Request $request): PurchaseVendor
    {
        $v = $request->user();
        abort_unless($v instanceof PurchaseVendor, 403, 'This area is for Purchase vendor accounts only.');

        return $v;
    }

    /* ── NCRs ───────────────────────────────────────────────────────────── */

    public function ncrs(Request $request)
    {
        $v = $this->vendor($request);

        return response()->json([
            'data' => PurchaseNcr::where('tenant_id', $v->tenant_id)->where('purchase_vendor_id', $v->id)
                ->latest('id')->get(),
            'statuses' => PurchaseNcr::STATUSES,
        ]);
    }

    public function respondNcr(Request $request, PurchaseNcr $ncr)
    {
        $this->assertOwned($request, $ncr);

        $data = $request->validate(['response' => 'required|string|max:5000']);
        $ncr->update([
            'response' => $data['response'],
            'status'   => in_array($ncr->status, ['Raised', 'Assigned'], true) ? 'Response' : $ncr->status,
        ]);

        return response()->json($ncr->fresh());
    }

    /* ── CAPAs ──────────────────────────────────────────────────────────── */

    public function capas(Request $request)
    {
        $v = $this->vendor($request);

        return response()->json([
            'data' => PurchaseCapa::where('tenant_id', $v->tenant_id)->where('purchase_vendor_id', $v->id)
                ->latest('id')->get(),
        ]);
    }

    public function submitCapaEvidence(Request $request, PurchaseCapa $capa)
    {
        $this->assertOwned($request, $capa);

        $data = $request->validate([
            'note'          => 'nullable|string|max:2000',
            'evidence'      => 'nullable|file|mimes:pdf,doc,docx,png,jpg,jpeg|max:10240',
            'evidence_data' => 'nullable|string',
        ]);

        $path = $capa->evidence_path;
        if ($request->hasFile('evidence')) {
            $path = $request->file('evidence')->store('purchase/capa-evidence', 'public');
        } elseif (! empty($data['evidence_data']) && str_contains($data['evidence_data'], 'base64,')) {
            $binary = base64_decode(explode('base64,', $data['evidence_data'])[1]);
            $path   = 'purchase/capa-evidence/capa_'.$capa->id.'_'.uniqid().'.dat';
            Storage::disk('public')->put($path, $binary);
        }

        $capa->update([
            'evidence_path'      => $path,
            'verification_notes' => $data['note'] ?? $capa->verification_notes,
            'status'             => ($path && ! empty($capa->assigned_to) && ! in_array($capa->status, ['Done', 'Verified'], true))
                ? 'Done' : $capa->status,
        ]);

        return response()->json($capa->fresh());
    }

    /* ── Request approvals + extensions ─────────────────────────────────── */

    public function requestApproval(Request $request)
    {
        $v = $this->vendor($request);
        $data = $request->validate([
            'title'       => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'priority'    => 'nullable|in:Low,Medium,High',
        ]);

        $approval = $this->approvals->raise([
            'approval_type'      => PurchaseApprovalType::OTHER,
            'subject_type'       => PurchaseVendor::class,
            'subject_id'         => $v->id,
            'purchase_vendor_id' => $v->id,
            'title'              => $data['title'],
            'description'        => $data['description'] ?? null,
            'priority'           => $data['priority'] ?? 'Medium',
            'meta'               => ['origin' => 'purchase_portal'],
        ], (int) $v->tenant_id, 0);

        return response()->json($approval, 201);
    }

    public function requestExtension(Request $request)
    {
        $v = $this->vendor($request);
        $data = $request->validate([
            'reason'       => 'required|string|max:2000',
            'requested_to' => 'nullable|date',
            'subject'      => 'nullable|string|max:160',
        ]);

        $approval = $this->approvals->raise([
            'approval_type'      => PurchaseApprovalType::EXTENSION,
            'subject_type'       => PurchaseVendor::class,
            'subject_id'         => $v->id,
            'purchase_vendor_id' => $v->id,
            'title'              => 'Extension request'.(! empty($data['subject']) ? ': '.$data['subject'] : '').' — '.$v->company_name,
            'description'        => $data['reason'],
            'meta'               => ['origin' => 'purchase_portal', 'requested_to' => $data['requested_to'] ?? null],
        ], (int) $v->tenant_id, 0);

        return response()->json($approval, 201);
    }

    /* ── Meetings, MOM + actions ────────────────────────────────────────── */

    public function meetings(Request $request)
    {
        $v = $this->vendor($request);

        $meetings = PurchaseKickoffMeeting::where('tenant_id', $v->tenant_id)
            ->where('purchase_vendor_id', $v->id)
            // Never expose unpublished drafts to the vendor.
            ->where('status', '!=', \App\Support\Purchase\PurchaseKickoffStatus::DRAFT)
            // Ordered by when the meeting IS, not by the order rows happened to
            // be written — the shared engine has always ordered this way and the
            // two portals listed the same vendor's meetings differently.
            // The roster, as the TPV portal has always sent it. Purchase sent
            // none at all, so a Purchase vendor opened their own meeting and
            // could not see who was in it — including their own people. Same
            // three columns, so the one portal screen renders both engines.
            ->with('participants:id,purchase_kickoff_meeting_id,name,role')
            // The agenda, so the meeting page is worth opening — that is the
            // whole trade being offered in place of a link in the e-mail. Only
            // the agenda columns: `discussion` and `decision` on the same table
            // are the MINUTES, which the vendor may not see until they are
            // approved and distributed.
            ->with(['agendaItems' => fn ($q) => $q->select(
                'id', 'purchase_kickoff_meeting_id', 'item', 'description', 'owner_names', 'duration_minutes', 'sort_order',
            )->orderBy('sort_order')->orderBy('id')])
            ->latest('scheduled_at')->get();

        // The minutes are the vendor's to see only once approved+distributed.
        $gate = app(MeetingAttendanceGate::class);

        $meetings->each(function ($m) use ($gate, $v) {
            $m->setAttribute('mom_available', \App\Support\Purchase\PurchaseMomApprovalStatus::isDistributable($m->mom_status));
            // The join link is not in this payload until the vendor has marked
            // attendance — see MeetingAttendanceGate. Withheld here rather than
            // hidden in the page, because a link sitting in the JSON is readable
            // whatever the page chooses to draw.
            foreach ($gate->stateFor($m, $v) as $field => $value) {
                $m->setAttribute($field, $value);
            }

            // One agreed shape for both portals, as VendorMomView already does
            // for the minutes: the shared engine calls this roster `attendees`
            // and Purchase calls it `participants`, and one screen renders both.
            // Sending only Purchase's own name would leave the roster invisible
            // on this engine and nowhere for the reader to find out why.
            $m->setAttribute('attendees', $m->participants);
        });

        return response()->json(['data' => $meetings]);
    }

    public function meetingMom(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $v = $this->vendor($request);
        abort_unless((int) $kickoff->tenant_id === (int) $v->tenant_id && (int) $kickoff->purchase_vendor_id === (int) $v->id, 404, 'Meeting not found');

        // Point 8 parity: the vendor sees minutes only after approval+distribution.
        abort_unless(
            \App\Support\Purchase\PurchaseMomApprovalStatus::isDistributable($kickoff->mom_status),
            403,
            'These minutes are not yet available.'
        );

        // Stamped HERE rather than where an administrator opens the document.
        // "Viewed" on the distribution tracker is a claim about the recipient,
        // and this is the only place the recipient is the one reading.
        $this->kickoffService->markMomViewed($kickoff);

        // One agreed shape for both portals. This engine used to hand its own
        // models straight out, under names — action_items, mom_decisions — that
        // the shared portal screen was not looking for, so a Purchase vendor
        // opened their minutes and found only the agenda. See VendorMomView.
        return response()->json(VendorMomView::for(
            $kickoff,
            (bool) $this->kickoffService->currentMomFile($kickoff),
        ));
    }


    /**
     * Mark attendance, and get the link in return.
     *
     * The meeting itself runs on Google Meet, Zoom or Teams — somewhere this
     * system cannot see — so nothing here can tell who sat through it. What we
     * CAN see is this account saying "I am attending", and that is the moment
     * the link is handed over. Before it, the link is not in any response the
     * vendor can read.
     *
     * It records what it can honestly claim: this account opened this meeting,
     * at this time, from this device. Whether they actually stayed is the
     * organiser's to judge, from this same log.
     */
    public function markAttendance(Request $request, PurchaseKickoffMeeting $kickoff, MeetingAttendanceGate $gate)
    {
        $v = $this->vendor($request);
        abort_unless((int) $kickoff->tenant_id === (int) $v->tenant_id && (int) $kickoff->purchase_vendor_id === (int) $v->id, 404, 'Meeting not found');

        /*
         * A meeting scheduled FOR a vendor commonly has no roster row for that
         * vendor. Without a row there is nowhere for the attendance to land, and
         * the vendor would be locked out of their own meeting for ever. The
         * identity is not guessed: it is the vendor record this request already
         * authenticated as.
         */
        return response()->json($gate->mark($kickoff, $v, $request, [
            'name' => $v->company_name ?: $v->name,
            'email' => $v->email,
            'organisation' => $v->company_name ?: $v->name,
            'side' => 'external',
        ]));
    }

    /**
     * The minutes document itself.
     *
     * The whole approve-then-distribute workflow exists to put this file in the
     * vendor's hands, and there was no way for them to open it: the only route
     * that served it sat behind role:admin,staff. The vendor was told their
     * minutes had been distributed and given no means to read them.
     */
    public function meetingMomFile(Request $request, PurchaseKickoffMeeting $kickoff)
    {
        $v = $this->vendor($request);
        abort_unless((int) $kickoff->tenant_id === (int) $v->tenant_id && (int) $kickoff->purchase_vendor_id === (int) $v->id, 404, 'Meeting not found');

        abort_unless(
            \App\Support\Purchase\PurchaseMomApprovalStatus::isDistributable($kickoff->mom_status),
            403,
            'These minutes are not yet available.'
        );

        $file = $this->kickoffService->currentMomFile($kickoff);
        abort_unless($file, 404, 'No minutes document has been issued for this meeting.');

        $this->kickoffService->markMomViewed($kickoff);

        return response()->download($file['path'], 'Minutes-'.($kickoff->meeting_no ?: $kickoff->id).'.pdf', [
            'Content-Type'        => $file['mime'],
            'Content-Disposition' => 'inline; filename="Minutes-'.($kickoff->meeting_no ?: $kickoff->id).'.pdf"',
        ]);
    }

    /** Download one of a meeting's labelled documents (only after approval). */
    public function meetingDocument(Request $request, PurchaseKickoffMeeting $kickoff, \App\Models\Purchase\PurchaseKickoffDocument $document)
    {
        $v = $this->vendor($request);
        abort_unless((int) $kickoff->tenant_id === (int) $v->tenant_id && (int) $kickoff->purchase_vendor_id === (int) $v->id, 404, 'Meeting not found');
        abort_unless(
            \App\Support\Purchase\PurchaseMomApprovalStatus::isDistributable($kickoff->mom_status),
            403,
            'These documents are not yet available.'
        );
        abort_unless((int) $document->purchase_kickoff_meeting_id === (int) $kickoff->id, 404, 'Document not found.');
        abort_unless(
            $document->path && \Illuminate\Support\Facades\Storage::disk('purchase_kickoff_docs')->exists($document->path),
            404,
            'Document file is missing.'
        );

        return \Illuminate\Support\Facades\Storage::disk('purchase_kickoff_docs')->response(
            $document->path, $document->original_name, [],
            $request->boolean('inline') ? 'inline' : 'attachment'
        );
    }

    public function actions(Request $request)
    {
        $v = $this->vendor($request);

        $meetingIds = PurchaseKickoffMeeting::where('tenant_id', $v->tenant_id)
            ->where('purchase_vendor_id', $v->id)->pluck('id');

        $actions = PurchaseMomActionItem::where('tenant_id', $v->tenant_id)
            ->whereIn('purchase_kickoff_meeting_id', $meetingIds)
            ->latest('id')->get();

        // An action item's description is written in a rich editor, so it is
        // HTML. Handing the model straight to the portal sent that HTML to a
        // screen that renders text, and the vendor read a wall of `<span
        // style=...>` and a base64 <img> src instead of the instruction. The
        // `*_html` twin is what the portal renders; the plain one is the text.
        return response()->json(['data' => $actions->map(fn ($a) => array_merge($a->toArray(), [
            'description'      => RichText::toText($a->description),
            'description_html' => RichText::display($a->description),
            'remark_html'      => RichText::display($a->remark),
        ]))]);
    }

    public function respondAction(Request $request, PurchaseMomActionItem $action)
    {
        $v = $this->vendor($request);
        $ok = PurchaseKickoffMeeting::where('id', $action->purchase_kickoff_meeting_id)
            ->where('tenant_id', $v->tenant_id)->where('purchase_vendor_id', $v->id)->exists();
        abort_unless($ok, 404, 'Action not found');

        $data = $request->validate(['note' => 'required|string|max:2000']);
        $existing = trim((string) $action->remark);
        $stamp = '[Vendor] '.$data['note'];
        $action->update(['remark' => $existing === '' ? $stamp : $existing."\n".$stamp]);

        return response()->json($action->fresh());
    }

    /* ── Upload training certificates ───────────────────────────────────── */

    public function uploadCertificate(Request $request, PurchaseWorker $worker)
    {
        $v = $this->vendor($request);
        abort_unless((int) $worker->tenant_id === (int) $v->tenant_id && (int) $worker->purchase_vendor_id === (int) $v->id, 404, 'Worker not found');

        $data = $request->validate([
            'title'       => 'required|string|max:150',
            'expiry_date' => 'nullable|date',
            'certificate' => 'required|file|mimes:pdf,png,jpg,jpeg|max:10240',
        ]);

        $path = $request->file('certificate')->store('purchase/worker-certs', 'public');
        $row = PurchaseWorkerTraining::create([
            'tenant_id' => $v->tenant_id, 'purchase_vendor_id' => $v->id, 'purchase_worker_id' => $worker->id,
            'title' => $data['title'], 'expiry_date' => $data['expiry_date'] ?? null,
            'status' => 'Completed', 'file_path' => $path,
        ]);

        return response()->json($row->fresh(), 201);
    }

    /* ── Ownership guard (purchase_vendor_id) ───────────────────────────── */

    private function assertOwned(Request $request, $model): void
    {
        $v = $this->vendor($request);
        abort_unless($model && (int) ($model->purchase_vendor_id ?? 0) === (int) $v->id, 404, 'Record not found');
    }
}
