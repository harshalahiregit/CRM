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

    /** §27 — capture a point-in-time snapshot into the performance history. */
    public function snapshot(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate(['project' => 'nullable|string|max:160']);

        return response()->json($this->service->snapshot($vendor, $data['project'] ?? null), 201);
    }

    /** §27 — the persisted VPI history for a vendor, newest first. */
    public function history(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $rows = \App\Models\Tpv\TpvVendorPerformanceSnapshot::where('tenant_id', $vendor->tenant_id)
            ->where('vendor_id', $vendor->id)
            ->when($request->query('project'), fn ($q, $p) => $q->where('project', $p))
            ->latest('captured_at')
            ->get();

        return response()->json(['data' => $rows]);
    }
}
