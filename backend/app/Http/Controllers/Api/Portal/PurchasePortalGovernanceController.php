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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * §32 Purchase Vendor Portal — the governance-response half, mirroring the TPV
 * portal on the Purchase-owned models (separate DB/tables). The caller IS a
 * PurchaseVendor (Sanctum token); everything is scoped to it. Read + respond
 * only — approve/verify/close stay admin-side. (Purchase has no PPE requirement
 * matrix, so that TPV-only capability is deliberately absent.)
 */
class PurchasePortalGovernanceController extends Controller
{
    public function __construct(private PurchaseApprovalRequestService $approvals)
    {
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
            ->latest('id')->get();

        // The minutes are the vendor's to see only once approved+distributed.
        $meetings->each(function ($m) {
            $m->setAttribute('mom_available', \App\Support\Purchase\PurchaseMomApprovalStatus::isDistributable($m->mom_status));
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

        return response()->json($kickoff->load(['agendaItems', 'actionItems', 'momDecisions', 'momIssues', 'documents']));
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

        return response()->json(['data' => $actions]);
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
