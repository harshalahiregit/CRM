<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvVendorPpeItemService;
use App\Support\Shared\VendorPpeCategory;
use Illuminate\Http\Request;

/**
 * Admin view of ONE TPV vendor's own PPE list, from the vendor workspace.
 *
 * Read-only: the list is the vendor's stock and the vendor keeps it. Admin
 * sees what the vendor says it holds and how much of it is out on workers.
 */
class VendorPpeItemController extends Controller
{
    public function __construct(private TpvVendorPpeItemService $items)
    {
    }

    public function index(Request $request, int $vendor)
    {
        $v = $this->vendor($request, $vendor);

        return response()->json([
            'data'       => $this->items->listFor((int) $v->id, (int) $v->tenant_id)->values(),
            'categories' => VendorPpeCategory::LABELS,
        ]);
    }

    public function image(Request $request, int $vendor, int $item)
    {
        $v = $this->vendor($request, $vendor);

        return $this->items->imageResponse($this->items->findOwned((int) $v->id, (int) $v->tenant_id, $item));
    }

    /** A vendor in the caller's tenant, or 404. */
    private function vendor(Request $request, int $id): Vendor
    {
        return Vendor::where('tenant_id', (int) $request->user()->tenant_id)->whereKey($id)->first()
            ?? abort(404, 'Vendor not found');
    }
}
