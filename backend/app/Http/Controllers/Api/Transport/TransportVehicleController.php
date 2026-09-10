<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Transport\StoreTransportDocumentRequest;
use App\Http\Requests\Transport\StoreTransportVehicleRequest;
use App\Http\Requests\Transport\TransitionTransportVehicleRequest;
use App\Http\Requests\Transport\UpdateTransportVehicleRequest;
use App\Services\Transport\TransportAuditLogger;
use App\Services\Transport\TransportDocumentService;
use App\Services\Transport\TransportVehicleService;
use App\Services\Transport\VehicleEligibilityService;
use App\Support\Transport\VehicleStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vehicle master (SNG-TRN-003), FE half.
 *
 * Thin, like TransportOrderController: FormRequest validates, the service acts,
 * ApiResponse wraps. Every write goes through TransportVehicleService so the
 * audit rows the G-1 audit found missing are written by construction — this
 * controller never touches the model directly.
 *
 * Tenant scoping is never re-implemented here. Every lookup is
 * TransportVehicleService::find(), which filters at the point of the query and
 * raises ResourceNotFoundException (404, never 403) otherwise. Route-model
 * binding is deliberately unused: it would resolve a cross-tenant id first and
 * leave the check to be remembered afterwards.
 */
class TransportVehicleController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TransportVehicleService $vehicles,
        private TransportDocumentService $documents,
        private VehicleEligibilityService $eligibility,
        private TransportAuditLogger $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status'      => 'nullable|string|max:40',
            'search'      => 'nullable|string|max:120',
            'allocatable' => 'nullable|boolean',
            'per_page'    => 'nullable|integer|min:1|max:200',
        ]);

        $page = $this->vehicles
            ->list($request->user()->tenant_id, $filters)
            ->paginate((int) ($filters['per_page'] ?? 25));

        return $this->success($page, 'Vehicles retrieved');
    }

    /** Counts per status, for the list's filter chips. */
    public function statusCounts(Request $request): JsonResponse
    {
        $counts = \App\Models\Transport\TransportVehicle::forTenant($request->user()->tenant_id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return $this->success(
            collect(VehicleStatus::ALL)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all(),
            'Vehicle status counts retrieved'
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $vehicle  = $this->vehicles->find($id, $tenantId);

        return $this->success([
            'vehicle'   => $vehicle,
            'documents' => $vehicle->documents()->orderByDesc('id')->get(),
            // FLEET §16: Available ≠ Eligible ≠ Ready. The detail page shows the
            // eligibility verdict beside the status so the two are not confused.
            'eligibility' => $this->eligibility->evaluate($vehicle, null, $tenantId),
            'transitions' => VehicleStatus::TRANSITIONS[$vehicle->status] ?? [],
            'audit'       => $this->audit->forSubject($vehicle, $tenantId),
        ], 'Vehicle retrieved');
    }

    public function store(StoreTransportVehicleRequest $request): JsonResponse
    {
        $vehicle = $this->vehicles->create(
            $request->validated(), $request->user()->tenant_id, $request->user()
        );

        return $this->success($vehicle, 'Vehicle created', 201);
    }

    public function update(UpdateTransportVehicleRequest $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $vehicle  = $this->vehicles->find($id, $tenantId);

        return $this->success(
            $this->vehicles->update($vehicle, $request->validated(), $tenantId, $request->user()),
            'Vehicle updated'
        );
    }

    /** FLEET §8 — status moves here, never through update(). */
    public function transition(TransitionTransportVehicleRequest $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $vehicle  = $this->vehicles->find($id, $tenantId);

        return $this->success(
            $this->vehicles->transitionTo(
                $vehicle, $request->validated()['status'], $tenantId,
                $request->user(), $request->validated()['reason'] ?? null
            ),
            'Vehicle status updated'
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $vehicle  = $this->vehicles->find($id, $tenantId);

        $this->vehicles->delete($vehicle, $tenantId, $request->user(), $request->input('reason'));

        return $this->success(null, 'Vehicle deleted');
    }

    /* ── Documents (DB-019) ──────────────────────────────────────────── */

    public function storeDocument(StoreTransportDocumentRequest $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $vehicle  = $this->vehicles->find($id, $tenantId);
        $data     = $request->validated();

        return $this->success(
            $this->documents->file($vehicle, $data['document_type'], $data, $tenantId, $request->user()),
            'Document filed', 201
        );
    }

    /** STOS-DOC §26 — a replacement is a new version, never an overwrite. */
    public function renewDocument(StoreTransportDocumentRequest $request, int $id, int $documentId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $this->vehicles->find($id, $tenantId);          // tenant + existence gate
        $current = $this->documents->find($documentId, $tenantId);

        return $this->success(
            $this->documents->renew($current, $request->validated(), $tenantId, $request->user()),
            'Document renewed', 201
        );
    }
}
