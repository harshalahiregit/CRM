<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Transport\RecordDeliveryRequest;
use App\Http\Requests\Transport\RejectTripRequest;
use App\Http\Requests\Transport\StoreTransportTripRequest;
use App\Http\Requests\Transport\UpdateTransportTripRequest;
use App\Services\Transport\TransportAuditLogger;
use App\Services\Transport\TransportTripService;
use App\Services\Transport\TripAssignmentService;
use Illuminate\Http\JsonResponse;
use App\Models\Transport\TransportContainer;
use App\Models\Transport\TransportTrip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            // The ACTIVE assignment while there is one, and the last one after
            // the trip is delivered.
            //
            // From D-119 the crew is released at delivery, so a delivered trip
            // has no active assignment — and reading only the active one would
            // blank "who drove this" the moment a trip finished, which is the
            // opposite of what freeing the driver is supposed to communicate.
            // The allocation controller still reads the ACTIVE one, correctly:
            // it is deciding whether a trip can be allocated or released, not
            // displaying history.
            'assignment' => ($this->assignments->activeForTrip($trip->id, $tenantId)
                ?? $this->assignments->historyForTrip($trip->id, $tenantId)->first())
                ?->load('vehicle:id,registration_number,vehicle_type,status', 'driver:id,name,driver_code,licence_class,availability'),
            'audit' => $this->audit->forSubject($trip, $tenantId),
            // CTD §4's destination, reachable from the trip in one click.
            //
            // A trip number is the ONE search key that deliberately does not
            // land on the Digital Passport — somebody typing TRP-2026-000034
            // is a dispatcher who wants the working screen (D-117). The
            // condition attached to that ruling is that the passport stays one
            // obvious click away, and it cannot be if the trip does not know
            // which container it is carrying.
            //
            // Null where the consignment has no container on it — loose cargo
            // is allowed (§8) and there is genuinely no passport to open.
            'passport' => $this->passportFor($trip, $tenantId),
        ], 'Trip retrieved');
    }

    /**
     * The container this trip is carrying, if it is carrying one.
     *
     * Two columns, deliberately. This exists so the screen can offer a link,
     * not so it can render a container — CTD §9's passport sections are the
     * passport's own job and duplicating any of them here is how two screens
     * start disagreeing about one box.
     *
     * @return array<string,mixed>|null
     */
    private function passportFor(TransportTrip $trip, int $tenantId): ?array
    {
        if (! $trip->consignment_id) {
            return null;
        }

        $id = DB::table('transport_consignment_containers')
            ->where('tenant_id', $tenantId)
            ->where('consignment_id', $trip->consignment_id)
            ->whereNull('detached_at')
            ->orderByDesc('id')
            ->value('container_id');

        if (! $id) {
            return null;
        }

        $container = TransportContainer::forTenant($tenantId)->find($id);

        return $container ? [
            'container_id'     => $container->id,
            'container_number' => $container->container_number,
        ] : null;
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

    /**
     * STT-002 — approve a trip awaiting viability.
     *
     * NO API REGISTRY ROW EXISTS for approving a trip: Step 11 defines STT-002,
     * PERM-003 and EVT-004, but names no endpoint. The path follows this
     * module's shipped convention rather than being invented freely — it mirrors
     * `submit-viability`, which sits beside it. Logged against D-12.
     *
     * Gated by TRIP_APPROVE at the route, which mirrors PERM-003 exactly,
     * INCLUDING its denial of the Dispatcher.
     *
     * The margin precondition is NOT enforced — see the service docblock and
     * D-64. The response message says so, because a user who approves a trip
     * should know what the system did and did not check.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $trip     = $this->trips->find($id, $tenantId);

        return $this->success(
            $this->trips->approve($trip, $tenantId, $request->user()),
            'Trip approved. The margin check is not yet enforced.'
        );
    }

    /**
     * STT-007 — record that the load arrived. RTM STOS-REQ-OPS-010, P0.
     *
     * PATCH on a named verb, like every other state change here. D-108 records
     * that Step 11 has no API row for this edge, so the path is derived.
     *
     * Gated on transport.trip.deliver, which mirrors PERM-004 — and NOT on
     * PERM-010's POD row, though FRS TRP-P0-013 names a driver. Confirming a
     * trip is delivered unlocks billing for everyone downstream; submitting the
     * proof of it does not. See TransitScope::PERMISSION_DELIVERY.
     */
    public function deliver(RecordDeliveryRequest $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $trip     = $this->trips->find($id, $tenantId);   // 404, never 403

        return $this->success(
            $this->trips->recordDelivery($trip, $request->validated(), $tenantId, $request->user()),
            // STT-007's side effect, said rather than stored. The trip reaching
            // `delivered` IS the POD request; this sentence is what makes that
            // visible to the person who just pressed the button.
            'Delivery recorded. Proof of delivery is now required before this trip can be billed.'
        );
    }

    /**
     * STT-003 — send a trip back for correction.
     *
     * No API_Registry row, like approve; the path mirrors it. Gated by
     * TRIP_APPROVE, reused rather than inventing a second permission matrix —
     * approve and reject are the two answers to one question, and sending a trip
     * back is strictly less powerful than approving it.
     */
    public function reject(RejectTripRequest $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $trip     = $this->trips->find($id, $tenantId);

        return $this->success(
            $this->trips->reject($trip, $request->validated()['reason'], $tenantId, $request->user()),
            'Trip sent back for correction'
        );
    }
}
