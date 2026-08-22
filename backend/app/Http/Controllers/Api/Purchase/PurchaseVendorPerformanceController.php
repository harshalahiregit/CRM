<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Concerns\AssertsTenantOwnership;
use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseVendor;
use App\Services\Purchase\PurchaseVendorPerformanceService;
use Illuminate\Http\Request;

/** Purchase Vendor Performance Index — mirror of the TPV VPI (parity). Read-only, tenant-scoped. */
class PurchaseVendorPerformanceController extends Controller
{
    use AssertsTenantOwnership;

    public function __construct(private PurchaseVendorPerformanceService $service) {}

    public function index(Request $request)
    {
        return response()->json([
            'data'       => $this->service->roster($request->user()->tenant_id),
            'dimensions' => PurchaseVendorPerformanceService::DIMENSIONS,
        ]);
    }

    public function show(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json($this->service->compute($purchaseVendor));
    }
}
