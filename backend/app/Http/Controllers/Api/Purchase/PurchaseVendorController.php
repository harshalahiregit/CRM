<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\StorePurchaseVendorRequest;
use App\Http\Requests\Purchase\UpdatePurchaseVendorRequest;
use App\Models\Purchase\PurchaseVendor;
use App\Services\Purchase\PurchaseVendorService;
use App\Support\Purchase\PurchaseVendorStatus;
use App\Support\Task\VendorTaskLink;
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

    /**
     * Tasks linked to this Purchase vendor (tasks.rel_type = 'purchase_vendor').
     *
     * Separate from the TPV endpoint on purpose -- the two vendor modules share no
     * table, no controller and no route, only the shape of the task link.
     */
    public function tasks(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);
        $tenantId = (int) $request->user()->tenant_id;

        // Most Purchase Vendors have no User at all (they authenticate as a
        // PurchaseVendor), so this is usually null and only the relation link applies.
        $portalUserId = $purchaseVendor->user_id;

        return response()->json([
            'summary' => VendorTaskLink::summary(VendorTaskLink::PURCHASE, $purchaseVendor->id, $tenantId, $portalUserId),
            'tasks'   => VendorTaskLink::forVendor(VendorTaskLink::PURCHASE, $purchaseVendor->id, $tenantId, $portalUserId),
        ]);
    }

    public function show(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        $vendor = $this->vendors->find($purchaseVendor->id, $request->user()->tenant_id);
        // Vendor Detail dashboard: last activation e-mail, full notification
        // timeline and portal login stats. All read from existing stores.
        $vendor->setAttribute('last_notification', $this->vendors->lastNotification($purchaseVendor));
        $vendor->setAttribute('notification_timeline', $this->vendors->notificationTimeline($purchaseVendor));
        $vendor->setAttribute('login_stats', $this->vendors->loginStats($purchaseVendor));

        return response()->json($vendor);
    }

    /** Resend the activation e-mail. Active vendors only; every send is logged. */
    public function resendActivation(Request $request, PurchaseVendor $purchaseVendor)
    {
        $this->assertTenant($request, $purchaseVendor);

        return response()->json($this->vendors->resendActivationEmail($purchaseVendor, $request->user()));
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
