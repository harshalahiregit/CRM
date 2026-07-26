<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\ForecastService;
use Illuminate\Http\Request;

class ForecastController extends Controller
{
    public function __construct(private ForecastService $forecastService)
    {
    }

    public function revenue(Request $request)
    {
        $period = $request->input('period', 'monthly');
        if (! in_array($period, ['monthly', 'quarterly', 'annual'])) {
            $period = 'monthly';
        }
        return response()->json($this->forecastService->revenueForecast($request->user()->tenant_id, $period));
    }

    public function pipeline(Request $request)
    {
        return response()->json($this->forecastService->pipelineByStage($request->user()->tenant_id));
    }

    public function funnel(Request $request)
    {
        return response()->json($this->forecastService->funnel($request->user()->tenant_id));
    }
}
