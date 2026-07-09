<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\HRDashboardService;
use Illuminate\Http\Request;

class HRDashboardController extends Controller
{
    public function __construct(private HRDashboardService $dashboardService)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->dashboardService->summary($request->user()));
    }
}
