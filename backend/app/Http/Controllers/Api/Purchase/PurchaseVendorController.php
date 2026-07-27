<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\StorePurchaseVendorRequest;
use App\Http\Requests\Purchase\UpdatePurchaseVendorRequest;
use App\Models\Purchase\PurchaseVendor;
use App\Services\Purchase\PurchaseVendorService;
use App\Support\Purchase\PurchaseVendorStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Purchase Vendor master — the staff/admin surface over the Purchase-owned vendor
 * entity (PurchaseVendorService, purchase_vendors). Completely independent of the
 * shared VendorController and TPV. Every bound vendor is tenant-guarded (404).
 */
class PurchaseVendorController extends Controller
{
    public function __construct(private PurchaseVendorService $vendors)
    {
    }

    public function stats(Request $request)
    {
        return response()->json($this->vendors->stats($request->user()->tenant_id));
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->vendors->list($request->user()->tenant_id, $request->only(['status', 'category', 'vendor_type', 'search']))
        );
    }

    public function store(StorePurchaseVendorRequest $request)
    {
        return response()->json($this->vendors->create($request->validated(), $request->user()), 201);
    }

    public function show(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json($this->vendors->find($purchaseVendor->id, $request->user()->tenant_id));
    }

    public function update(UpdatePurchaseVendorRequest $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json($this->vendors->update($purchaseVendor, $request->validated(), $request->user()));
    }

    public function updateStatus(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);
        $data = $request->validate([
            'status'  => ['required', Rule::in(PurchaseVendorStatus::ALL)],
            'remarks' => 'nullable|string|max:2000',
        ]);

        return response()->json($this->vendors->updateStatus($purchaseVendor, $data['status'], $request->user(), $data['remarks'] ?? null));
    }

    /** Activate a vendor for procurement (role:admin). */
    public function approve(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json($this->vendors->approve($purchaseVendor, $request->user()));
    }

    public function destroy(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);
        $this->vendors->delete($purchaseVendor, $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    private function assertTenant(Request $request, PurchaseVendor $purchaseVendor): void
    {
        abort_unless((int) $purchaseVendor->tenant_id === (int) $request->user()->tenant_id, 404, 'Purchase vendor not found');
    }
}
