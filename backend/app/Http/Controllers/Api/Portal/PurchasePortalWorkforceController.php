<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\SaveGroupInductionRequest;
use App\Http\Requests\Purchase\StorePurchaseWorkerRequest;
use App\Http\Requests\Purchase\UpdatePurchaseWorkerRequest;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerPpeIssue;
use App\Services\Purchase\PurchaseGateService;
use App\Services\Purchase\PurchasePpeService;
use App\Services\Purchase\PurchaseWorkforceService;
use App\Support\Medical\DoctorOptions;
use Illuminate\Http\Request;

/**
 * Purchase Vendor Portal — workforce (workers + medical/training/induction/
 * documents/readiness). Purchase-owned; every action is scoped to the
 * authenticated PurchaseVendor ($request->user()). Worker ids are validated
 * against ownership (404 existence-hiding) — never a purchase_vendor_id from the
 * request body, never cross-vendor access.
 */
class PurchasePortalWorkforceController extends Controller
{
    public function __construct(private PurchaseWorkforceService $service)
    {
    }

    public function index(Request $request)
    {
        $vendor = $this->vendor($request);

        return response()->json([
            'workers' => $this->service->list($vendor, $request->only(['status', 'search'])),
            'summary' => $this->service->summary($vendor),
        ]);
    }

    public function summary(Request $request)
    {
        return response()->json($this->service->summary($this->vendor($request)));
    }

    public function store(StorePurchaseWorkerRequest $request)
    {
        $worker = $this->service->create($this->vendor($request), $request->validated());

        return response()->json($this->service->workerPayload($worker), 201);
    }

    public function show(Request $request, int $worker)
    {
        return response()->json($this->service->workerPayload($this->owned($request, $worker)));
    }

    public function update(UpdatePurchaseWorkerRequest $request, int $worker)
    {
        $updated = $this->service->update($this->owned($request, $worker), $request->validated());

        return response()->json($this->service->workerPayload($updated));
    }

    public function destroy(Request $request, int $worker)
    {
        $this->service->delete($this->owned($request, $worker));

        return response()->json(['message' => 'Deleted']);
    }

    public function readiness(Request $request, int $worker)
    {
        return response()->json($this->service->readiness($this->owned($request, $worker)));
    }

    public function uploadDocument(Request $request, int $worker)
    {
        $w = $this->owned($request, $worker);
        $data = $request->validate([
            'type' => 'required|string|max:80',
            'file' => 'required|file|max:8192',
        ]);

        return response()->json($this->service->addDocument($w, $data['type'], $request->file('file')), 201);
    }

    public function saveMedical(Request $request, int $worker)
    {
        $w = $this->owned($request, $worker);
        $data = $request->validate([
            'exam_date'      => 'nullable|date',
            'expiry_date'    => 'nullable|date',
            // TPV-parity fitness states — 'Fit_With_Restrictions' passes readiness.
            'fitness_status' => 'required|in:Fit,Fit_With_Restrictions,Unfit,Pending,Expired',
            'blood_group'    => 'nullable|string|max:10',
            'remarks'        => 'nullable|string|max:500',
            // Depth (§16).
            'restrictions'   => 'nullable|string|max:1000',
            'examiner_name'  => 'nullable|string|max:150',
            'approved_by'    => 'nullable|integer',
            // Which in-house doctor was picked, if any. Licence and clinic are
            // looked up server-side from the directory, never sent from here.
            'doctor_user_id' => 'nullable|integer',
        ]);

        $data = DoctorOptions::applyTo($data, (int) $w->tenant_id, 'purchase');

        return response()->json($this->service->saveMedical($w, $data), 201);
    }

    public function saveTraining(Request $request, int $worker)
    {
        $w = $this->owned($request, $worker);
        $data = $request->validate([
            // title OR a typed training_type — at least one identifies the course.
            'title'         => 'required_without:training_type|nullable|string|max:150',
            'training_type' => ['nullable', \Illuminate\Validation\Rule::in(\App\Models\Purchase\PurchaseWorkerTraining::TYPES)],
            'provider'      => 'nullable|string|max:150',
            'training_date' => 'nullable|date',
            'expiry_date'   => 'nullable|date',
            // TPV-parity currency window.
            'valid_until'   => 'nullable|date',
            'status'        => 'required|in:Completed,Pending,Expired,Failed',
            'score'         => 'nullable|string|max:30',
            'remarks'       => 'nullable|string|max:500',
        ]);

        return response()->json($this->service->saveTraining($w, $data), 201);
    }

    public function saveInduction(Request $request, int $worker)
    {
        $w = $this->owned($request, $worker);
        $data = $request->validate([
            'induction_date' => 'nullable|date',
            'status'         => 'required|in:Completed,Pending',
            'conducted_by'   => 'nullable|string|max:150',
            'remarks'        => 'nullable|string|max:500',
        ]);

        return response()->json($this->service->saveInduction($w, $data), 201);
    }

    /**
     * One group session over the vendor's own workers; the trainer signs once.
     * The vendor comes from the token, so another vendor's worker id is
     * reported as "not found" and never written.
     */
    public function saveGroupInduction(SaveGroupInductionRequest $request)
    {
        $vendor = $this->vendor($request);
        $data   = $request->validated();
        $ids    = $data['worker_ids'];
        unset($data['worker_ids']);

        return response()->json($this->service->saveGroupInduction(
            (int) $vendor->tenant_id, (int) $vendor->id, $ids, $data
        ));
    }

    /* ── scoping ─────────────────────────────────────────────────────────── */

    /* ── Site gate — the vendor's own people, read-only ──────────────────── */

    /**
     * Attendance, the gate log and the on-site roster, for this vendor only.
     *
     * TPV's portal has shown these since it existed; Purchase built the whole
     * gate engine — two tables, a service, seven admin routes — and never exposed
     * any of it to the vendor, so the one party who needs to know whether their
     * own people got in could not see it.
     *
     * READ-ONLY on purpose. Recording a crossing is the security desk's act
     * (`PurchaseGateController@storeEvent`, admin-gated) and stays there: a
     * vendor that could write its own gate scans could manufacture attendance.
     *
     * The vendor id comes from the token, never the request, so these can only
     * ever narrow to the caller's own workers.
     */
    /**
     * Every training record across this vendor's own workers.
     *
     * The portal could WRITE a training (saveTraining, below) and had no way to
     * read one back, so a vendor could file a certificate and never see it
     * again — the same hole the admin side had on its vendor tab.
     */
    public function trainings(Request $request)
    {
        $vendor = $this->vendor($request);

        $rows = \App\Models\Purchase\PurchaseWorkerTraining::forTenant($vendor->tenant_id)
            ->whereIn('purchase_worker_id', $this->ownWorkerIds($vendor))
            ->with('worker:id,full_name,worker_code')
            ->orderByDesc('id')->limit(500)->get();

        return response()->json(['data' => $rows]);
    }

    /**
     * Safety strikes against this vendor's own workers — read-only.
     *
     * A vendor cannot issue or void one: three of them terminate a worker's site
     * access, so the authority to hand them out belongs with the site, not with
     * the company being struck. Seeing them is the point — a vendor who cannot
     * see a strike cannot act on it before the third one lands.
     */
    public function strikes(Request $request)
    {
        $vendor = $this->vendor($request);

        $query = \App\Models\Purchase\PurchaseSafetyStrike::forTenant($vendor->tenant_id)
            ->whereIn('purchase_worker_id', $this->ownWorkerIds($vendor))
            ->with(['worker:id,full_name,worker_code', 'issuer:id,name'])
            ->orderByDesc('occurred_at');

        if ($request->filled('severity')) {
            $query->where('severity', $request->query('severity'));
        }

        if ($request->boolean('active')) {
            $query->whereNull('voided_at');
        }

        return response()->json($query->get());
    }

    /** The ids this vendor owns — the scope everything above is narrowed to. */
    private function ownWorkerIds($vendor)
    {
        return \App\Models\Purchase\PurchaseWorker::forTenant($vendor->tenant_id)
            ->where('purchase_vendor_id', $vendor->id)->select('id');
    }

    public function gateStats(Request $request, PurchaseGateService $gate)
    {
        $vendor = $this->vendor($request);

        return response()->json(
            $gate->stats((int) $vendor->tenant_id, $request->query('date'), (int) $vendor->id)
        );
    }

    public function gateLog(Request $request, PurchaseGateService $gate)
    {
        $vendor = $this->vendor($request);

        return response()->json([
            'data' => $gate->log((int) $vendor->tenant_id, [
                // A supplied vendor_id is ignored — this one wins.
                'vendor_id' => $vendor->id,
                'worker_id' => $request->query('worker_id'),
                'decision' => $request->query('decision'),
                'from' => $request->query('from'),
                'to' => $request->query('to'),
                'limit' => $request->query('limit'),
            ]),
        ]);
    }

    public function onSite(Request $request, PurchaseGateService $gate)
    {
        $vendor = $this->vendor($request);

        return response()->json([
            'data' => $gate->onSite((int) $vendor->tenant_id, $request->query('date'), (int) $vendor->id),
        ]);
    }

    /** One of the vendor's own workers, day by day, with hours on site. */
    public function workerAttendance(Request $request, int $worker, PurchaseGateService $gate)
    {
        return response()->json(
            $gate->workerAttendance($this->owned($request, $worker), $request->only(['from', 'to']))
        );
    }

    /**
     * The vendor's own bulk import.
     *
     * No vendor_id is accepted: it comes from the token, so an import cannot be
     * aimed at somebody else's books.
     */
    public function uploadWorkers(Request $request)
    {
        $request->validate([
            'worker_file' => 'required|file|mimes:csv,xls,xlsx,txt,zip|max:20480',
        ]);

        return response()->json(
            $this->service->bulkUpload($request->file('worker_file'), $this->vendor($request))
        );
    }

    private function vendor(Request $request): PurchaseVendor
    {
        $vendor = $request->user();
        // #45 — 403, not 401: an authenticated identity of the WRONG TYPE is a
        // permission failure, and a 401 here would clear the caller's session.
        // EnsurePurchaseVendorPortalAccess already answers this case with 403;
        // this is the same answer for the defence-in-depth check.
        abort_unless($vendor instanceof PurchaseVendor, 403, 'This area is for Purchase vendor accounts only.');

        return $vendor;
    }

    /** Resolve a worker owned by the caller's vendor, or 404 (existence-hiding). */
    private function owned(Request $request, int $workerId): PurchaseWorker
    {
        $worker = $this->service->find($this->vendor($request), $workerId);
        abort_unless($worker, 404, 'Worker not found');

        return $worker;
    }

    /* ── Step 4 — PPE (vendor-owned, central Inventory) ──────────────────── */

    /** The PPE shelf. Tenant-wide, because there is one central store. */
    public function ppeCatalogue(Request $request, PurchasePpeService $ppe)
    {
        return response()->json($ppe->catalogue((int) $this->vendor($request)->tenant_id)->values());
    }

    /** Dashboard figures — availability tenant-wide, issuance this vendor's own. */
    public function ppeSummary(Request $request, PurchasePpeService $ppe)
    {
        $vendor = $this->vendor($request);

        return response()->json($ppe->summaryForVendor((int) $vendor->id, (int) $vendor->tenant_id));
    }

    /**
     * One of the caller's own workers' PPE — history and what they hold.
     *
     * The SAME contract as the admin route (PurchaseWorkforceAdminController::ppe),
     * because the SAME screen reads both: the worker wizard's PPE step reads
     * `issues` and `compliance`. This used to answer a bare array, so on the
     * portal `data.issues` was always undefined and every worker — including
     * ones admin had just kitted out — read "No PPE issued".
     */
    public function workerPpe(Request $request, int $worker, PurchasePpeService $ppe)
    {
        $w = $this->owned($request, $worker);

        return response()->json([
            'issues'     => $ppe->forWorker($w)->values(),
            'compliance' => $ppe->complianceFor($w),
        ]);
    }

    public function workerPpeCompliance(Request $request, int $worker, PurchasePpeService $ppe)
    {
        return response()->json($ppe->complianceFor($this->owned($request, $worker)));
    }

    /**
     * Issue PPE to one of the caller's own workers.
     *
     * warehouse_id is deliberately NOT accepted: a vendor picking a site would be
     * moving stock between warehouses. The service resolves the tenant default.
     *
     * Either a central Inventory item or one of the vendor's OWN PPE items
     * (`vendor_ppe_item_id`); the service checks the item is this vendor's.
     */
    public function issueWorkerPpe(Request $request, int $worker, PurchasePpeService $ppe)
    {
        $data = $request->validate([
            'inventory_item_id' => 'required_without:vendor_ppe_item_id|nullable|integer',
            'vendor_ppe_item_id' => 'required_without:inventory_item_id|nullable|integer|min:1',
            'qty'               => 'required|numeric|min:0.001',
            'size'              => 'nullable|string|max:40',
            'issued_date'       => 'nullable|date',
            'notes'             => 'nullable|string|max:2000',
        ]);

        return response()->json($ppe->issue($this->owned($request, $worker), $data), 201);
    }

    /**
     * Hand back / write off one of the caller's own issues.
     *
     * The issue is resolved through the worker the caller owns, so an issue id
     * belonging to another vendor reads as absent.
     */
    public function returnWorkerPpe(Request $request, int $issue, PurchasePpeService $ppe)
    {
        $data = $request->validate([
            'condition' => 'nullable|in:returned,lost,damaged',
            'qty'       => 'nullable|numeric|min:0.001',
            'notes'     => 'nullable|string|max:2000',
        ]);

        $vendor = $this->vendor($request);

        $row = PurchaseWorkerPpeIssue::query()
            ->where('tenant_id', $vendor->tenant_id)
            ->whereIn('purchase_worker_id',
                PurchaseWorker::where('purchase_vendor_id', $vendor->id)->select('id'))
            ->find($issue);

        abort_unless($row, 404, 'PPE issue not found');

        return response()->json($ppe->returnIssue($row, $data));
    }

    /* ── Step 5 — badge, READ ONLY for the vendor ────────────────────────── */

    /**
     * Badge status for one of the caller's own workers.
     *
     * Read-only by design: the vendor supplies the evidence, the site decides who
     * may walk in. Activation lives on the admin route.
     */
    public function workerBadge(Request $request, int $worker)
    {
        $w = $this->owned($request, $worker);

        return response()->json([
            'worker_id'         => $w->id,
            'status'            => $w->status,
            'current_step'      => (int) $w->current_step,
            'badge_number'      => $w->badge_number,
            'badge_issued_at'   => optional($w->badge_issued_at)->toIso8601String(),
            'badge_valid_until' => optional($w->badge_valid_until)->toDateString(),
            'activated'         => (bool) $w->badge_number,
            'readiness'         => $this->service->readiness($w),
        ]);
    }
}
