<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Vendor\Vendor;
use App\Services\Vendor\VendorRiskService;
use Illuminate\Http\Request;

/**
 * Vendor Risk Classification (gap report area 2).
 *
 * Reading the classification is open to admin+staff; setting it is an admin
 * gate (mounted in the admin route group), mirroring how the module treats other
 * authority decisions (approvals, document review, strikes).
 */
class VendorRiskController extends Controller
{
    public function __construct(private VendorRiskService $risk)
    {
    }

    /** GET /tpv/vendors/{vendor}/risk — current classification + factor catalogue. */
    public function show(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json($this->risk->snapshot($vendor));
    }

    /** PUT /tpv/vendors/{vendor}/risk — (re)assess from the answered factors. */
    public function assess(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate([
            'factors'   => 'required|array',
            'factors.*' => 'nullable|string|max:60',
            'notes'     => 'nullable|string|max:2000',
        ]);

        $vendor = $this->risk->assess($vendor, $data['factors'], $data['notes'] ?? null, $request->user());

        return response()->json($this->risk->snapshot($vendor));
    }

    /** Route-model binding does not know about tenants — reads must be guarded. */
    private function assertTenant(Request $request, Vendor $vendor): void
    {
        abort_unless(
            (int) $vendor->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Vendor not found'
        );
    }
}
