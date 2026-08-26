<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Services\Tpv\TpvDashboardService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TpvDashboardController extends Controller
{
    public function __construct(private TpvDashboardService $dashboardService)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->dashboardService->getDashboard($request->user()->tenant_id));
    }

    /** §4 — risk drill-down by the requested dimension. */
    public function riskDrilldown(Request $request)
    {
        $data = $request->validate([
            'dimension' => ['required', Rule::in(['vendor', 'project', 'site', 'department', 'work_package', 'risk_category'])],
        ]);

        return response()->json(
            $this->dashboardService->riskDrilldown($request->user()->tenant_id, $data['dimension'])
        );
    }
}
