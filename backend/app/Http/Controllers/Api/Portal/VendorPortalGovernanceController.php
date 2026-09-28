<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvCapa;
use App\Models\Tpv\TpvNcr;
use App\Models\Tpv\TpvPpeRequirement;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerCompetency;
use App\Models\Tpv\TpvWorkerTraining;
use App\Models\Shared\KickoffMeeting;
use App\Models\Shared\KickoffMomItem;
use App\Models\Vendor\Vendor;
use App\Services\Shared\KickoffMeetingService;
use App\Services\Shared\MeetingAttendanceGate;
use App\Services\Tpv\TpvApprovalService;
use App\Support\Shared\KickoffStatus;
use App\Support\Shared\MomApprovalStatus;
use App\Support\Shared\VendorMomView;
use App\Support\RichText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * §32 Vendor Portal — the governance-response half. Everything here is scoped to
 * the ambient portalVendor the vendor.portal middleware resolved; a vendor can
 * only ever see and act on its own governance items. Read + respond only — the
 * vendor never approves/verifies/closes (that authority stays admin-side).
 */
class VendorPortalGovernanceController extends Controller
{
    use ResolvesPortalVendor;

    public function __construct(
        private TpvApprovalService $approvals,
        // Required, not nullable-with-a-default: the container silently skips a
        // parameter that has one, and every call through it would be a no-op.
        private KickoffMeetingService $kickoffService,
    ) {
    }

    /* ── NCRs — view + respond (§32) ────────────────────────────────────── */

    public function ncrs(Request $request)
    {
        $vendor = $this->portalVendor($request);

        return response()->json([
            'data' => TpvNcr::where('tenant_id', $vendor->tenant_id)->where('vendor_id', $vendor->id)
                ->latest('id')->get(),
            'statuses' => TpvNcr::STATUSES,
        ]);
    }

    public function respondNcr(Request $request, TpvNcr $ncr)
    {
        $this->assertOwned($request, $ncr, 'NCR');

        $data = $request->validate(['response' => 'required|string|max:5000']);

        // The vendor's response advances the NCR to the Response state; the
        // corrective action / verification / closure stay admin-side.
        $ncr->update([
            'response' => $data['response'],
            'status'   => in_array($ncr->status, ['Raised', 'Assigned'], true) ? 'Response' : $ncr->status,
        ]);

        return response()->json($ncr->fresh());
    }

    /* ── CAPAs — view + submit evidence (§32) ───────────────────────────── */

    public function capas(Request $request)
    {
        $vendor = $this->portalVendor($request);

        return response()->json([
            'data' => TpvCapa::where('tenant_id', $vendor->tenant_id)->where('vendor_id', $vendor->id)
                ->latest('id')->get(),
        ]);
    }

    public function submitCapaEvidence(Request $request, TpvCapa $capa)
    {
        $this->assertOwned($request, $capa, 'CAPA');

        $data = $request->validate([
            'note'     => 'nullable|string|max:2000',
            'evidence' => 'nullable|file|mimes:pdf,doc,docx,png,jpg,jpeg|max:10240',
            'evidence_data' => 'nullable|string',   // base64 fallback
        ]);

        $path = $capa->evidence_path;
        if ($request->hasFile('evidence')) {
            $path = $request->file('evidence')->store('tpv/capa-evidence', 'public');
        } elseif (! empty($data['evidence_data']) && str_contains($data['evidence_data'], 'base64,')) {
            $binary = base64_decode(explode('base64,', $data['evidence_data'])[1]);
            $path   = 'tpv/capa-evidence/capa_'.$capa->id.'_'.uniqid().'.dat';
            Storage::disk('public')->put($path, $binary);
        }

        $capa->update([
            'evidence_path'      => $path,
            'verification_notes' => $data['note'] ?? $capa->verification_notes,
            // The vendor marks their corrective action Done; admin still Verifies.
            'status'             => ($path && ! empty($capa->assigned_to) && ! in_array($capa->status, ['Done', 'Verified'], true))
                ? 'Done' : $capa->status,
        ]);

        return response()->json($capa->fresh());
    }

    /* ── Request approvals + extensions (§32) ───────────────────────────── */

    public function requestApproval(Request $request)
    {
        $vendor = $this->portalVendor($request);

        $data = $request->validate([
            'title'       => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'priority'    => 'nullable|in:Low,Medium,High',
        ]);

        $approval = $this->approvals->raise([
            'approval_type' => \App\Support\Tpv\ApprovalType::OTHER,
            'subject_type'  => Vendor::class,
            'subject_id'    => $vendor->id,
            'vendor_id'     => $vendor->id,
            'title'         => $data['title'],
            'description'   => $data['description'] ?? null,
            'priority'      => $data['priority'] ?? 'Medium',
            'meta'          => ['origin' => 'vendor_portal'],
        ], (int) $vendor->tenant_id, (int) ($vendor->user_id ?? 0));

        return response()->json($approval, 201);
    }

    public function requestExtension(Request $request)
    {
        $vendor = $this->portalVendor($request);

        $data = $request->validate([
            'reason'       => 'required|string|max:2000',
            'requested_to' => 'nullable|date',
            'subject'      => 'nullable|string|max:160',   // what the extension is for
        ]);

        $approval = $this->approvals->raise([
            'approval_type' => \App\Support\Tpv\ApprovalType::EXTENSION,
            'subject_type'  => Vendor::class,
            'subject_id'    => $vendor->id,
            'vendor_id'     => $vendor->id,
            'title'         => 'Extension request'.(! empty($data['subject']) ? ': '.$data['subject'] : '').' — '.$vendor->company_name,
            'description'   => $data['reason'],
            'meta'          => ['origin' => 'vendor_portal', 'requested_to' => $data['requested_to'] ?? null],
        ], (int) $vendor->tenant_id, (int) ($vendor->user_id ?? 0));

        return response()->json($approval, 201);
    }

    /* ── Meetings, MOM + actions (§32) ──────────────────────────────────── */

    public function meetings(Request $request)
    {
        $vendor = $this->portalVendor($request);

        // kickoffable_type stores the MODEL CLASS (App\Models\Vendor\Vendor), not
        // the short subject key. Comparing it to 'vendor' matched no row ever, so
        // this tab showed the vendor an empty list however many meetings they
        // had — and assertMeetingOwned below refused every one of them.
        $meetings = KickoffMeeting::where('tenant_id', $vendor->tenant_id)
            ->where('kickoffable_type', Vendor::class)->where('kickoffable_id', $vendor->id)
            // Never expose unpublished drafts to the vendor.
            ->where('status', '!=', KickoffStatus::DRAFT)
            ->with('attendees:id,kickoff_meeting_id,name,role')
            // The agenda, so the meeting page is worth opening — that is the
            // whole trade being offered in place of a link in the e-mail. Only
            // the agenda columns: `discussion` and `decision` on the same table
            // are the MINUTES, which the vendor may not see until they are
            // approved and distributed.
            ->with(['agendaItems' => fn ($q) => $q->select(
                'id', 'kickoff_meeting_id', 'item', 'description', 'owner_names', 'duration_minutes', 'sort_order',
            )])
            ->latest('scheduled_at')
            // tenant_id, end_at and duration_minutes are not shown as fields —
            // they are what the appended timing attributes are derived FROM.
            // Without them every meeting reached the portal with no end and no
            // tenant clock, so none could ever read as expired.
            ->get(['id', 'tenant_id', 'reference', 'title', 'meeting_type', 'status', 'scheduled_at', 'end_at', 'duration_minutes', 'mode', 'location', 'meeting_platform', 'meeting_link', 'agenda', 'mom_status', 'mom_path', 'kickoffable_type', 'kickoffable_id']);

        // The minutes are only the vendor's to see once approved+distributed. Add
        // a flag the portal reads, and hide mom_path until then so the "download"
        // control can't reach an unapproved document.
        $gate = app(MeetingAttendanceGate::class);

        $meetings->each(function ($m) use ($gate, $vendor) {
            $available = MomApprovalStatus::isDistributable($m->mom_status);
            $m->setAttribute('mom_available', $available);
            if (! $available) {
                $m->setAttribute('mom_path', null);
            }
            // The join link is not in this payload until the vendor has marked
            // attendance — see MeetingAttendanceGate. Withheld here rather than
            // hidden in the page, because a link sitting in the JSON is readable
            // whatever the page chooses to draw.
            foreach ($gate->stateFor($m, $vendor) as $field => $value) {
                $m->setAttribute($field, $value);
            }
        });

        return response()->json(['data' => $meetings]);
    }

    public function meetingMom(Request $request, KickoffMeeting $kickoffMeeting)
    {
        $this->assertMeetingOwned($request, $kickoffMeeting);

        // Point 8: the vendor sees the minutes only after they are approved and
        // distributed — never a draft or an in-review set.
        abort_unless(
            MomApprovalStatus::isDistributable($kickoffMeeting->mom_status),
            403,
            'These minutes are not yet available.'
        );

        // Stamped HERE rather than where an administrator opens the document.
        // "Viewed" on the distribution tracker is a claim about the recipient,
        // and this is the only place the recipient is the one reading.
        $this->kickoffService->markMomViewed($kickoffMeeting);

        // One agreed shape for both portals — see VendorMomView for what the
        // two engines each used to send instead, and what got lost on the way.
        return response()->json(VendorMomView::for(
            $kickoffMeeting,
            (bool) $kickoffMeeting->mom_path,
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
    public function markAttendance(Request $request, KickoffMeeting $kickoffMeeting, MeetingAttendanceGate $gate)
    {
        $this->assertMeetingOwned($request, $kickoffMeeting);

        $vendor = $this->portalVendor($request);

        /*
         * A meeting scheduled FOR a vendor commonly has no roster row for that
         * vendor — the invitation code adds them separately for exactly this
         * reason. Without a row there is nowhere for the attendance to land, and
         * the vendor would be locked out of their own meeting for ever. The
         * identity is not guessed: it is the vendor record this request already
         * authenticated as.
         */
        return response()->json($gate->mark($kickoffMeeting, $vendor, $request, [
            'name' => $vendor->company_name ?: $vendor->name,
            'email' => $vendor->email,
            'organisation' => $vendor->company_name ?: $vendor->name,
            'side' => 'external',
            // Which column of the four-column attendance sheet to seat them in.
            // A vendor seating itself is by definition the Third-Party Vendor
            // column; without this they land in the grid's "not yet placed" row
            // on a meeting held for them. See MeetingPartyDirectory.
            'party' => \App\Services\Shared\MeetingPartyDirectory::TPV,
        ]));
    }

    /**
     * The minutes document itself.
     *
     * The whole approve-then-distribute workflow exists to put this file in the
     * vendor's hands, and there was no way for them to open it: the only route
     * that served it sat behind role:admin,staff. The vendor was told their
     * minutes had been distributed and given no means to read them.
     *
     * Same gate as the minutes content — approved and distributed, and their
     * own meeting.
     */
    public function meetingMomFile(Request $request, KickoffMeeting $kickoffMeeting)
    {
        $this->assertMeetingOwned($request, $kickoffMeeting);

        abort_unless(
            MomApprovalStatus::isDistributable($kickoffMeeting->mom_status),
            403,
            'These minutes are not yet available.'
        );
        abort_unless(
            $kickoffMeeting->mom_path && Storage::disk('kickoff_docs')->exists($kickoffMeeting->mom_path),
            404,
            'No minutes document has been issued for this meeting.'
        );

        $this->kickoffService->markMomViewed($kickoffMeeting);

        return Storage::disk('kickoff_docs')->response(
            $kickoffMeeting->mom_path,
            'Minutes-'.($kickoffMeeting->meeting_no ?: $kickoffMeeting->id).'.pdf',
            ['Content-Type' => 'application/pdf'],
            $request->boolean('download') ? 'attachment' : 'inline',
        );
    }

    /**
     * Download one of a meeting's labelled documents. Available to the owning
     * vendor only once the minutes are approved+distributed (same gate as the
     * MoM itself).
     */
    public function meetingDocument(Request $request, KickoffMeeting $kickoffMeeting, \App\Models\Shared\KickoffMeetingDocument $document)
    {
        $this->assertMeetingOwned($request, $kickoffMeeting);
        abort_unless(
            MomApprovalStatus::isDistributable($kickoffMeeting->mom_status),
            403,
            'These documents are not yet available.'
        );
        abort_unless((int) $document->kickoff_meeting_id === (int) $kickoffMeeting->id, 404, 'Document not found.');
        abort_unless(
            $document->path && Storage::disk('kickoff_docs')->exists($document->path),
            404,
            'Document file is missing.'
        );

        return Storage::disk('kickoff_docs')->response(
            $document->path,
            $document->original_name,
            [],
            $request->boolean('inline') ? 'inline' : 'attachment'
        );
    }

    public function actions(Request $request)
    {
        $vendor = $this->portalVendor($request);

        $meetingIds = KickoffMeeting::where('tenant_id', $vendor->tenant_id)
            ->where('kickoffable_type', Vendor::class)->where('kickoffable_id', $vendor->id)->pluck('id');

        $actions = KickoffMomItem::where('tenant_id', $vendor->tenant_id)
            ->whereIn('kickoff_meeting_id', $meetingIds)
            ->with('responsible:id,name')
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

    public function respondAction(Request $request, KickoffMomItem $momItem)
    {
        $vendor = $this->portalVendor($request);
        $this->assertActionOwned($request, $momItem);

        $data = $request->validate(['note' => 'required|string|max:2000']);

        // The vendor adds progress; the status transition + verification stay
        // admin-side. Their note is appended to any existing remark.
        $existing = trim((string) $momItem->remark);
        $stamp = '[Vendor] '.$data['note'];
        $momItem->update(['remark' => $existing === '' ? $stamp : $existing."\n".$stamp]);

        return response()->json($momItem->fresh());
    }

    /* ── PPE requirement matrix — view (§32) ────────────────────────────── */

    public function ppeMatrix(Request $request)
    {
        $vendor = $this->portalVendor($request);

        $rules = TpvPpeRequirement::where('tenant_id', $vendor->tenant_id)
            ->where('is_active', true)
            ->with('product:id,name,sku')
            ->orderBy('scope_type')->orderBy('scope_value')
            ->get()
            ->map(fn (TpvPpeRequirement $r) => [
                'scope_type'  => $r->scope_type,
                'scope_value' => $r->scope_value,
                'hazard'      => $r->hazard,
                'activity'    => $r->activity,
                'ppe_class'   => $r->ppe_class ?? 'mandatory',
                'condition'   => $r->condition,
                'product'     => $r->product?->name,
                'qty'         => $r->qty,
                'replacement_frequency_days' => $r->replacement_frequency_days,
                'verification_required'      => (bool) $r->verification_required,
            ]);

        return response()->json(['rules' => $rules, 'classes' => TpvPpeRequirement::CLASSES]);
    }

    /* ── Upload training / competency certificates (§32) ────────────────── */

    public function uploadCertificate(Request $request, TpvWorker $worker)
    {
        $this->assertOwned($request, $worker, 'Worker');

        $data = $request->validate([
            'kind'        => ['required', Rule::in(['training', 'competency'])],
            'name'        => 'required|string|max:150',
            'category'    => 'nullable|string|max:80',
            'valid_until' => 'nullable|date',
            'certificate' => 'required|file|mimes:pdf,png,jpg,jpeg|max:10240',
        ]);

        $path = $request->file('certificate')->store('tpv/worker-certs', 'public');

        if ($data['kind'] === 'training') {
            $row = TpvWorkerTraining::create([
                'tenant_id' => $worker->tenant_id, 'tpv_worker_id' => $worker->id,
                'training_type' => in_array($data['category'] ?? null, TpvWorkerTraining::TYPES, true) ? $data['category'] : 'Job_Specific',
                'provider' => $data['name'], 'valid_until' => $data['valid_until'] ?? null,
                'certificate_path' => $path, 'passed' => true,
            ]);
        } else {
            $row = TpvWorkerCompetency::create([
                'tenant_id' => $worker->tenant_id, 'tpv_worker_id' => $worker->id,
                'name' => $data['name'],
                'category' => in_array($data['category'] ?? null, TpvWorkerCompetency::CATEGORIES, true) ? $data['category'] : 'Certification',
                'valid_until' => $data['valid_until'] ?? null, 'evidence_path' => $path,
            ]);
        }

        return response()->json($row->fresh(), 201);
    }

    /* ── Ownership guards ───────────────────────────────────────────────── */

    private function assertMeetingOwned(Request $request, KickoffMeeting $m): void
    {
        $vendor = $this->portalVendor($request);
        abort_unless(
            (int) $m->tenant_id === (int) $vendor->tenant_id
                && $m->kickoffable_type === Vendor::class && (int) $m->kickoffable_id === (int) $vendor->id,
            404, 'Meeting not found'
        );
    }

    private function assertActionOwned(Request $request, KickoffMomItem $item): void
    {
        $vendor = $this->portalVendor($request);
        $ok = KickoffMeeting::where('id', $item->kickoff_meeting_id)
            ->where('tenant_id', $vendor->tenant_id)
            ->where('kickoffable_type', Vendor::class)->where('kickoffable_id', $vendor->id)->exists();
        abort_unless($ok, 404, 'Action not found');
    }
}
