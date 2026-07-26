<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Company & Finance settings (Settings → Company & Finance): registered
 * state + GSTIN. The state drives CGST/SGST vs IGST auto-detection.
 */
class CompanySettingController extends Controller
{
    public function show(Request $request)
    {
        $tenant = $request->user()->tenant;

        return response()->json([
            'name'  => $tenant->name,
            'state' => $tenant->state,
            'gstin' => $tenant->gstin,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'state' => 'nullable|string|max:100',
            // 15-char GSTIN: 2-digit state code + PAN + entity + Z + checksum
            'gstin' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/'],
        ], [
            'gstin.regex' => 'GSTIN must be a valid 15-character GST number (e.g. 27AAECM1234K1Z5).',
        ]);

        $tenant = $request->user()->tenant;
        $tenant->forceFill($data)->save();

        return response()->json([
            'name'  => $tenant->name,
            'state' => $tenant->state,
            'gstin' => $tenant->gstin,
        ]);
    }
}
