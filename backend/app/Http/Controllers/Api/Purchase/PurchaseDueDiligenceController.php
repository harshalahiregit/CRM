<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Concerns\AssertsTenantOwnership;
use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseDueDiligence;
use App\Models\Purchase\PurchaseVendor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Purchase vendor Due-Diligence checklist — the Purchase-side mirror of
 * App\Http\Controllers\Api\Tpv\VendorDueDiligenceController.
 *
 * Reading is open to admin+staff; recording the verification is an admin
 * authority decision (mounted in the admin group), mirroring how the lean risk
 * score and prequalification are gated.
 */
class PurchaseDueDiligenceController extends Controller
{
    use AssertsTenantOwnership;

    /** GET /purchase/vendors/{purchaseVendor}/due-diligence — the checklist + catalogue. */
    public function show(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        $dd = PurchaseDueDiligence::where('tenant_id', $purchaseVendor->tenant_id)
            ->where('purchase_vendor_id', $purchaseVendor->id)->first();

        return response()->json([
            'record'   => $dd,
            'checks'   => PurchaseDueDiligence::CHECKS,
            'states'   => PurchaseDueDiligence::CHECK_STATES,
            'statuses' => PurchaseDueDiligence::STATUSES,
        ]);
    }

    /** PUT /purchase/vendors/{purchaseVendor}/due-diligence — upsert the checklist. */
    public function save(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        $rules = ['findings' => 'nullable|array', 'notes' => 'nullable|string|max:2000'];
        foreach (PurchaseDueDiligence::CHECKS as $check) {
            $rules[$check] = ['sometimes', Rule::in(PurchaseDueDiligence::CHECK_STATES)];
        }
        $data = $request->validate($rules);

        $dd = PurchaseDueDiligence::firstOrNew([
            'tenant_id' => $purchaseVendor->tenant_id, 'purchase_vendor_id' => $purchaseVendor->id,
        ]);
        $dd->fill($data);
        $dd->status      = $dd->deriveStatus();
        $dd->verified_by = $request->user()->id;
        $dd->verified_at = now();
        $dd->save();

        return response()->json($dd->fresh());
    }
}
