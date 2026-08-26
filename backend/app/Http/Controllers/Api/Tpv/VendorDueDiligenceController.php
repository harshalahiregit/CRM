<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvDueDiligence;
use App\Models\Vendor\Vendor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * §7 Due-Diligence checklist. Reading is open to admin+staff; recording the
 * verification is an admin authority decision (mounted in the admin group),
 * mirroring how risk classification and prequalification are gated.
 */
class VendorDueDiligenceController extends Controller
{
    /** GET /tpv/vendors/{vendor}/due-diligence — the checklist + catalogue. */
    public function show(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $dd = TpvDueDiligence::where('tenant_id', $vendor->tenant_id)
            ->where('vendor_id', $vendor->id)->first();

        return response()->json([
            'record'  => $dd,
            'checks'  => TpvDueDiligence::CHECKS,
            'states'  => TpvDueDiligence::CHECK_STATES,
            'statuses' => TpvDueDiligence::STATUSES,
        ]);
    }

    /** PUT /tpv/vendors/{vendor}/due-diligence — upsert the checklist. */
    public function save(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $rules = ['findings' => 'nullable|array', 'notes' => 'nullable|string|max:2000'];
        foreach (TpvDueDiligence::CHECKS as $check) {
            $rules[$check] = ['sometimes', Rule::in(TpvDueDiligence::CHECK_STATES)];
        }
        $data = $request->validate($rules);

        $dd = TpvDueDiligence::firstOrNew([
            'tenant_id' => $vendor->tenant_id, 'vendor_id' => $vendor->id,
        ]);
        $dd->fill($data);
        $dd->status      = $dd->deriveStatus();
        $dd->verified_by = $request->user()->id;
        $dd->verified_at = now();
        $dd->save();

        return response()->json($dd->fresh());
    }

    private function assertTenant(Request $request, Vendor $vendor): void
    {
        abort_unless((int) $vendor->tenant_id === (int) $request->user()->tenant_id, 404, 'Vendor not found');
    }
}
