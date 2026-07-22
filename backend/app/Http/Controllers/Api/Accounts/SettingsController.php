<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\StoreCompanyProfileRequest;
use App\Http\Requests\Accounts\StoreFinancialYearRequest;
use App\Models\Accounts\FinancialYear;
use App\Models\Accounts\NumberingSeries;
use App\Services\Accounts\SettingsService;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(private SettingsService $settings)
    {
    }

    /* ── Company profile ──────────────────────────────────────── */
    public function companyProfile(Request $request)
    {
        return response()->json($this->settings->companyProfile($request->user()->tenant_id));
    }

    public function saveCompanyProfile(StoreCompanyProfileRequest $request)
    {
        $profile = $this->settings->saveCompanyProfile($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($profile);
    }

    /* ── Financial years ──────────────────────────────────────── */
    public function financialYears(Request $request)
    {
        return response()->json($this->settings->financialYears($request->user()->tenant_id));
    }

    public function storeFinancialYear(StoreFinancialYearRequest $request)
    {
        $fy = $this->settings->createFinancialYear($request->validated(), $request->user()->tenant_id);
        return response()->json($fy, 201);
    }

    public function toggleFinancialYear(FinancialYear $financialYear, Request $request)
    {
        $data = $request->validate(['is_closed' => 'required|boolean']);
        $fy = $this->settings->setFinancialYearClosed($financialYear, $data['is_closed'], $request->user()->tenant_id, $request->user()->id);
        return response()->json($fy);
    }

    /* ── Voucher types & numbering ────────────────────────────── */
    public function voucherTypes(Request $request)
    {
        return response()->json($this->settings->voucherTypes($request->user()->tenant_id));
    }

    public function numberingSeries(Request $request)
    {
        return response()->json($this->settings->numberingSeries($request->user()->tenant_id));
    }

    public function updateNumberingSeries(NumberingSeries $series, Request $request)
    {
        $data = $request->validate([
            'prefix'          => 'nullable|string|max:20',
            'suffix'          => 'nullable|string|max:20',
            'next_number'     => 'nullable|integer|min:1',
            'reset_frequency' => 'nullable|in:never,yearly',
            'width'           => 'nullable|integer|min:1|max:12',
        ]);
        return response()->json($this->settings->updateNumberingSeries($series, $data, $request->user()->tenant_id));
    }

    /* ── Audit trail (read-only) ──────────────────────────────── */
    public function auditLogs(Request $request)
    {
        return response()->json($this->settings->auditLogs($request->user()->tenant_id, $request->only([
            'entity_type', 'action', 'per_page',
        ])));
    }
}
