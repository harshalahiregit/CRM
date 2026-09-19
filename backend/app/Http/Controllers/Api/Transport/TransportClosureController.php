<?php

namespace App\Http\Controllers\Api\Transport;

use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Transport\CloseTripRequest;
use App\Services\Transport\TransportAuditLogger;
use App\Services\Transport\TransportTripService;
use App\Services\Transport\TripClosureService;
use App\Support\Transport\ClosureScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trip closure — STT-012, API-009. The one endpoint in this module whose path,
 * method and permission key are all quoted rather than derived.
 *
 *   GET  /transport/trips/{trip}/closure   what is blocking, without trying
 *   POST /transport/trips/{trip}/close     API-009, verbatim
 *
 * ── THIS ENDPOINT IS PLUMBED, NOT REACHABLE — D-106 ───────────────────────
 * `collection_pending` is the only state STT-012 leaves from, and no user can
 * reach it: TripBill::markInvoiced() has no caller and no route. That is P3's
 * surface, raised and not fixed here.
 *
 * Every response therefore carries `reachable: false` and the reason, so a
 * client that renders a Close button knows the state is currently unoccupiable
 * rather than discovering it from a 422 that looks like a bug.
 *
 * ── THE GET EXISTS BECAUSE OF TRP-P0-014'S ACCEPTANCE CRITERION ───────────
 * "User sees exactly why a trip is blocked." A screen must be able to say that
 * before anyone clicks, so the readiness verdict is served by the same method
 * the gate itself uses — a refusal and a pre-flight read agree by construction
 * rather than by two implementations staying in step.
 *
 * It sits on transport.trip.view, not transport.trip.close: reading why a trip
 * is blocked is not closing it. The same read/write split separates
 * PRETRIP_VIEW from PRETRIP_PERFORM and the dispatch GET from its PATCH.
 *
 * Thin, like every other transport controller. Tenancy resolves once in
 * TransportTripService::find(), which raises ResourceNotFoundException — 404,
 * never 403 — and again inside TripClosureService.
 */
class TransportClosureController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TripClosureService $closure,
        private TransportTripService $trips,
        private TransportAuditLogger $audit,
    ) {
    }

    /** What is blocking closure, and what could not be checked at all. */
    public function show(Request $request, int $trip): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $record   = $this->trips->find($trip, $tenantId);

        return $this->success($this->payload($record, $tenantId), 'Closure readiness');
    }

    /** API-009 — POST /api/v1/transport/trips/{trip}/close. */
    public function close(CloseTripRequest $request, int $trip): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $record   = $this->trips->find($trip, $tenantId);

        try {
            $closed = $this->closure->close(
                $record, $request->validated()['closure_reason'], $tenantId, $request->user(),
            );
        } catch (ResourceNotFoundException $e) {
            throw $e;
        } catch (BusinessException $e) {
            return $this->refusal($e, $record, $tenantId);
        }

        return $this->success($this->payload($closed, $tenantId), 'Trip closed');
    }

    private function payload($trip, int $tenantId): array
    {
        return [
            'trip'      => $trip,
            'closed'    => $trip->closed_at !== null,
            'readiness' => $this->closure->readiness($trip, $tenantId),

            // STT-012's side effect, stated rather than implied by its absence.
            'profit_snapshot' => ClosureScope::SNAPSHOT_PROFIT,

            'audit' => $this->audit->forSubject($trip, $tenantId),
        ];
    }

    private function refusal(BusinessException $e, $record, int $tenantId): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => $e->getMessage(),
            'data'    => $this->payload($record->fresh(), $tenantId),
        // getStatusCode(), not getCode(): BusinessException carries its status
        // in its own property and getCode() is always 0.
        ], $e->getStatusCode());
    }
}
