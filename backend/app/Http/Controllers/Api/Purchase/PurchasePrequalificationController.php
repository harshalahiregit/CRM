<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Concerns\AssertsTenantOwnership;
use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseVendor;
use App\Services\Purchase\PurchasePrequalificationService;
use Illuminate\Http\Request;

/**
 * Purchase vendor Prequalification — the Purchase-side mirror of
 * App\Http\Controllers\Api\Tpv\VendorPrequalificationController.
 *
 * Reading is admin+staff; scoring the questionnaire is an admin gate (mounted in
 * the admin route group), mirroring the module's other authority decisions.
 */
class PurchasePrequalificationController extends Controller
{
    use AssertsTenantOwnership;

    public function __construct(private PurchasePrequalificationService $prequal)
    {
    }

    /** GET /purchase/vendors/{purchaseVendor}/prequalification — outcome + questionnaire. */
    public function show(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json($this->prequal->snapshot($purchaseVendor));
    }

    /** PUT /purchase/vendors/{purchaseVendor}/prequalification — (re)assess from the answers. */
    public function assess(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        $data = $request->validate([
            'answers'   => 'required|array',
            'answers.*' => 'nullable|string|max:60',
            'notes'     => 'nullable|string|max:2000',
        ]);

        $purchaseVendor = $this->prequal->assess($purchaseVendor, $data['answers'], $data['notes'] ?? null, $request->user());

        return response()->json($this->prequal->snapshot($purchaseVendor));
    }
}
