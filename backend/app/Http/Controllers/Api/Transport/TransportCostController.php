<?php

namespace App\Http\Controllers\Api\Transport;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Transport\RetractTripCostRequest;
use App\Http\Requests\Transport\StoreTripCostRequest;
use App\Services\Transport\TransportTripService;
use App\Services\Transport\TripCostService;
use App\Support\Transport\CostType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trip costs — SNG-TRN-012, "Trip cost capture".
 *
 * Thin, like the other transport controllers: validate through a FormRequest,
 * call the service, return through the shared envelope. Every rule that matters
 * — the trip must exist in this tenant, the source contract, the duplicate
 * handling — lives in TripCostService so it holds however a cost is created,
 * including from the import and telemetry paths that will not come through here.
 *
 * Tenant scoping is never re-implemented per method; lookups go through a
 * service `find()` that filters by tenant AT the point of lookup. Route-model
 * binding is deliberately not used, for the reason the consignment controller
 * records: it resolves a cross-tenant id first and leaves the check to be
 * remembered afterwards.
 */
class TransportCostController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TripCostService $costs,
        private TransportTripService $trips,
    ) {
    }

    /**
     * Every cost on a trip, with the total and the per-type breakdown.
     *
     * The totals are computed server-side and returned beside the rows rather
     * than left for the client to add up. They are bcmath sums of a DECIMAL
     * column; a screen adding JavaScript numbers would drift from them, and the
     * server's figure is the one SNG-TRN-018 will report.
     */
    public function index(Request $request, int $tripId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $trip     = $this->trips->find($tripId, $tenantId);

        return $this->success([
            'costs'     => $this->costs->forTrip($trip->id, $tenantId),
            'total'     => $this->costs->totalFor($trip->id, $tenantId),
            'breakdown' => $this->costs->breakdownFor($trip->id, $tenantId),
            'currency'  => $trip->currency ?? 'INR',
            // Suggestions for a picker, explicitly not a permitted-values list.
            // Sent so a screen can offer consistent spellings without the
            // client hard-coding a vocabulary the registry has not defined.
            'known_types' => CostType::KNOWN,
        ], 'Trip costs retrieved');
    }

    /** SNG-TRN-012 — record one cost. Constructed permission, see D-58. */
    public function store(StoreTripCostRequest $request, int $tripId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $trip     = $this->trips->find($tripId, $tenantId);

        return $this->success(
            $this->costs->record($trip, $request->validated(), $tenantId, $request->user()),
            'Cost recorded', 201
        );
    }

    /**
     * Retract a cost from the margin.
     *
     * A soft delete, not a removal — SNG-TRN-018 has to be able to explain a
     * figure that changed, and a vanished row explains nothing.
     */
    public function destroy(RetractTripCostRequest $request, int $tripId, int $costId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $this->trips->find($tripId, $tenantId);          // tenant + existence gate
        $cost = $this->costs->find($costId, $tenantId);

        $this->assertBelongsToTrip((int) $cost->trip_id, $tripId);

        return $this->success(
            $this->costs->retract($cost, (string) $request->input('reason'), $tenantId, $request->user()),
            'Cost retracted'
        );
    }

    /**
     * The cost must belong to the trip in the path.
     *
     * Both ids are tenant-checked already, so this is not a security boundary —
     * it stops a correct-looking URL that pairs trip 4 with trip 9's cost from
     * quietly retracting the wrong one.
     */
    private function assertBelongsToTrip(int $costTripId, int $tripId): void
    {
        if ($costTripId !== $tripId) {
            throw new BusinessException('That cost belongs to a different trip.', 404);
        }
    }
}
