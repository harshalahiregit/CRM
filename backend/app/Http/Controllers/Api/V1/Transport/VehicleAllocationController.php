<?php

namespace App\Http\Controllers\Api\V1\Transport;

use App\Domains\Fleet\Services\VehicleAllocationService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * STOS-FLEET — which vehicles may take this job (Feature 1).
 *
 * Read-only by design. This RECOMMENDS; it never allocates. A trip and its
 * assignment belong to Dispatch (Developer 1), and an override reason is
 * recorded against the allocation THERE — writing one here would put a trip
 * fact in the fleet domain.
 */
class VehicleAllocationController extends Controller
{
    use ApiResponse;
    use ResolvesTransportAccess;

    public function __construct(private VehicleAllocationService $allocation)
    {
    }

    public function eligible(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        $filters = $request->validate([
            'vehicle_type' => 'nullable|string|max:30',
            // PLN-001 — the order's payload. Optional, because a planner may
            // browse the fleet without an order in front of them; supplied, it
            // excludes anything that cannot carry the load.
            //
            // `gt:0` rather than `min:0`: a zero here would mean "every vehicle
            // qualifies", which is what an empty box already means, and the two
            // must not be spelled differently.
            'required_capacity_tonnes' => 'nullable|numeric|gt:0|max:999999',
            // Optional: without a pickup, proximity simply is not scored rather
            // than being guessed at.
            'pickup_lat'   => 'nullable|numeric|between:-90,90',
            'pickup_lng'   => 'nullable|numeric|between:-180,180',
        ]);

        return $this->success(
            $this->allocation->eligible($this->companyId($request), $filters),
            'Eligible vehicles retrieved'
        );
    }
}
