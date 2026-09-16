<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Transport\ResourceCommitmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Which of my trips is holding this vehicle or driver, and until when?"
 *
 * UX §35 — never merely show Blocked, show why. The allocation panel can say a
 * resource is unavailable; this is what lets it say *why* and *when it comes
 * free*, in a sentence a customer understands rather than a status code.
 *
 * ── DELIBERATELY SEPARATE FROM THE CANDIDATES ENDPOINT ───────────────────
 * The obvious place for this would be inside the allocation candidates payload.
 * It is not there on purpose: candidates are built by the eligibility services,
 * which belong to Person 2's allocation scoring (TM-001 §9). Adding a field to
 * that payload would be a contract change to somebody else's surface.
 *
 * This endpoint reads Person 1 tables only — `trip_assignments` and
 * `transport_trips` — and the client merges the two by resource id. The merge
 * is the seam, and it costs one extra request.
 *
 * Gated with TRIP_VIEW rather than a key of its own: everything it returns is a
 * fact about a trip, and someone who may read trips may read this.
 */
class TransportResourceCommitmentController extends Controller
{
    use ApiResponse;

    public function __construct(private ResourceCommitmentService $commitments)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->success(
            $this->commitments->forTenant($request->user()->tenant_id),
            'Resource commitments retrieved',
        );
    }
}
