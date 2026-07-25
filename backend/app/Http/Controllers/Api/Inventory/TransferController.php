<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Inventory\Transfer;
use App\Services\Inventory\TransferService;
use Illuminate\Http\Request;

/**
 * Transfers with a real in-transit state (§15).
 *
 * The barrier follows the physical job rather than one blanket rule:
 *
 *  • DISPATCHING takes stock off the source's shelf, so it takes the source
 *    warehouse's bar — admin, or that site's manager.
 *  • RECEIVING puts stock on the destination's shelf, so it takes the
 *    destination's. The whole point of a transfer is that two different people
 *    are involved; requiring one person to hold both ends would defeat it.
 *  • WRITING OFF a transit loss is money disappearing, so it needs either end's
 *    manager or an admin, and it always notifies both.
 */
class TransferController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(private TransferService $transfers)
    {
    }

    public function index(Request $request)
    {
        $this->denyExternal($request);

        $f = $request->only(['status', 'from_warehouse_id', 'to_warehouse_id', 'search']);
        if ($request->boolean('overdue')) {
            $f['overdue'] = 1;
        }
        // "Inbound to me" resolves to the sites this person manages, so it can
        // never be pointed at somebody else's warehouse from the query string.
        if ($request->boolean('inbound')) {
            $f['inbound_to'] = $this->managedWarehouseIds($request)[0] ?? 0;
        }

        return $this->success($this->transfers->list($request->user()->tenant_id, $f), 'Transfers retrieved');
    }

    public function show(Request $request, int $id)
    {
        $this->denyExternal($request);

        $transfer = $this->transfers->show($id, $request->user()->tenant_id);

        return $this->success([
            'transfer' => $transfer,
            'summary'  => $this->transfers->summaryOf($transfer),
            // The server decides what this viewer may do, so the UI never draws
            // a button that would come back 403.
            'can_dispatch' => $transfer->status === 'draft' && $this->mayUse($request, (int) $transfer->from_warehouse_id),
            'can_receive'  => $transfer->status === 'in_transit' && $this->mayUse($request, (int) $transfer->to_warehouse_id),
            'can_write_off' => $transfer->status === 'in_transit'
                && ($this->mayUse($request, (int) $transfer->from_warehouse_id) || $this->mayUse($request, (int) $transfer->to_warehouse_id)),
        ], 'Transfer retrieved');
    }

    /** Raise a consignment from an internal delivery note. */
    public function store(Request $request)
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'voucher_id'        => 'nullable|integer|min:1',
            'from_warehouse_id' => 'nullable|integer|min:1',
            'to_warehouse_id'   => 'nullable|integer|min:1',
            'lines'             => 'nullable|array',
            'lines.*.product_id' => 'required_with:lines|integer|min:1',
            'lines.*.quantity'  => 'required_with:lines|numeric|min:0',
            'expected_at'       => 'nullable|date',
            'carrier'           => 'nullable|string|max:60',
            'tracking_number'   => 'nullable|string|max:80',
            'vehicle_no'        => 'nullable|string|max:40',
            'driver_name'       => 'nullable|string|max:120',
            'driver_phone'      => 'nullable|string|max:30',
            'note'              => 'nullable|string|max:1000',
        ]);

        $tenantId = $request->user()->tenant_id;

        if (! empty($data['voucher_id'])) {
            $this->guardVoucherManage($request, (int) $data['voucher_id'], 'send this note as a consignment');

            return $this->success(
                $this->transfers->createFromVoucher((int) $data['voucher_id'], $data, $tenantId, $request->user()->id),
                'Consignment raised', 201
            );
        }

        $this->guardWarehouse($request, (int) ($data['from_warehouse_id'] ?? 0), 'send stock out of this warehouse');

        return $this->success(
            $this->transfers->create($data, $tenantId, $request->user()->id),
            'Consignment raised', 201
        );
    }

    public function dispatchOut(Request $request, int $id)
    {
        $transfer = $this->transfers->show($id, $request->user()->tenant_id);
        $this->guardWarehouse($request, (int) $transfer->from_warehouse_id, 'dispatch stock from this warehouse');

        $data = $request->validate([
            'expected_at'     => 'nullable|date',
            'carrier'         => 'nullable|string|max:60',
            'tracking_number' => 'nullable|string|max:80',
            'vehicle_no'      => 'nullable|string|max:40',
            'driver_name'     => 'nullable|string|max:120',
            'driver_phone'    => 'nullable|string|max:30',
        ]);

        return $this->success(
            $this->transfers->dispatch($id, $data, $request->user()->tenant_id, $request->user()->id),
            'Dispatched — the stock is now in transit'
        );
    }

    public function receive(Request $request, int $id)
    {
        $transfer = $this->transfers->show($id, $request->user()->tenant_id);
        $this->guardWarehouse($request, (int) $transfer->to_warehouse_id, 'receive stock at this warehouse');

        $data = $request->validate([
            'lines'                  => 'required|array|min:1',
            'lines.*.id'             => 'required|integer',
            'lines.*.received_qty'   => 'required|numeric|min:0',
            'lines.*.to_location_id' => 'nullable|integer|min:1',
            'lines.*.note'           => 'nullable|string|max:255',
        ]);

        return $this->success(
            $this->transfers->receive($id, $data['lines'], $data, $request->user()->tenant_id, $request->user()->id),
            'Received'
        );
    }

    /** Sign off what never arrived. */
    public function writeOff(Request $request, int $id)
    {
        $transfer = $this->transfers->show($id, $request->user()->tenant_id);

        abort_unless(
            $this->isAdmin($request)
                || $this->mayUse($request, (int) $transfer->from_warehouse_id)
                || $this->mayUse($request, (int) $transfer->to_warehouse_id),
            403,
            'Only an admin or one of the two warehouse managers can write off a transit loss.'
        );

        $data = $request->validate([
            'lines'             => 'required|array|min:1',
            'lines.*.id'        => 'required|integer',
            'lines.*.lost_qty'  => 'required|numeric|min:0',
            'reason'            => 'required|string|max:1000',
        ]);

        return $this->success(
            $this->transfers->writeOffLoss($id, $data['lines'], $data['reason'], $request->user()->tenant_id, $request->user()->id),
            'Written off as a transit loss'
        );
    }

    public function cancel(Request $request, int $id)
    {
        $transfer = $this->transfers->show($id, $request->user()->tenant_id);
        // Turning a lorry round puts stock back on the SOURCE's shelf, so it is
        // the source's decision.
        $this->guardWarehouse($request, (int) $transfer->from_warehouse_id, 'turn this consignment round');

        return $this->success(
            $this->transfers->cancel($id, $request->user()->tenant_id, $request->user()->id),
            $transfer->status === 'in_transit'
                ? 'Turned round — the stock is back at its source'
                : 'Consignment cancelled'
        );
    }

    /**
     * Does the transit warehouse's balance match what the open consignments say
     * is still on the road? Admin-only: it is an audit of the module itself.
     */
    public function reconcile(Request $request)
    {
        $this->requireAdmin($request, 'reconcile stock in transit');

        return $this->success($this->transfers->reconcile($request->user()->tenant_id), 'Transit reconciled');
    }

    /* ── Barriers ───────────────────────────────────────────────── */

    private function mayUse(Request $request, int $warehouseId): bool
    {
        return $this->isAdmin($request) || in_array($warehouseId, $this->managedWarehouseIds($request), true);
    }

    private function guardWarehouse(Request $request, int $warehouseId, string $what): void
    {
        $this->denyExternal($request);
        abort_unless($this->mayUse($request, $warehouseId), 403, "Only an admin or the warehouse manager can {$what}.");
    }
}
