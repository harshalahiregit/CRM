<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Vendor\Vendor;
use App\Services\Vendor\VendorPrequalificationService;
use Illuminate\Http\Request;

/**
 * Vendor Prequalification (gap report area 6).
 *
 * Reading is admin+staff; scoring the questionnaire is an admin gate (mounted in
 * the admin route group), mirroring the module's other authority decisions.
 */
class VendorPrequalificationController extends Controller
{
    public function __construct(private VendorPrequalificationService $prequal)
    {
    }

    /** GET /tpv/vendors/{vendor}/prequalification — outcome + questionnaire. */
    public function show(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json($this->prequal->snapshot($vendor));
    }

    /** PUT /tpv/vendors/{vendor}/prequalification — (re)assess from the answers. */
    public function assess(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate([
            'answers'   => 'required|array',
            'answers.*' => 'nullable|string|max:60',
            'notes'     => 'nullable|string|max:2000',
        ]);

        $vendor = $this->prequal->assess($vendor, $data['answers'], $data['notes'] ?? null, $request->user());

        return response()->json($this->prequal->snapshot($vendor));
    }

    private function assertTenant(Request $request, Vendor $vendor): void
    {
        abort_unless(
            (int) $vendor->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Vendor not found'
        );
    }
}
