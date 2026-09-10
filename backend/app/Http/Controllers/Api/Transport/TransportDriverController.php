<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Transport\StoreTransportDocumentRequest;
use App\Http\Requests\Transport\StoreTransportDriverRequest;
use App\Http\Requests\Transport\TransitionTransportDriverRequest;
use App\Http\Requests\Transport\UpdateTransportDriverRequest;
use App\Services\Transport\DriverEligibilityService;
use App\Services\Transport\TransportAuditLogger;
use App\Services\Transport\TransportDocumentService;
use App\Services\Transport\TransportDriverService;
use App\Services\Transport\TransportPolicyService;
use App\Support\Transport\DriverAvailability;
use App\Support\Transport\DriverStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Driver master (SNG-TRN-004), FE half.
 *
 * Same shape as TransportVehicleController. Every write goes through
 * TransportDriverService so audit is written by construction, and every lookup
 * is tenant-filtered at the query.
 */
class TransportDriverController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TransportDriverService $drivers,
        private TransportDocumentService $documents,
        private DriverEligibilityService $eligibility,
        private TransportPolicyService $policies,
        private TransportAuditLogger $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status'       => 'nullable|string|max:40',
            'availability' => 'nullable|string|max:40',
            'search'       => 'nullable|string|max:120',
            'allocatable'  => 'nullable|boolean',
            'per_page'     => 'nullable|integer|min:1|max:200',
        ]);

        $page = $this->drivers
            ->list($request->user()->tenant_id, $filters)
            ->paginate((int) ($filters['per_page'] ?? 25));

        return $this->success($page, 'Drivers retrieved');
    }

    /**
     * Counts for the list's chips.
     *
     * Both axes, because the list filters on both and a single count would make
     * "3 blocked" ambiguous between a lifecycle bar and an operational state.
     */
    public function statusCounts(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $model = \App\Models\Transport\TransportDriver::class;

        $status = $model::forTenant($tenantId)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $avail  = $model::forTenant($tenantId)->selectRaw('availability, COUNT(*) as total')->groupBy('availability')->pluck('total', 'availability');

        return $this->success([
            'status'       => collect(DriverStatus::ALL)->mapWithKeys(fn ($s) => [$s => (int) ($status[$s] ?? 0)])->all(),
            'availability' => collect(DriverAvailability::ALL)->mapWithKeys(fn ($a) => [$a => (int) ($avail[$a] ?? 0)])->all(),
        ], 'Driver status counts retrieved');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $driver   = $this->drivers->find($id, $tenantId);
        $window   = $this->policies->int($tenantId, 'compliance.expiring_window_days');

        return $this->success([
            'driver'    => $driver,
            'documents' => $driver->documents()->orderByDesc('id')->get(),
            // CMP §23, derived. UX §35: "Never merely show Blocked. Show: Why?"
            // — the eligibility verdict carries the why.
            'compliance_status' => $driver->complianceStatus($window),
            'eligibility'       => $this->eligibility->evaluate($driver, null, $tenantId),
            'transitions' => [
                'status'       => DriverStatus::TRANSITIONS[$driver->status] ?? [],
                'availability' => DriverAvailability::TRANSITIONS[$driver->availability] ?? [],
            ],
            'audit' => $this->audit->forSubject($driver, $tenantId),
        ], 'Driver retrieved');
    }

    public function store(StoreTransportDriverRequest $request): JsonResponse
    {
        $driver = $this->drivers->create(
            $request->validated(), $request->user()->tenant_id, $request->user()
        );

        return $this->success($driver, 'Driver created', 201);
    }

    public function update(UpdateTransportDriverRequest $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $driver   = $this->drivers->find($id, $tenantId);

        return $this->success(
            $this->drivers->update($driver, $request->validated(), $tenantId, $request->user()),
            'Driver updated'
        );
    }

    /** One axis at a time — see TransitionTransportDriverRequest. */
    public function transition(TransitionTransportDriverRequest $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $driver   = $this->drivers->find($id, $tenantId);
        $data     = $request->validated();

        $updated = $data['axis'] === 'status'
            ? $this->drivers->transitionStatusTo($driver, $data['value'], $tenantId, $request->user(), $data['reason'] ?? null)
            : $this->drivers->transitionAvailabilityTo($driver, $data['value'], $tenantId, $request->user(), $data['reason'] ?? null);

        return $this->success($updated, 'Driver '.$data['axis'].' updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $driver   = $this->drivers->find($id, $tenantId);

        $this->drivers->delete($driver, $tenantId, $request->user(), $request->input('reason'));

        return $this->success(null, 'Driver deleted');
    }

    /* ── Documents (DB-019) ──────────────────────────────────────────── */

    public function storeDocument(StoreTransportDocumentRequest $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $driver   = $this->drivers->find($id, $tenantId);
        $data     = $request->validated();

        return $this->success(
            $this->documents->file($driver, $data['document_type'], $data, $tenantId, $request->user()),
            'Document filed', 201
        );
    }

    public function renewDocument(StoreTransportDocumentRequest $request, int $id, int $documentId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $this->drivers->find($id, $tenantId);
        $current = $this->documents->find($documentId, $tenantId);

        return $this->success(
            $this->documents->renew($current, $request->validated(), $tenantId, $request->user()),
            'Document renewed', 201
        );
    }
}
