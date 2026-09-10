<?php

namespace App\Http\Controllers\Api\Transport;

use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Transport\AssignTripResourcesRequest;
use App\Services\Transport\AllocationService;
use App\Services\Transport\TransportAuditLogger;
use App\Services\Transport\TransportTripService;
use App\Services\Transport\TripAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Allocation endpoints — SNG-TRN-009 step 7.
 *
 *   POST   /transport/trips/{trip}/assign      API-004
 *   GET    /transport/trips/{trip}/candidates  no registry row — see below
 *   DELETE /transport/trips/{trip}/assign      no registry row — see below
 *
 * ── TWO OF THESE THREE ARE NOT IN THE REGISTRY ────────────────────────────
 * Step 11's API registry runs API-001…015 and contains no candidate-listing and
 * no release endpoint; neither does Step 4's parallel list. But PLN-002 and
 * PLN-003 ("Only eligible vehicles/drivers suggested") are P0 requirements that
 * cannot be met without a way to ask, and an allocation that can be made but
 * never undone is not a workflow. Paths ruled by the owner on 2026-09-08:
 * candidates gets one call returning both collections, and release reuses
 * API-004's own path with DELETE rather than inventing a second noun.
 * Recorded as defect D-12.
 *
 * ── GATING ────────────────────────────────────────────────────────────────
 * All three sit behind transport.permission:transport.trip.assign (PERM-004).
 * Candidates is NOT given a weaker gate: a dispatcher who may not crew a trip
 * has no reason to enumerate the fleet against it, and Step 11 defines no
 * separate permission to give it (defect D-8).
 *
 * ── THE VERDICT TRAVELS WITH EVERY ANSWER ─────────────────────────────────
 * Success and failure both carry the full eligibility checks array, in the same
 * shape Step 5 produces. QA-003 requires a block to be "actionable" and BRWM §70
 * shows the tone; a client that had to make a second call to find out WHY would
 * either skip it or show a bare "failed".
 *
 * Thin, like every other transport controller: no queries, no rules. Tenancy is
 * resolved once by TransportTripService::find() and again inside
 * AllocationService for the vehicle and driver ids.
 */
class TransportAllocationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private AllocationService $allocation,
        private TransportTripService $trips,
        private TripAssignmentService $assignments,
        private TransportAuditLogger $audit,
    ) {
    }

    /**
     * API-004 — assign a vehicle and/or driver.
     *
     * 422 with the verdict when a required eligibility check fails; 200 with the
     * verdict when it succeeds. The status code carries the outcome, the body
     * carries the reasoning, and both shapes are the same so a client renders
     * one component either way.
     */
    public function assign(AssignTripResourcesRequest $request, int $trip): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $record   = $this->trips->find($trip, $tenantId);   // 404, never 403
        $data     = $request->validated();

        try {
            $result = $this->allocation->assign(
                $record,
                isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : null,
                isset($data['driver_id']) ? (int) $data['driver_id'] : null,
                $tenantId,
                $request->user(),
                array_filter([
                    'reason'          => $data['reason'] ?? null,
                    'allocation_type' => $data['allocation_type'] ?? null,
                ]),
            );
        } catch (ResourceNotFoundException $e) {
            // MUST come first: ResourceNotFoundException EXTENDS BusinessException,
            // so a broad catch below would swallow it and answer 422. A naming
            // another tenant's vehicle has to read as "no such vehicle" — 404,
            // never 422 and never 403, or the status code itself confirms the
            // record exists somewhere.
            throw $e;
        } catch (BusinessException $e) {
            // A refusal is not an error the client can do nothing about — it is
            // a verdict. Re-running eligibility here costs one read and lets the
            // response say which checks failed, not just that something did.
            return response()->json([
                'status'      => 'error',
                'message'     => $e->getMessage(),
                'eligibility' => $this->allocation->candidates($record, $tenantId, includeIneligible: true),
                'data'        => [
                    'trip'       => $record->fresh(),
                    'assignment' => $this->assignments->activeForTrip($record->id, $tenantId),
                ],
            // getStatusCode(), not getCode(): BusinessException carries its
            // status in its own property and getCode() is always 0.
            ], $e->getStatusCode());
        }

        return $this->success([
            'trip'        => $result['trip'],
            'assignment'  => $result['assignment'],
            'eligibility' => $result['eligibility'],
            // STT-004 fires only when both resources are set. Saying so plainly
            // stops a client having to infer it from the status string.
            'allocated'   => $result['trip']->status === \App\Support\Transport\TripStatus::ALLOCATED,
            'audit'       => $this->audit->forSubject($result['assignment'], $tenantId),
        ], $result['trip']->status === \App\Support\Transport\TripStatus::ALLOCATED
            ? 'Trip allocated'
            : 'Resource assigned — the trip is allocated once both a vehicle and a driver are set');
    }

    /**
     * PLN-002 / PLN-003 — "Only eligible vehicles/drivers suggested".
     *
     * `include_ineligible=1` returns the rest with their blockers, because a
     * dispatcher looking at an empty list needs to know WHY every vehicle is
     * unusable (UX §35: never merely show Blocked — show why).
     */
    public function candidates(Request $request, int $trip): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $record   = $this->trips->find($trip, $tenantId);

        $filters = $request->validate(['include_ineligible' => 'nullable|boolean']);

        $candidates = $this->allocation->candidates(
            $record, $tenantId, (bool) ($filters['include_ineligible'] ?? false)
        );

        return $this->success([
            'trip'       => $record->only(['id', 'trip_number', 'status', 'vehicle_id', 'driver_id']),
            'vehicles'   => $candidates['vehicles'],
            'drivers'    => $candidates['drivers'],
            'assignment' => $this->assignments->activeForTrip($record->id, $tenantId),
        ], 'Allocation candidates retrieved');
    }

    /**
     * Release the trip's active assignment.
     *
     * Frees the vehicle and driver and reverts the trip to approved — that last
     * move is an INFERRED transition, not a registry row; see
     * TripStatus::INFERRED_TRANSITIONS.
     */
    public function release(Request $request, int $trip): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $record   = $this->trips->find($trip, $tenantId);

        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        $assignment = $this->assignments->activeForTrip($record->id, $tenantId);

        if (! $assignment) {
            throw new BusinessException('This trip has no active assignment to release.', 422);
        }

        $released = $this->allocation->release($assignment, $tenantId, $request->user(), $data['reason'] ?? null);

        return $this->success([
            'trip'       => $record->fresh(),
            'assignment' => $released,
        ], 'Assignment released');
    }
}
