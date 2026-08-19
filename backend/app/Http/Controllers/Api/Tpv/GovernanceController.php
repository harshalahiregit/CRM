<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Services\Tpv\ComplianceReportService;
use App\Services\Tpv\GovernanceDashboardService;
use Illuminate\Http\Request;

/** HSSE governance dashboard + periodic reports (Doc 6). Tenant-scoped, read-only. */
class GovernanceController extends Controller
{
    public function dashboard(Request $request, GovernanceDashboardService $service)
    {
        return response()->json($service->build($request->user()->tenant_id));
    }

    /** DPR / WPR / MCR periodic compliance report. */
    public function report(Request $request, ComplianceReportService $service)
    {
        $data = $request->validate([
            'kind'   => 'required|in:DPR,WPR,MCR,dpr,wpr,mcr',
            'anchor' => 'nullable|date',
        ]);

        return response()->json($service->build(
            $request->user()->tenant_id,
            $data['kind'],
            $data['anchor'] ?? null,
        ));
    }
}
