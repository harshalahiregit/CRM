<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Concerns\AssertsTenantOwnership;
use App\Http\Controllers\Controller;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvDocumentVaultService;
use Illuminate\Http\Request;

/** Unified TPV Document Vault (Sangoe TPV §30). Read-only, tenant-scoped. */
class TpvDocumentVaultController extends Controller
{
    use AssertsTenantOwnership;

    public function __construct(private TpvDocumentVaultService $service) {}

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        return response()->json([
            'data'    => $this->service->roster($tenantId, $request->only(['source', 'vendor_id', 'expiry', 'q'])),
            'summary' => $this->service->summary($tenantId),
            'sources' => TpvDocumentVaultService::SOURCES,
            'expiry_states' => TpvDocumentVaultService::EXPIRY_STATES,
        ]);
    }

    public function vendor(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        return response()->json($this->service->vendorVault($request->user()->tenant_id, $vendor->id));
    }
}
