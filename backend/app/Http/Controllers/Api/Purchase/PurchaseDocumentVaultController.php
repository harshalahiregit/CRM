<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Concerns\AssertsTenantOwnership;
use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseVendor;
use App\Services\Purchase\PurchaseDocumentVaultService;
use Illuminate\Http\Request;

/** Unified Purchase Document Vault — mirror of the TPV vault (parity). Read-only, tenant-scoped. */
class PurchaseDocumentVaultController extends Controller
{
    use AssertsTenantOwnership;

    public function __construct(private PurchaseDocumentVaultService $service) {}

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        return response()->json([
            'data'    => $this->service->roster($tenantId, $request->only(['source', 'vendor_id', 'expiry', 'q'])),
            'summary' => $this->service->summary($tenantId),
            'sources' => PurchaseDocumentVaultService::SOURCES,
            'expiry_states' => PurchaseDocumentVaultService::EXPIRY_STATES,
        ]);
    }

    public function vendor(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json($this->service->vendorVault($request->user()->tenant_id, $purchaseVendor->id));
    }
}
