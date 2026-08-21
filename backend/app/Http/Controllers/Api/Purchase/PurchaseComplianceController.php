<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Concerns\AssertsTenantOwnership;
use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseVendorCompliance;
use App\Services\Purchase\PurchaseComplianceService;
use App\Support\Purchase\PurchaseComplianceCatalog as Catalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Purchase compliance engine — mirror of the TPV register (parity). Tenant-scoped. */
class PurchaseComplianceController extends Controller
{
    use AssertsTenantOwnership;

    public function __construct(private PurchaseComplianceService $service) {}

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->roster($request->user()->tenant_id),
            'categories' => Catalog::CATEGORIES,
            'statuses' => Catalog::STATUSES,
        ]);
    }

    public function vendorMatrix(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json([
            'vendor' => $purchaseVendor->only(['id', 'company_name', 'purchase_vendor_code', 'status']),
            'matrix' => $this->service->vendorMatrix($request->user()->tenant_id, $purchaseVendor->id),
        ]);
    }

    public function upsert(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);
        $data = $request->validate([
            'category' => ['required', Rule::in(Catalog::CATEGORIES)],
            'status' => ['required', Rule::in(Catalog::STATUSES)],
            'requirement' => 'nullable|string',
            'valid_until' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        return response()->json(
            $this->service->upsert($request->user()->tenant_id, $purchaseVendor->id, $data, $request->user()->id)
        );
    }

    public function destroy(Request $request, PurchaseVendorCompliance $compliance)
    {
        $this->assertTenant($request, $compliance);
        $this->service->delete($compliance);

        return response()->json(['deleted' => true]);
    }
}
