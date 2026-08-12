<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\StorePurchaseWorkerRequest;
use App\Http\Requests\Purchase\UpdatePurchaseWorkerRequest;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerPpeIssue;
use App\Services\Purchase\PurchasePpeService;
use App\Services\Purchase\PurchaseWorkforceService;
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
            'fitness_status' => 'required|in:Fit,Unfit,Pending',
            'blood_group'    => 'nullable|string|max:10',
            'remarks'        => 'nullable|string|max:500',
        ]);

        return response()->json($this->service->saveMedical($w, $data), 201);
    }

    public function saveTraining(Request $request, int $worker)
    {
        $w = $this->owned($request, $worker);
        $data = $request->validate([
            'title'         => 'required|string|max:150',
            'training_date' => 'nullable|date',
            'expiry_date'   => 'nullable|date',
            'status'        => 'required|in:Completed,Pending,Expired',
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

    /* ── scoping ─────────────────────────────────────────────────────────── */

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

    /** One of the caller's own workers' PPE history. */
    public function workerPpe(Request $request, int $worker, PurchasePpeService $ppe)
    {
        return response()->json($ppe->forWorker($this->owned($request, $worker))->values());
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
     */
    public function issueWorkerPpe(Request $request, int $worker, PurchasePpeService $ppe)
    {
        $data = $request->validate([
            'inventory_item_id' => 'required|integer',
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
