<?php

namespace App\Http\Controllers\Api\V1\Transport;

use App\Domains\Fleet\Models\VehicleLiveStatus;
use App\Domains\Fleet\Services\FleetService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * STOS-FLEET / STOS-INT — the control tower's read endpoints.
 *
 * Thin by rule: resolve the company, hand off to the domain service, return.
 * The route group carries role:admin,staff; denyExternal() is the per-action
 * backstop so a new method here cannot leak the fleet to a portal login just
 * because somebody forgot the group guard.
 */
class FleetController extends Controller
{
    use ApiResponse;
    use ResolvesTransportAccess;

    public function __construct(private FleetService $fleet)
    {
    }

    /** The vehicle status grid + the tiles above it. */
    public function grid(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->fleet->grid($this->companyId($request), $request->only('state', 'q')),
            'Fleet retrieved'
        );
    }

    /**
     * Tier-1 telemetry for one vehicle (Feature 2).
     *
     * A primary-key lookup on vehicle_live_status and nothing else — this is
     * what a gauge polls, and it must never touch telemetry_records, which
     * grows without bound.
     */
    public function liveStatus(Request $request, int $vehicle): JsonResponse
    {
        $this->denyExternal($request);

        $companyId = $this->companyId($request);

        $live = VehicleLiveStatus::forCompany($companyId)->where('vehicle_id', $vehicle)->first();

        if (! $live) {
            // 200 with a null reading, not 404: "this vehicle has never
            // reported" is a legitimate answer about a vehicle that exists, and
            // a gauge should render "no signal" rather than an error toast.
            return $this->success(
                ['vehicle_id' => $vehicle, 'live' => null, 'signal' => 'offline'],
                'No telemetry recorded for this vehicle'
            );
        }

        return $this->success([
            'vehicle_id' => $vehicle,
            'live'       => $live,
            'signal'     => $this->fleet->signalHealth($live->last_ping_at),
        ], 'Live status retrieved');
    }
}
