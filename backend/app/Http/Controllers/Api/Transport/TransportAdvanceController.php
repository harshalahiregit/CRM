<?php

namespace App\Http\Controllers\Api\Transport;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Transport\DecideTripAdvanceRequest;
use App\Http\Requests\Transport\StoreTripAdvanceRequest;
use App\Services\Transport\TransportAuditLogger;
use App\Services\Transport\TripAdvanceService;
use App\Services\Transport\TransportTripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trip advances — SNG-TRN-011, "Advance request and approval".
 *
 * Thin, like the other transport controllers: validate through a FormRequest,
 * call the service, return through the shared envelope. Every policy decision —
 * exposure, segregation, how much may be approved — lives in
 * TripAdvanceService so that it holds however an advance is created.
 *
 * Tenant scoping is never re-implemented per method; both lookups go through a
 * service `find()` that filters by tenant AT the point of lookup and raises
 * ResourceNotFoundException otherwise. Route-model binding is deliberately not
 * used, for the reason the consignment controller records: it would resolve a
 * cross-tenant id first and leave the check to be remembered afterwards.
 */
class TransportAdvanceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TripAdvanceService $advances,
        private TransportTripService $trips,
        private TransportAuditLogger $audit,
    ) {
    }

    /**
     * Every advance on a trip, with what the policy allows and what is left.
     *
     * The exposure figures are returned beside the rows rather than left for the
     * client to add up: BR-P0-005 is the server's rule, and a screen that
     * computed its own remaining balance would eventually disagree with the
     * refusal the server gives.
     */
    public function index(Request $request, int $tripId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $trip     = $this->trips->find($tripId, $tenantId);

        $limit    = $this->advances->limitFor($trip, $tenantId);
        $exposure = $this->advances->exposureFor($trip->id, $tenantId);

        return $this->success([
            'advances' => $trip->advances()->orderByDesc('id')->get(),
            'exposure' => [
                'limit'     => $limit,
                'committed' => $exposure,
                'remaining' => bccomp($limit, $exposure, 2) === 1 ? bcsub($limit, $exposure, 2) : '0.00',
                'currency'  => $trip->currency ?? 'INR',
            ],
        ], 'Advances retrieved');
    }

    /** TRP-P0-007 / API-005 / PERM-006. */
    public function store(StoreTripAdvanceRequest $request, int $tripId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $trip     = $this->trips->find($tripId, $tenantId);

        return $this->success(
            $this->advances->request($trip, $request->validated(), $tenantId, $request->user()),
            'Advance requested', 201
        );
    }

    /** PERM-007. */
    public function approve(DecideTripAdvanceRequest $request, int $tripId, int $advanceId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $this->trips->find($tripId, $tenantId);          // tenant + existence gate
        $advance = $this->advances->find($advanceId, $tenantId);

        $this->assertBelongsToTrip($advance->trip_id, $tripId);

        return $this->success(
            $this->advances->approve($advance, $request->validated(), $tenantId, $request->user()),
            'Advance approved'
        );
    }

    /** PERM-007 — refusing is the same authority as allowing. */
    public function reject(DecideTripAdvanceRequest $request, int $tripId, int $advanceId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $this->trips->find($tripId, $tenantId);
        $advance = $this->advances->find($advanceId, $tenantId);

        $this->assertBelongsToTrip($advance->trip_id, $tripId);

        return $this->success(
            $this->advances->reject($advance, (string) $request->input('decision_reason', ''), $tenantId, $request->user()),
            'Advance rejected'
        );
    }

    /**
     * The advance must belong to the trip in the path.
     *
     * Both ids are tenant-checked already, so this is not a security boundary —
     * it stops a correct-looking URL that pairs trip 4 with trip 9's advance
     * from quietly deciding the wrong one.
     */
    private function assertBelongsToTrip(int $advanceTripId, int $tripId): void
    {
        if ($advanceTripId !== $tripId) {
            throw new BusinessException('That advance belongs to a different trip.', 404);
        }
    }
}
