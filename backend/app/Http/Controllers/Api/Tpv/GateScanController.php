<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Services\Tpv\GateScanService;
use Illuminate\Http\Request;

/**
 * PUBLIC — the site gate. No login: a guard scans a badge with a phone.
 *
 * The QR token is the bearer credential; possession of the card is what
 * authorises seeing the pass. Responses carry only the PII a guard needs to
 * match the person and act in an emergency. Routes are rate-limited, and every
 * scan is written to the gate log.
 */
class GateScanController extends Controller
{
    public function __construct(private GateScanService $gateService)
    {
    }

    /** Read-only — shows the pass card without recording attendance. */
    public function scan(Request $request, string $token)
    {
        return response()->json($this->gateService->scan($token, $request));
    }

    public function checkIn(Request $request, string $token)
    {
        $request->validate(['gate' => 'nullable|string|max:60']);

        return response()->json($this->gateService->checkIn($token, $request));
    }

    public function checkOut(Request $request, string $token)
    {
        $request->validate(['gate' => 'nullable|string|max:60']);

        return response()->json($this->gateService->checkOut($token, $request));
    }
}
