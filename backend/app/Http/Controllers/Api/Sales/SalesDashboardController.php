<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesDashboardService;
use Illuminate\Http\Request;

class SalesDashboardController extends Controller
{
    public function __construct(private SalesDashboardService $dashboardService)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->dashboardService->getDashboard($request->user()->tenant_id));
    }
}
