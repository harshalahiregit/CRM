<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Inventory\CarrierService;
use App\Services\Inventory\FulfilmentService;
use Illuminate\Http\Request;

/**
 * Pick, pack, ship (§11 / §14).
 *
 * Picking and packing are warehouse work, so any internal staff member may do
 * them — that is the job. Shipping is the moment stock leaves and money is
 * committed, so it takes the same bar as posting the document behind it.
 */
class FulfilmentController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(
        private FulfilmentService $fulfilment,
        private CarrierService $carriers,
    ) {
    }

    public function index(Request $request)
    {
        $this->denyExternal($request);

        $f = $request->only(['status', 'warehouse_id', 'assigned_to', 'search']);
        // "Mine" is resolved server-side from the token, so it can never be
        // pointed at a colleague by editing the query string.
        if ($request->boolean('mine')) {
            $f['mine'] = $request->user()->id;
        }

        return $this->success($this->fulfilment->list($request->user()->tenant_id, $f), 'Pick lists retrieved');
    }

    public function show(Request $request, int $id)
    {
        $this->denyExternal($request);
        $list = $this->fulfilment->show($id, $request->user()->tenant_id);

        return $this->success([
            'list'  => $list,
            // The dispatcher decides whether a discrepancy is acceptable, so the
            // check is reported rather than enforced.
            'check' => $this->fulfilment->packCheck($list),
        ], 'Pick list retrieved');
    }

    /** Turn a delivery voucher into a job someone can walk. */
    public function store(Request $request)
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'voucher_id'   => 'required|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'assigned_to'  => 'nullable|integer|exists:users,id',
            'note'         => 'nullable|string|max:1000',
        ]);

        // Raising a pick list commits stock (it reserves), so it takes the same
        // bar as acting on the document it fulfils.
        $this->guardVoucherManage($request, (int) $data['voucher_id'], 'raise a pick list for this delivery');

        return $this->success(
            $this->fulfilment->createFromVoucher(
                (int) $data['voucher_id'], $data, $request->user()->tenant_id, $request->user()->id
            ),
            'Pick list created', 201
        );
    }

    public function assign(Request $request, int $id)
    {
        $this->denyExternal($request);
        $data = $request->validate(['assigned_to' => 'nullable|integer|exists:users,id']);

        return $this->success(
            $this->fulfilment->assign($id, $data['assigned_to'] ?? null, $request->user()->tenant_id, $request->user()->id),
            'Pick list assigned'
        );
    }

    public function pick(Request $request, int $id)
    {
        $this->denyExternal($request);
        $data = $request->validate([
            'lines'              => 'required|array|min:1',
            'lines.*.id'         => 'required|integer',
            'lines.*.picked_qty' => 'required|numeric|min:0',
            'lines.*.note'       => 'nullable|string|max:255',
        ]);

        return $this->success(
            $this->fulfilment->pick($id, $data['lines'], $request->user()->tenant_id, $request->user()->id),
            'Picking saved'
        );
    }

    public function completePick(Request $request, int $id)
    {
        $this->denyExternal($request);

        return $this->success(
            $this->fulfilment->completePick($id, $request->user()->tenant_id, $request->user()->id),
            'Picking finished — ready to pack'
        );
    }

    public function pack(Request $request, int $id)
    {
        $this->denyExternal($request);
        $data = $request->validate([
            'lines'              => 'required|array|min:1',
            'lines.*.id'         => 'required|integer',
            'lines.*.packed_qty' => 'required|numeric|min:0',
        ]);

        return $this->success(
            $this->fulfilment->pack($id, $data['lines'], $request->user()->tenant_id, $request->user()->id),
            'Packing saved'
        );
    }

    /**
     * Ship. This posts the delivery voucher, which is what moves the stock, so
     * it takes the same bar as posting that document by hand.
     */
    public function ship(Request $request, int $id)
    {
        $this->denyExternal($request);

        $list = $this->fulfilment->show($id, $request->user()->tenant_id);
        if ($list->voucher_id) {
            $this->guardVoucherManage($request, (int) $list->voucher_id, 'ship this parcel');
        }

        $data = $request->validate([
            'carrier'         => 'nullable|string|max:60',
            'tracking_number' => 'nullable|string|max:80',
            'parcel_weight'   => 'nullable|numeric|min:0',
            'parcel_count'    => 'nullable|integer|min:1|max:999',
        ]);

        return $this->success(
            $this->fulfilment->ship($id, $data, $request->user()->tenant_id, $request->user()->id),
            'Shipped — stock has left the warehouse'
        );
    }

    public function deliver(Request $request, int $id)
    {
        $this->denyExternal($request);
        $data = $request->validate([
            'received_by'  => 'nullable|string|max:120',
            'delivered_at' => 'nullable|date',
        ]);

        return $this->success(
            $this->fulfilment->markDelivered($id, $data, $request->user()->tenant_id, $request->user()->id),
            'Marked as delivered'
        );
    }

    public function cancel(Request $request, int $id)
    {
        $this->denyExternal($request);

        return $this->success(
            $this->fulfilment->cancel($id, $request->user()->tenant_id, $request->user()->id),
            'Pick list cancelled — the reserved stock is free again'
        );
    }

    /** Which carriers exist, and which are actually connected. */
    public function carriers(Request $request)
    {
        $this->denyExternal($request);

        return $this->success($this->carriers->available(), 'Carriers retrieved');
    }
}
