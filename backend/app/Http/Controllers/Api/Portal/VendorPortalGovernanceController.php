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
use App\Services\Tpv\TpvApprovalService;
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

    public function __construct(private TpvApprovalService $approvals)
    {
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

        $meetings = KickoffMeeting::where('tenant_id', $vendor->tenant_id)
            ->where('kickoffable_type', 'vendor')->where('kickoffable_id', $vendor->id)
            ->with('attendees:id,kickoff_meeting_id,name,role')
            ->latest('scheduled_at')
            ->get(['id', 'reference', 'title', 'meeting_type', 'status', 'scheduled_at', 'mode', 'location', 'mom_path', 'kickoffable_type', 'kickoffable_id']);

        return response()->json(['data' => $meetings]);
    }

    public function meetingMom(Request $request, KickoffMeeting $kickoffMeeting)
    {
        $this->assertMeetingOwned($request, $kickoffMeeting);

        return response()->json($kickoffMeeting->load([
            'agendaItems', 'momItems.responsible:id,name', 'decisions', 'issues',
        ]));
    }

    public function actions(Request $request)
    {
        $vendor = $this->portalVendor($request);

        $meetingIds = KickoffMeeting::where('tenant_id', $vendor->tenant_id)
            ->where('kickoffable_type', 'vendor')->where('kickoffable_id', $vendor->id)->pluck('id');

        $actions = KickoffMomItem::where('tenant_id', $vendor->tenant_id)
            ->whereIn('kickoff_meeting_id', $meetingIds)
            ->with('responsible:id,name')
            ->latest('id')->get();

        return response()->json(['data' => $actions]);
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
                && $m->kickoffable_type === 'vendor' && (int) $m->kickoffable_id === (int) $vendor->id,
            404, 'Meeting not found'
        );
    }

    private function assertActionOwned(Request $request, KickoffMomItem $item): void
    {
        $vendor = $this->portalVendor($request);
        $ok = KickoffMeeting::where('id', $item->kickoff_meeting_id)
            ->where('tenant_id', $vendor->tenant_id)
            ->where('kickoffable_type', 'vendor')->where('kickoffable_id', $vendor->id)->exists();
        abort_unless($ok, 404, 'Action not found');
    }
}
