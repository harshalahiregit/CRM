<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseActivity;
use App\Models\Purchase\PurchaseWorkPackage;
use App\Models\Purchase\PurchaseWorker;
use App\Services\Purchase\PurchaseWorkPackageService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Purchase work packages, their activities, and the work-authorisation check —
 * mirror of TPV's WorkPackage + WorkAuthorization controllers.
 *
 * Authorisation is read-only and derived per request: it never writes a flag,
 * so a worker whose medical lapsed overnight stops being authorised overnight
 * rather than when something remembers to clear a cached value.
 */
class PurchaseWorkPackageController extends Controller
{
    public function __construct(private PurchaseWorkPackageService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->list(
                (int) $request->user()->tenant_id,
                $request->only(['status', 'vendor_id'])
            ),
        ]);
    }

    public function show(Request $request, PurchaseWorkPackage $workPackage)
    {
        $this->assertTenant($request, $workPackage->tenant_id);

        return response()->json($workPackage->load(['activities', 'vendor:id,company_name', 'workers:id,full_name,worker_code,work_package_id']));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'purchase_vendor_id' => 'nullable|integer',
            'project_id'         => 'nullable|integer',
            'contract_id'        => 'nullable|integer',
            'name'               => 'required|string|max:190',
            'description'        => 'nullable|string|max:5000',
            'scope'              => 'nullable|string|max:5000',
            'location'           => 'nullable|string|max:190',
            'start_date'         => 'nullable|date',
            'end_date'           => 'nullable|date|after_or_equal:start_date',
            'status'             => ['nullable', Rule::in(PurchaseWorkPackage::STATUSES)],
            'notes'              => 'nullable|string|max:5000',
        ]);

        return response()->json(
            $this->service->create((int) $request->user()->tenant_id, $data, $request->user()->id),
            201
        );
    }

    public function update(Request $request, PurchaseWorkPackage $workPackage)
    {
        $this->assertTenant($request, $workPackage->tenant_id);

        $data = $request->validate([
            'purchase_vendor_id' => 'nullable|integer',
            'name'               => 'sometimes|required|string|max:190',
            'description'        => 'nullable|string|max:5000',
            'scope'              => 'nullable|string|max:5000',
            'location'           => 'nullable|string|max:190',
            'start_date'         => 'nullable|date',
            'end_date'           => 'nullable|date|after_or_equal:start_date',
            'status'             => ['nullable', Rule::in(PurchaseWorkPackage::STATUSES)],
            'notes'              => 'nullable|string|max:5000',
        ]);

        return response()->json($this->service->update($workPackage, $data));
    }

    public function destroy(Request $request, PurchaseWorkPackage $workPackage)
    {
        $this->assertTenant($request, $workPackage->tenant_id);
        $this->service->delete($workPackage);

        return response()->json(['message' => 'Work package removed']);
    }

    /* ── Activities ──────────────────────────────────────────────────────── */

    public function addActivity(Request $request, PurchaseWorkPackage $workPackage)
    {
        $this->assertTenant($request, $workPackage->tenant_id);

        $data = $request->validate([
            'name'                => 'required|string|max:190',
            'description'         => 'nullable|string|max:5000',
            'required_competency' => 'nullable|string|max:120',
            'status'              => 'nullable|string|max:30',
            'requires_permit'     => 'nullable|boolean',
            'permit_type'         => 'nullable|string|max:60',
            'hazard'              => 'nullable|string|max:500',
        ]);

        return response()->json($this->service->addActivity($workPackage, $data), 201);
    }

    public function updateActivity(Request $request, PurchaseActivity $activity)
    {
        $this->assertTenant($request, $activity->tenant_id);

        $data = $request->validate([
            'name'                => 'sometimes|required|string|max:190',
            'description'         => 'nullable|string|max:5000',
            'required_competency' => 'nullable|string|max:120',
            'status'              => 'nullable|string|max:30',
            'sort_order'          => 'nullable|integer|min:0',
            'requires_permit'     => 'nullable|boolean',
            'permit_type'         => 'nullable|string|max:60',
            'hazard'              => 'nullable|string|max:500',
        ]);

        return response()->json($this->service->updateActivity($activity, $data));
    }

    public function deleteActivity(Request $request, PurchaseActivity $activity)
    {
        $this->assertTenant($request, $activity->tenant_id);
        $this->service->deleteActivity($activity);

        return response()->json(['message' => 'Activity removed']);
    }

    /* ── Work authorisation ──────────────────────────────────────────────── */

    /** One worker, optionally against a specific activity. */
    public function authorize(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker->tenant_id);

        $activity = null;
        if ($request->filled('activity_id')) {
            $activity = PurchaseActivity::where('tenant_id', $request->user()->tenant_id)
                ->find($request->query('activity_id'));
        }

        return response()->json($this->service->authorize($worker, $activity));
    }

    /** The whole roster, with each worker's authorisation. */
    public function roster(Request $request)
    {
        return response()->json($this->service->roster(
            (int) $request->user()->tenant_id,
            $request->only(['work_package_id', 'vendor_id', 'activity_id'])
        ));
    }

    /** Assign a worker to a package (null clears it). */
    public function assignWorker(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker->tenant_id);

        $data = $request->validate(['work_package_id' => 'nullable|integer']);

        return response()->json($this->service->assignWorker($worker, $data['work_package_id'] ?? null));
    }

    private function assertTenant(Request $request, $tenantId): void
    {
        abort_unless((int) $tenantId === (int) $request->user()->tenant_id, 404, 'Not found');
    }
}
