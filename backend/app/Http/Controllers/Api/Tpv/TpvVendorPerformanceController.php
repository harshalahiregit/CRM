<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Concerns\AssertsTenantOwnership;
use App\Http\Controllers\Controller;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvVendorPerformanceService;
use Illuminate\Http\Request;

/** Vendor Performance Index (Sangoe TPV §27). Read-only, tenant-scoped. */
class TpvVendorPerformanceController extends Controller
{
    use AssertsTenantOwnership;

    public function __construct(private TpvVendorPerformanceService $service) {}

    public function index(Request $request)
    {
        return response()->json([
            'data'       => $this->service->roster($request->user()->tenant_id),
            'dimensions' => TpvVendorPerformanceService::DIMENSIONS,
        ]);
    }

    public function show(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json($this->service->compute($vendor));
    }
}
