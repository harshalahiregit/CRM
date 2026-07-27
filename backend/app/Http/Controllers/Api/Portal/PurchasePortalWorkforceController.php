<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\StorePurchaseWorkerRequest;
use App\Http\Requests\Purchase\UpdatePurchaseWorkerRequest;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
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
        abort_unless($vendor instanceof PurchaseVendor, 401, 'Unauthenticated');

        return $vendor;
    }

    /** Resolve a worker owned by the caller's vendor, or 404 (existence-hiding). */
    private function owned(Request $request, int $workerId): PurchaseWorker
    {
        $worker = $this->service->find($this->vendor($request), $workerId);
        abort_unless($worker, 404, 'Worker not found');

        return $worker;
    }
}
