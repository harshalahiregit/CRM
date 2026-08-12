<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerPpeIssue;
use App\Services\Purchase\PurchasePpeService;
use App\Services\Purchase\PurchaseWorkforceService;
use Illuminate\Http\Request;

/**
 * Admin/staff view of the Purchase workforce.
 *
 * The vendor supplies the evidence — profile, medical, training, induction, PPE —
 * and this is where the site reviews it and decides who may walk in. Scoped by
 * TENANT rather than by vendor, which is the difference between this and the
 * portal controller: an admin legitimately sees every vendor's workers.
 *
 * Badge activation is deliberately here and nowhere else.
 */
class PurchaseWorkforceAdminController extends Controller
{
    public function __construct(private PurchaseWorkforceService $service)
    {
    }

    /** Every Purchase worker in the tenant, optionally filtered to one vendor. */
    public function index(Request $request)
    {
        $q = PurchaseWorker::forTenant($request->user()->tenant_id)
            ->with('vendor:id,company_name,purchase_vendor_code');

        if ($request->filled('vendor_id')) {
            $q->where('purchase_vendor_id', (int) $request->input('vendor_id'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }

        return response()->json(['data' => $q->latest()->get()]);
    }

    public function show(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json([
            'worker'    => $worker->load(['vendor:id,company_name', 'documents', 'medicals', 'trainings', 'inductions']),
            'readiness' => $this->service->readiness($worker),
            'badge'     => [
                'badge_number'      => $worker->badge_number,
                'badge_issued_at'   => optional($worker->badge_issued_at)->toIso8601String(),
                'badge_valid_until' => optional($worker->badge_valid_until)->toDateString(),
                'activated'         => (bool) $worker->badge_number,
            ],
        ]);
    }

    /** A worker's PPE, from the central ledger. */
    public function ppe(Request $request, PurchaseWorker $worker, PurchasePpeService $ppe)
    {
        $this->assertTenant($request, $worker);

        return response()->json([
            'issues'     => $ppe->forWorker($worker)->values(),
            'compliance' => $ppe->complianceFor($worker),
        ]);
    }

    /**
     * Step 5 — activate the worker and issue its entry badge. ADMIN ONLY.
     *
     * The route carries role:admin, so staff and vendors never reach this. The
     * service re-checks readiness, so the badge cannot outrun the evidence.
     */
    public function activate(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker);

        $data = $request->validate(['valid_until' => 'nullable|date']);

        return response()->json($this->service->activateBadge($worker, $request->user(), $data));
    }

    /**
     * Admin-side PPE return/write-off, for kit handed back at the gate rather
     * than through the vendor's portal.
     */
    public function returnPpe(Request $request, PurchaseWorkerPpeIssue $issue, PurchasePpeService $ppe)
    {
        abort_unless(
            (int) $issue->tenant_id === (int) $request->user()->tenant_id,
            404,
            'PPE issue not found'
        );

        $data = $request->validate([
            'condition' => 'nullable|in:returned,lost,damaged',
            'qty'       => 'nullable|numeric|min:0.001',
            'notes'     => 'nullable|string|max:2000',
        ]);

        return response()->json($ppe->returnIssue($issue, $data, $request->user()));
    }

    /** The gate decision for a scanned worker. */
    public function gate(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json($this->service->gateDecision($worker));
    }

    /** 404, not 403 — the same existence-hiding the Purchase portal uses. */
    private function assertTenant(Request $request, PurchaseWorker $worker): void
    {
        abort_unless(
            (int) $worker->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Worker not found'
        );
    }
}
