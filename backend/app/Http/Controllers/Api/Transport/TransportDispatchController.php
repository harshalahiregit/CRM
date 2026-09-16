<?php

namespace App\Http\Controllers\Api\Transport;

use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Transport\AmendDispatchRequest;
use App\Http\Requests\Transport\DispatchTripRequest;
use App\Services\Transport\DispatchService;
use App\Services\Transport\PretripService;
use App\Services\Transport\TransportAuditLogger;
use App\Services\Transport\TransportTripService;
use App\Support\Transport\DispatchScope;
use App\Support\Transport\TripStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dispatch confirmation — RTM STOS-REQ-OPS-008, FRS TRP-P0-006.
 *
 *   PATCH /transport/trips/{trip}/dispatch        release the trip
 *   PATCH /transport/trips/{trip}/dispatch/amend  change a frozen field
 *
 * ── NEITHER PATH IS IN THE REGISTRY ───────────────────────────────────────
 * Step 11's API registry (API-001…015) names no dispatch endpoint, and Step 12
 * owns no dispatch ticket (D-18). The owner authorised this scope directly on
 * 2026-09-10. Paths follow the conventions this module already set: a state
 * transition is a PATCH on a named verb, as PATCH /trips/{id}/submit-viability
 * and PATCH /trips/{trip}/pass-pretrip already are.
 *
 * ── NOTHING HERE REACHES in_transit ───────────────────────────────────────
 * STT-006 is SNG-TRN-013's Transit half, blocked on the owner's Q1/Q3 ruling.
 * The response says which state was actually reached so no client can assume
 * the trip is moving.
 *
 * Thin, like every other transport controller. Tenancy is resolved once by
 * TransportTripService::find(), which raises ResourceNotFoundException — 404,
 * never 403 — and again inside DispatchService.
 */
class TransportDispatchController extends Controller
{
    use ApiResponse;

    public function __construct(
        private DispatchService $dispatch,
        private TransportTripService $trips,
        private TransportAuditLogger $audit,
        private PretripService $pretrip,
    ) {
    }

    /**
     * The whole dispatch picture for one trip, without attempting anything.
     *
     * A screen must be able to say WHY dispatch is unavailable before the user
     * clicks — UX §35 forbids merely showing Blocked, and BRW-048 wants the
     * exact reason. So this returns the same live readiness verdict the gate
     * itself would use, computed by the same method, rather than leaving the
     * panel to infer a reason from a 422 it has not triggered yet.
     *
     * Gated on transport.trip.view, not transport.trip.dispatch: watching a
     * trip's dispatch state is not releasing it, and the same read/write split
     * already separates PRETRIP_VIEW from PRETRIP_PERFORM.
     */
    public function show(Request $request, int $trip): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $record   = $this->trips->find($trip, $tenantId);

        return $this->success($this->payload($record, $tenantId), 'Dispatch state');
    }

    /** Release the trip — pretrip_ok → dispatched. */
    public function confirm(DispatchTripRequest $request, int $trip): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $record   = $this->trips->find($trip, $tenantId);   // 404, never 403

        try {
            $moved = $this->dispatch->confirm($record, $request->validated(), $tenantId, $request->user());
        } catch (ResourceNotFoundException $e) {
            // MUST precede the BusinessException arm — ResourceNotFoundException
            // extends it, and a 422 on another tenant's id would confirm the
            // record exists.
            throw $e;
        } catch (BusinessException $e) {
            return $this->refusal($e, $record, $tenantId);
        }

        return $this->success($this->payload($moved, $tenantId), 'Trip dispatched');
    }

    /** Change a frozen field — "changes create version". */
    public function amend(AmendDispatchRequest $request, int $trip): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $record   = $this->trips->find($trip, $tenantId);
        $data     = $request->validated();

        try {
            $moved = $this->dispatch->amend(
                $record, $data, (string) ($data['reason'] ?? ''), $tenantId, $request->user(),
            );
        } catch (ResourceNotFoundException $e) {
            throw $e;
        } catch (BusinessException $e) {
            return $this->refusal($e, $record, $tenantId);
        }

        return $this->success($this->payload($moved, $tenantId), 'Dispatch amended');
    }

    /** One shape for success and refusal, so a client renders one component. */
    private function payload($trip, int $tenantId): array
    {
        return [
            'trip'        => $trip,
            'dispatched'  => $trip->status === TripStatus::DISPATCHED,
            'frozen'      => $this->dispatch->isFrozen($trip),
            'version'     => (int) $trip->dispatch_version,
            'dispatch'    => $this->dispatch->snapshot($trip),
            // TRP-P0-006's "TAT", derived rather than stored — see DispatchScope.
            'turnaround_hours' => $trip->turnaroundHours(),
            // TRP-P0-006's "Version history", reconstructed from the audit trail.
            'history' => $this->dispatch->history($trip, $tenantId),
            // BRW-046, re-derived live. Present on every response — including a
            // success — so a panel never has to guess whether it may offer the
            // action, and so a refusal and a pre-flight read agree by construction.
            'readiness' => $trip->isDispatched() ? null : $this->pretrip->revalidate($trip, $tenantId),
            // Stated rather than implied: dispatched is not in transit.
            'in_transit'  => false,
            'in_transit_note' => 'STT-006 belongs to SNG-TRN-013, which is blocked.',
            // BRW-050's side effects that did not happen, and why.
            'deferred_effects' => array_keys(array_filter(
                DispatchScope::BRW_050_DISPOSITION,
                fn (string $d) => $d !== 'built',
            )),
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
