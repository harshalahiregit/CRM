<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Models\Accounts\GstRate;
use App\Models\Accounts\HsnSac;
use App\Models\Accounts\TdsSection;
use App\Services\Accounts\TaxMasterService;
use Illuminate\Http\Request;

class TaxMasterController extends Controller
{
    public function __construct(private TaxMasterService $tax)
    {
    }

    /* ── GST rates ────────────────────────────────────────────── */
    public function gstRates(Request $request)
    {
        return response()->json($this->tax->gstRates($request->user()->tenant_id));
    }

    public function storeGstRate(Request $request)
    {
        $data = $this->validateGst($request);
        return response()->json($this->tax->saveGstRate(null, $data, $request->user()->tenant_id), 201);
    }

    public function updateGstRate(Request $request, GstRate $gstRate)
    {
        $data = $this->validateGst($request);
        return response()->json($this->tax->saveGstRate($gstRate, $data, $request->user()->tenant_id));
    }

    public function destroyGstRate(GstRate $gstRate, Request $request)
    {
        $this->tax->deleteGstRate($gstRate, $request->user()->tenant_id);
        return response()->json(['message' => 'Rate deleted']);
    }

    /* ── HSN / SAC ────────────────────────────────────────────── */
    public function hsnSac(Request $request)
    {
        return response()->json($this->tax->hsnSac($request->user()->tenant_id, $request->query('search')));
    }

    public function storeHsnSac(Request $request)
    {
        $data = $this->validateHsn($request);
        return response()->json($this->tax->saveHsnSac(null, $data, $request->user()->tenant_id), 201);
    }

    public function updateHsnSac(Request $request, HsnSac $hsnSac)
    {
        $data = $this->validateHsn($request);
        return response()->json($this->tax->saveHsnSac($hsnSac, $data, $request->user()->tenant_id));
    }

    public function destroyHsnSac(HsnSac $hsnSac, Request $request)
    {
        $this->tax->deleteHsnSac($hsnSac, $request->user()->tenant_id);
        return response()->json(['message' => 'HSN/SAC deleted']);
    }

    /* ── TDS sections ─────────────────────────────────────────── */
    public function tdsSections(Request $request)
    {
        return response()->json($this->tax->tdsSections($request->user()->tenant_id));
    }

    public function storeTdsSection(Request $request)
    {
        $data = $this->validateTds($request);
        return response()->json($this->tax->saveTdsSection(null, $data, $request->user()->tenant_id), 201);
    }

    public function updateTdsSection(Request $request, TdsSection $tdsSection)
    {
        $data = $this->validateTds($request);
        return response()->json($this->tax->saveTdsSection($tdsSection, $data, $request->user()->tenant_id));
    }

    public function destroyTdsSection(TdsSection $tdsSection, Request $request)
    {
        $this->tax->deleteTdsSection($tdsSection, $request->user()->tenant_id);
        return response()->json(['message' => 'Section deleted']);
    }

    /* ── Validation ───────────────────────────────────────────── */
    private function validateGst(Request $request): array
    {
        return $request->validate([
            'name'           => 'required|string|max:100',
            'rate_percent'   => 'required|numeric|min:0',
            'cgst'           => 'nullable|numeric|min:0',
            'sgst'           => 'nullable|numeric|min:0',
            'igst'           => 'nullable|numeric|min:0',
            'cess'           => 'nullable|numeric|min:0',
            'effective_from' => 'nullable|date',
            'effective_to'   => 'nullable|date',
            'is_active'      => 'nullable|boolean',
        ]);
    }

    private function validateHsn(Request $request): array
    {
        return $request->validate([
            'code'                => 'required|string|max:20',
            'description'         => 'nullable|string|max:255',
            'type'                => 'required|in:hsn,sac',
            'default_gst_rate_id' => 'nullable|integer',
            'is_active'           => 'nullable|boolean',
        ]);
    }

    private function validateTds(Request $request): array
    {
        return $request->validate([
            'section_code'   => 'required|string|max:20',
            'description'    => 'nullable|string|max:255',
            'rate_percent'   => 'required|numeric|min:0',
            'threshold'      => 'nullable|numeric|min:0',
            'effective_from' => 'nullable|date',
            'effective_to'   => 'nullable|date',
            'is_active'      => 'nullable|boolean',
        ]);
    }
}
