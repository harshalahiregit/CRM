<?php

namespace App\Http\Controllers\Api\V1\Transport;

use App\Domains\Fleet\Services\VehicleService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Stos\StoreVehicleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * STOS-FLEET — Step 1: onboarding a vehicle, and correcting its master data.
 *
 * Every other Developer 2 endpoint needs the vehicle_id this creates, so this
 * is the entry point of the whole module.
 *
 * Who may do what: admin and staff both onboard and edit — a transport manager
 * is 'staff' and blocking them would mean only the account owner could add a
 * truck. RETIRING is admin-only: it hides an asset that fuel spend and job
 * cards are already attached to.
 */
class VehicleController extends Controller
{
    use ApiResponse;
    use ResolvesTransportAccess;

    public function __construct(private VehicleService $vehicles)
    {
    }

    public function store(StoreVehicleRequest $request): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->vehicles->create($request->validated(), $this->companyId($request), $request->user()->id),
            'Vehicle added to the fleet',
            201
        );
    }

    public function update(StoreVehicleRequest $request, int $vehicle): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->vehicles->update($vehicle, $request->validated(), $this->companyId($request), $request->user()->id),
            'Vehicle updated'
        );
    }

    public function destroy(Request $request, int $vehicle): JsonResponse
    {
        $this->denyExternal($request);
        abort_unless($this->isAdmin($request), 403, 'Only an admin can retire a vehicle.');

        $this->vehicles->retire($vehicle, $this->companyId($request), $request->user()->id);

        return $this->success(null, 'Vehicle retired');
    }

    /** Option lists for the onboarding form — one source for the enums. */
    public function options(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success($this->vehicles->formOptions(), 'Options retrieved');
    }
}
