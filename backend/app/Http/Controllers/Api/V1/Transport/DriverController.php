<?php

namespace App\Http\Controllers\Api\V1\Transport;

use App\Domains\Fleet\Models\DriverProfile;
use App\Domains\Fleet\Services\DriverService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * STOS-FLEET — drivers.
 *
 * There is no "create driver" endpoint in an integrated deployment on purpose:
 * people are added in the customer/vendor directory that already owns them, and
 * Transport reads that live. What this exposes is the OVERLAY — licence,
 * availability and which vehicle they regularly take — the only part STOS owns.
 */
class DriverController extends Controller
{
    use ApiResponse;
    use ResolvesTransportAccess;

    public function __construct(private DriverService $drivers)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->drivers->list($this->companyId($request), $request->only('q', 'drivers_only', 'ready_only')),
            'Drivers retrieved'
        );
    }

    /**
     * Who can take a load right now, and who cannot — with the reason.
     *
     * Answers in the same `{eligible, excluded[blockers]}` shape as
     * `FleetService::getEligibleVehicles()`, so a dispatch board renders the
     * truck list and the crew list with one component. An expired licence
     * blocks the DRIVER here; it no longer touches the vehicle's ranking.
     */
    public function eligible(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->drivers->eligible($this->companyId($request), $request->only('q', 'drivers_only')),
            'Driver eligibility evaluated'
        );
    }

    /**
     * Save the Transport overlay against a person from the directory.
     *
     * Addressed by `source:source_id` — the handle that points back at the
     * record in the directory, rather than an id STOS invented for a person it
     * does not own.
     */
    public function saveProfile(Request $request, string $source, int $person): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'licence_number' => 'nullable|string|max:40',
            'licence_class'  => ['nullable', Rule::in(DriverProfile::CLASSES)],
            'licence_expiry' => 'nullable|date',
            'medical_expiry' => 'nullable|date',
            // MANUALLY_SETTABLE, not STATUSES: ON_TRIP is written by the
            // dispatch gateway when a trip takes the driver and cleared when it
            // releases them. Typing it here would claim a trip that does not
            // exist, and the release would then never come.
            'status'         => ['nullable', Rule::in(DriverProfile::MANUALLY_SETTABLE)],
            'note'           => 'nullable|string|max:255',
        ]);

        return $this->success(
            $this->drivers->saveProfile($this->companyId($request), $source, $person, $data, $request->user()->id),
            'Driver details saved'
        );
    }

    /**
     * Put a driver on a vehicle, or clear it with a null vehicle_id.
     *
     * This is the REGULAR assignment the allocation engine reads. Who drives on
     * a given trip is Dispatch's decision and is not set here.
     */
    public function assign(Request $request, string $source, int $person): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'vehicle_id' => 'nullable|integer|min:1',
        ]);

        $vehicleId = $data['vehicle_id'] ?? null;

        return $this->success(
            $this->drivers->assignToVehicle(
                $this->companyId($request), $source, $person, $vehicleId, $request->user()->id
            ),
            $vehicleId ? 'Driver assigned' : 'Driver unassigned'
        );
    }
}
