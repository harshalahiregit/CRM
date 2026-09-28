<?php

namespace App\Http\Controllers\Api\V1\Transport;

use App\Domains\Fleet\Services\FuelService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Stos\StoreFuelRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * STOS-COST — diesel, urea and the fills that do not add up (Feature 3).
 */
class FuelController extends Controller
{
    use ApiResponse;
    use ResolvesTransportAccess;

    public function __construct(private FuelService $fuel)
    {
    }

    public function store(StoreFuelRequest $request, int $vehicle): JsonResponse
    {
        $this->denyExternal($request);

        $row = $this->fuel->record(
            $vehicle,
            $this->companyId($request),
            $request->validated(),
            $request->user()->id,
            $request->file('receipt'),
        );

        return $this->success($row, 'Fuel entry recorded', 201);
    }

    /** The variance register: exceptions and emergency fills. */
    public function exceptions(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->fuel->exceptions($this->companyId($request), $request->query('vehicle_id')),
            'Fuel exceptions retrieved'
        );
    }

    /**
     * The receipt image. Streamed from the private disk — these are never
     * public URLs, because a fuel receipt carries a card trail.
     */
    public function receipt(Request $request, int $fuel)
    {
        $this->denyExternal($request);

        $file = $this->fuel->receipt($fuel, $this->companyId($request));

        return Storage::disk($file['disk'])->response($file['path']);
    }
}
