<?php

namespace App\Http\Controllers\Api\V1\Transport;

use App\Domains\Fleet\Services\FleetUtilisationService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * STOS-FLEET → STOS-REP — the reporting reads the executive tower feeds from (T-49).
 *
 * Thin by rule, like the rest of the fleet controllers: resolve the company,
 * hand off to the domain service, return. The route group carries
 * role:admin,staff; denyExternal() is the per-action backstop.
 */
class FleetReportController extends Controller
{
    use ApiResponse;
    use ResolvesTransportAccess;

    public function __construct(private FleetUtilisationService $utilisation)
    {
    }

    /** Idle vehicles right now — trucks that could take a trip and are not on one. */
    public function idle(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->utilisation->idleNow($this->companyId($request)),
            'Idle fleet snapshot'
        );
    }

    /** Per-vehicle utilisation over a window (default: the last 30 days). */
    public function utilisation(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        $request->validate([
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return $this->success(
            $this->utilisation->utilisation(
                $this->companyId($request),
                $request->query('from'),
                $request->query('to'),
            ),
            'Fleet utilisation'
        );
    }
}
