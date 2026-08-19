<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Services\Tpv\GovernanceDashboardService;
use Illuminate\Http\Request;

/** HSSE governance dashboard (Doc 6). Tenant-scoped, read-only. */
class GovernanceController extends Controller
{
    public function dashboard(Request $request, GovernanceDashboardService $service)
    {
        return response()->json($service->build($request->user()->tenant_id));
    }
}
