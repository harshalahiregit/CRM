<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\StoreVendorPpeItemRequest;
use App\Http\Requests\Shared\UpdateVendorPpeItemRequest;
use App\Services\Tpv\TpvVendorPpeItemService;
use App\Support\Shared\VendorPpeCategory;
use Illuminate\Http\Request;

/**
 * TPV vendor portal — the vendor's OWN PPE list.
 *
 * The owner is always the vendor the portal middleware resolved from the
 * token; no vendor id is read from the URL or the body. An item id belonging
 * to another vendor reads as 404, never as permission. Issuing one of these to
 * a worker goes through the ordinary portal issue route with
 * `vendor_ppe_item_id` — see PpeInventoryService::issue().
 */
class VendorPortalPpeItemController extends Controller
{
    use ResolvesPortalVendor;

    public function __construct(private TpvVendorPpeItemService $items)
    {
    }

    public function index(Request $request)
    {
        $vendor = $this->portalVendor($request);

        return response()->json([
            'data'       => $this->items->listFor((int) $vendor->id, (int) $vendor->tenant_id)->values(),
            'categories' => VendorPpeCategory::LABELS,
        ]);
    }

    public function store(StoreVendorPpeItemRequest $request)
    {
        $vendor = $this->portalVendor($request);

        return response()->json($this->items->create(
            (int) $vendor->id,
            (int) $vendor->tenant_id,
            $request->safe()->except('image'),
            $request->file('image'),
            $request->user()?->id,
        ), 201);
    }

    public function update(UpdateVendorPpeItemRequest $request, int $item)
    {
        $vendor = $this->portalVendor($request);
        $row = $this->items->findOwned((int) $vendor->id, (int) $vendor->tenant_id, $item);

        return response()->json($this->items->update($row, $request->safe()->except('image'), $request->file('image')));
    }

    /** Deactivate / reactivate. Items are never deleted — issues point at them. */
    public function setStatus(Request $request, int $item)
    {
        $data = $request->validate(['is_active' => 'required|boolean']);

        $vendor = $this->portalVendor($request);
        $row = $this->items->findOwned((int) $vendor->id, (int) $vendor->tenant_id, $item);

        return response()->json($this->items->setActive($row, (bool) $data['is_active']));
    }

    public function image(Request $request, int $item)
    {
        $vendor = $this->portalVendor($request);

        return $this->items->imageResponse(
            $this->items->findOwned((int) $vendor->id, (int) $vendor->tenant_id, $item)
        );
    }
}
