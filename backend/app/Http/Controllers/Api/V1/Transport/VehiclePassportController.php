<?php

namespace App\Http\Controllers\Api\V1\Transport;

use App\Domains\Fleet\Services\FleetService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * STOS-FLEET — the Digital Vehicle Passport (Feature 5).
 *
 * One screen, one request. The M2 brief addresses it by registration number
 * (what a person types off a number plate); an id works too, so the fleet grid
 * can link straight through without a second lookup.
 */
class VehiclePassportController extends Controller
{
    use ApiResponse;
    use ResolvesTransportAccess;

    public function __construct(private FleetService $fleet)
    {
    }

    public function show(Request $request, string $vehicle): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->fleet->passport($vehicle, $this->companyId($request)),
            'Vehicle passport retrieved'
        );
    }
}
