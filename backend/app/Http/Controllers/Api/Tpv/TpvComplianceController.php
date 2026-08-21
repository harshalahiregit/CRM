<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvVendorCompliance;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvComplianceService;
use App\Support\Tpv\ComplianceCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** TPV Compliance engine (Sangoe TPV §21). Tenant-scoped. */
class TpvComplianceController extends Controller
{
    public function __construct(private TpvComplianceService $service) {}

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->roster($request->user()->tenant_id),
            'categories' => ComplianceCatalog::CATEGORIES,
            'statuses' => ComplianceCatalog::STATUSES,
        ]);
    }

    public function vendorMatrix(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json([
            'vendor' => $vendor->only(['id', 'company_name', 'vendor_code', 'status']),
            'matrix' => $this->service->vendorMatrix($request->user()->tenant_id, $vendor->id),
        ]);
    }

    public function upsert(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);
        $data = $request->validate([
            'category' => ['required', Rule::in(ComplianceCatalog::CATEGORIES)],
            'status' => ['required', Rule::in(ComplianceCatalog::STATUSES)],
            'requirement' => 'nullable|string',
            'valid_until' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        return response()->json(
            $this->service->upsert($request->user()->tenant_id, $vendor->id, $data, $request->user()->id)
        );
    }

    public function destroy(Request $request, TpvVendorCompliance $compliance)
    {
        $this->assertTenant($request, $compliance);
        $this->service->delete($compliance);

        return response()->json(['deleted' => true]);
    }
}
