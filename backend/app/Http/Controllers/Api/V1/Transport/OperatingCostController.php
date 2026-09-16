<?php

namespace App\Http\Controllers\Api\V1\Transport;

use App\Domains\Fleet\Models\TyreFitment;
use App\Domains\Fleet\Services\FleetService;
use App\Domains\Fleet\Services\TyreService;
use App\Domains\Fleet\Services\UreaService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * STOS-COST / STOS-MAINT — urea, tyres, and the trip cost roll-up.
 *
 * The trip endpoint is the HTTP face of `FleetService::getTripOperatingCosts()`,
 * the contract Developer 3 consumes; the in-process method is the primary
 * surface and this simply exposes it for anyone integrating over HTTP.
 */
class OperatingCostController extends Controller
{
    use ApiResponse;
    use ResolvesTransportAccess;

    public function __construct(
        private UreaService $urea,
        private TyreService $tyres,
        private FleetService $fleet,
    ) {
    }

    /* ── Urea / AdBlue ──────────────────────────────────────────── */

    public function storeUrea(Request $request, int $vehicle): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'litres'         => 'required|numeric|gt:0|max:500',
            'rate_per_litre' => 'nullable|numeric|gt:0|max:10000',
            'amount'         => 'required|numeric|gt:0|max:9999999',
            'odometer'       => 'nullable|numeric|min:0|max:9999999',
            'station_vendor' => 'nullable|string|max:150',
            'trip_id'        => 'nullable|integer|min:1',
        ]);

        return $this->success(
            $this->urea->record($vehicle, $this->companyId($request), $data, $request->user()->id),
            'Urea entry recorded',
            201
        );
    }

    /* ── Tyres ──────────────────────────────────────────────────── */

    public function tyres(Request $request, int $vehicle): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success($this->tyres->forVehicle($vehicle, $this->companyId($request)), 'Tyres retrieved');
    }

    public function fitTyre(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'vehicle_id'  => 'required|integer|min:1',
            'tyre_id'     => 'required|string|max:60',
            'position'    => ['required', Rule::in(TyreFitment::POSITIONS)],
            'tread_depth' => 'nullable|numeric|between:0,30',
            'odometer_at_fitment' => 'nullable|numeric|min:0|max:9999999',
            'fitted_on'   => 'nullable|date',
            'note'        => 'nullable|string|max:255',
        ]);

        return $this->success(
            $this->tyres->fit($this->companyId($request), $data, $request->user()->id),
            'Tyre fitted',
            201
        );
    }

    public function inspectTyre(Request $request, int $fitment): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'tread_depth'  => 'required|numeric|between:0,30',
            'inspected_on' => 'nullable|date',
            'note'         => 'nullable|string|max:255',
        ]);

        return $this->success(
            $this->tyres->inspect($fitment, $this->companyId($request), $data, $request->user()->id),
            'Inspection recorded'
        );
    }

    public function removeTyre(Request $request, int $fitment): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'status' => ['nullable', Rule::in(['removed', 'in_stock', 'retreaded', 'scrapped'])],
            'odometer_at_removal' => 'nullable|numeric|min:0|max:9999999',
            'note'   => 'nullable|string|max:255',
        ]);

        return $this->success(
            $this->tyres->remove($fitment, $this->companyId($request), $data, $request->user()->id),
            'Tyre removed'
        );
    }

    /* ── Trip cost roll-up (Developer 3's contract) ─────────────── */

    public function tripCosts(Request $request, int $trip): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->fleet->getTripOperatingCosts($trip, $this->companyId($request)),
            'Trip operating costs retrieved'
        );
    }
}
