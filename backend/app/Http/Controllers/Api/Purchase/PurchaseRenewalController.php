<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Concerns\AssertsTenantOwnership;
use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseRenewal;
use App\Models\Purchase\PurchaseVendor;
use App\Services\Purchase\PurchaseRenewalService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Purchase Renewal & Extension — mirror of the TPV engine (parity). Tenant-scoped. */
class PurchaseRenewalController extends Controller
{
    use AssertsTenantOwnership;

    public function __construct(private PurchaseRenewalService $service) {}

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->list($request->user()->tenant_id, $request->only(['status', 'vendor_id'])),
            'decisions' => PurchaseRenewal::DECISIONS,
        ]);
    }

    public function assess(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json($this->service->assess($purchaseVendor));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'purchase_vendor_id' => 'required|integer|exists:purchase_vendors,id',
            'contract_id' => 'nullable|integer|exists:purchase_contracts,id',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        return response()->json($this->service->initiate($data, $request->user()->tenant_id, $request->user()->id), 201);
    }

    public function reassess(Request $request, PurchaseRenewal $renewal)
    {
        $this->assertTenant($request, $renewal);

        return response()->json($this->service->reassess($renewal));
    }

    public function decide(Request $request, PurchaseRenewal $renewal)
    {
        $this->assertTenant($request, $renewal);
        abort_unless($request->user()->role === 'admin', 403, 'Only an admin may decide a renewal.');

        $data = $request->validate([
            'decision' => ['required', Rule::in(PurchaseRenewal::DECISIONS)],
            'conditions' => 'nullable|string',
            'new_end_date' => 'nullable|date',
        ]);

        return response()->json($this->service->decide($renewal, $data, $request->user()));
    }

    public function destroy(Request $request, PurchaseRenewal $renewal)
    {
        $this->assertTenant($request, $renewal);
        $this->service->delete($renewal);

        return response()->json(['deleted' => true]);
    }
}
