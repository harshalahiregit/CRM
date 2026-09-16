<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Transport\StoreTransportTripRequest;
use App\Http\Requests\Transport\UpdateTransportTripRequest;
use App\Services\Transport\TransportAuditLogger;
use App\Services\Transport\TransportTripService;
use App\Services\Transport\TripAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trips (SNG-TRN-007).
 *
 * Same shape as the order controller: thin, tenant-scoped through the service,
 * no route-model binding.
 */
class TransportTripController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TransportTripService $trips,
        private TransportAuditLogger $audit,
        private TripAssignmentService $assignments,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status'      => 'nullable|string|max:40',
            'order_id'    => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'open'        => 'nullable|boolean',
            'search'      => 'nullable|string|max:120',
            'per_page'    => 'nullable|integer|min:1|max:200',
        ]);

        return $this->success(
            $this->trips->list($request->user()->tenant_id, $filters),
            'Trips retrieved'
        );
    }

    public function statusCounts(Request $request): JsonResponse
    {
        return $this->success(
            $this->trips->statusCounts($request->user()->tenant_id),
            'Trip status counts retrieved'
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $trip     = $this->trips->find($id, $tenantId);

        return $this->success([
            // CTD-003 — the consignment is loaded with the trip so the screen can
            // show order -> consignment -> trip without a second request. Column
            // limited, like the others: a trip read must not become a full
            // shipment read.
            'trip'  => $trip->load(
                'customer:id,company',
                'order:id,order_number,service_type,priority,order_status,required_at',
                'consignment:id,consignment_number,customer_reference,cargo_description,package_count,gross_weight_kg',
            ),
            // Who is crewing this trip. Part of the trip, not a separate lookup:
            // the detail screen would otherwise need transport.trip.assign just
            // to display a vehicle registration, which would hide it from
            // Accounts and Approver — roles PERM-001 grants full trip view to.
            // The eager loads are column-limited so a trip read never becomes a
            // full master-data read.
            'assignment' => $this->assignments->activeForTrip($trip->id, $tenantId)
                ?->load('vehicle:id,registration_number,vehicle_type,status', 'driver:id,name,driver_code,licence_class,availability'),
            'audit' => $this->audit->forSubject($trip, $tenantId),
        ], 'Trip retrieved');
    }

    /** Create a trip from an approved order. */
    public function store(StoreTransportTripRequest $request): JsonResponse
    {
        $data = $request->validated();

        $trip = $this->trips->createFromOrder(
            (int) $data['order_id'],
            $data,
            $request->user()->tenant_id,
            $request->user()
        );

        return $this->success($trip, 'Trip created', 201);
    }

    public function update(UpdateTransportTripRequest $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $trip     = $this->trips->find($id, $tenantId);

        return $this->success(
            $this->trips->update($trip, $request->validated(), $tenantId, $request->user()),
            'Trip updated'
        );
    }

    /**
     * STT-001 — submit the trip for viability.
     *
     * Its own endpoint rather than a generic status setter: this is the only
     * transition SNG-TRN-007 owns, and a generic setter would invite callers to
     * move a trip into states whose guards belong to tickets not yet built.
     */
    public function submitForViability(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $trip     = $this->trips->find($id, $tenantId);

        return $this->success(
            $this->trips->submitForViability($trip, $tenantId, $request->user()),
            'Trip submitted for viability'
        );
    }
}
