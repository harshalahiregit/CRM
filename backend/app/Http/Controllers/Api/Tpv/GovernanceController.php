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

    /** HSSE authority matrix (Doc 1) — the named authorities + who owns what. */
    public function authorityMatrix()
    {
        return response()->json([
            'authorities' => config('authority.authorities'),
            'matrix'      => config('authority.matrix'),
        ]);
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
