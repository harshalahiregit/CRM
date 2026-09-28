<?php

namespace App\Http\Controllers\Api\V1\Transport;

use App\Domains\Fleet\Services\MaintenanceService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Stos\CloseJobCardRequest;
use App\Http\Requests\Stos\StoreJobCardRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * STOS-MAINT — workshop job cards (Feature 4).
 *
 * Opening a card takes the vehicle off the road and closing it tries to put it
 * back; both transitions live in the service, so a status can never be changed
 * without the card that justifies it.
 */
class MaintenanceController extends Controller
{
    use ApiResponse;
    use ResolvesTransportAccess;

    public function __construct(private MaintenanceService $maintenance)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->maintenance->board($this->companyId($request), $request->query('status')),
            'Job cards retrieved'
        );
    }

    public function store(StoreJobCardRequest $request): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->maintenance->open($this->companyId($request), $request->validated(), $request->user()->id),
            'Job card opened',
            201
        );
    }

    public function update(StoreJobCardRequest $request, int $job): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->maintenance->update($job, $this->companyId($request), $request->validated(), $request->user()->id),
            'Job card updated'
        );
    }

    /**
     * Close the card. The response says whether the vehicle actually went back
     * on the road and, when it did not, exactly what still holds it.
     */
    public function close(CloseJobCardRequest $request, int $job): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->maintenance->close($job, $this->companyId($request), $request->validated(), $request->user()->id),
            'Job card closed'
        );
    }
}
