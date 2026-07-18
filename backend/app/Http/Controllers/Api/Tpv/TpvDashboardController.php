<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Services\Tpv\TpvDashboardService;
use Illuminate\Http\Request;

class TpvDashboardController extends Controller
{
    public function __construct(private TpvDashboardService $dashboardService)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->dashboardService->getDashboard($request->user()->tenant_id));
    }
}
