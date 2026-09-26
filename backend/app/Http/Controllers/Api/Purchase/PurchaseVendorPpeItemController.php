<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseVendor;
use App\Services\Purchase\PurchaseVendorPpeItemService;
use App\Support\Shared\VendorPpeCategory;
use Illuminate\Http\Request;

/**
 * Admin view of ONE Purchase vendor's own PPE list, from the vendor workspace.
 *
 * Read-only, mirroring the TPV side: the list is the vendor's stock and the
 * vendor keeps it from its portal.
 */
class PurchaseVendorPpeItemController extends Controller
{
    public function __construct(private PurchaseVendorPpeItemService $items)
    {
    }

    public function index(Request $request, int $purchaseVendor)
    {
        $v = $this->vendor($request, $purchaseVendor);

        return response()->json([
            'data'       => $this->items->listFor((int) $v->id, (int) $v->tenant_id)->values(),
            'categories' => VendorPpeCategory::LABELS,
        ]);
    }

    public function image(Request $request, int $purchaseVendor, int $item)
    {
        $v = $this->vendor($request, $purchaseVendor);

        return $this->items->imageResponse($this->items->findOwned((int) $v->id, (int) $v->tenant_id, $item));
    }

    /** A vendor in the caller's tenant, or 404. */
    private function vendor(Request $request, int $id): PurchaseVendor
    {
        return PurchaseVendor::where('tenant_id', (int) $request->user()->tenant_id)->whereKey($id)->first()
            ?? abort(404, 'Vendor not found');
    }
}
