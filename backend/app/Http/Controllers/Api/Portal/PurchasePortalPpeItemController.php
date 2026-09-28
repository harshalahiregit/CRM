<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\StoreVendorPpeItemRequest;
use App\Http\Requests\Shared\UpdateVendorPpeItemRequest;
use App\Models\Purchase\PurchaseVendor;
use App\Services\Purchase\PurchaseVendorPpeItemService;
use App\Support\Shared\VendorPpeCategory;
use Illuminate\Http\Request;

/**
 * Purchase vendor portal — the vendor's OWN PPE list.
 *
 * The Purchase twin of VendorPortalPpeItemController. The token subject IS the
 * PurchaseVendor, so that is the owner of every row read or written here; an
 * item id from another vendor reads as 404.
 */
class PurchasePortalPpeItemController extends Controller
{
    public function __construct(private PurchaseVendorPpeItemService $items)
    {
    }

    public function index(Request $request)
    {
        $vendor = $this->vendor($request);

        return response()->json([
            'data'       => $this->items->listFor((int) $vendor->id, (int) $vendor->tenant_id)->values(),
            'categories' => VendorPpeCategory::LABELS,
        ]);
    }

    public function store(StoreVendorPpeItemRequest $request)
    {
        $vendor = $this->vendor($request);

        // created_by is a users id; a Purchase vendor login is not a users row.
        return response()->json($this->items->create(
            (int) $vendor->id,
            (int) $vendor->tenant_id,
            $request->safe()->except('image'),
            $request->file('image'),
        ), 201);
    }

    public function update(UpdateVendorPpeItemRequest $request, int $item)
    {
        $vendor = $this->vendor($request);
        $row = $this->items->findOwned((int) $vendor->id, (int) $vendor->tenant_id, $item);

        return response()->json($this->items->update($row, $request->safe()->except('image'), $request->file('image')));
    }

    /** Deactivate / reactivate. Items are never deleted — issues point at them. */
    public function setStatus(Request $request, int $item)
    {
        $data = $request->validate(['is_active' => 'required|boolean']);

        $vendor = $this->vendor($request);
        $row = $this->items->findOwned((int) $vendor->id, (int) $vendor->tenant_id, $item);

        return response()->json($this->items->setActive($row, (bool) $data['is_active']));
    }

    public function image(Request $request, int $item)
    {
        $vendor = $this->vendor($request);

        return $this->items->imageResponse(
            $this->items->findOwned((int) $vendor->id, (int) $vendor->tenant_id, $item)
        );
    }

    private function vendor(Request $request): PurchaseVendor
    {
        $vendor = $request->user();
        abort_unless($vendor instanceof PurchaseVendor, 403, 'This area is for Purchase vendor accounts only.');

        return $vendor;
    }
}
