<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Services\Purchase\PurchaseDashboardService;
use Illuminate\Http\Request;

class PurchaseDashboardController extends Controller
{
    public function __construct(private PurchaseDashboardService $dashboardService)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->dashboardService->getDashboard($request->user()->tenant_id));
    }
}
