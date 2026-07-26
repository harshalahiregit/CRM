<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Inventory\InventoryNotifier;
use App\Services\Inventory\PurchaseOrderService;
use App\Services\Inventory\StockService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Purchase orders + auto-reorder. Internal staff raise and manage POs; deleting
 * is not offered (cancel instead, so the trail survives). Approving is admin-only
 * — committing the tenant to spend is a master-data-grade decision.
 */
class PurchaseOrderController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(
        private PurchaseOrderService $orders,
        private InventoryNotifier $notifier,
    ) {
    }

    public function index(Request $request)
    {
        $this->denyExternal($request);

        return $this->success(
            $this->orders->list($request->user()->tenant_id, $request->only(['status', 'vendor_id', 'source', 'search'])),
            'Purchase orders retrieved'
        );
    }

    public function show(Request $request, int $id)
    {
        $this->denyExternal($request);

        return $this->success($this->orders->show($id, $request->user()->tenant_id), 'Purchase order retrieved');
    }

    public function store(Request $request)
    {
        $this->denyExternal($request);
        $po = $this->orders->create($this->validated($request), $request->user()->tenant_id, $request->user()->id);

        return $this->success($this->orders->show($po->id, $request->user()->tenant_id), 'Purchase order created', 201);
    }

    public function update(Request $request, int $id)
    {
        $this->denyExternal($request);
        $po = $this->orders->update($id, $this->validated($request, false), $request->user()->tenant_id);

        return $this->success($this->orders->show($po->id, $request->user()->tenant_id), 'Purchase order updated');
    }

    public function submit(Request $request, int $id)
    {
        $this->denyExternal($request);

        return $this->success($this->orders->submit($id, $request->user()->tenant_id), 'Purchase order submitted for approval');
    }

    public function approve(Request $request, int $id)
    {
        $this->requireAdmin($request, 'approve a purchase order');

        return $this->success($this->orders->approve($id, $request->user()->tenant_id, $request->user()->id), 'Purchase order approved');
    }

    public function markSent(Request $request, int $id)
    {
        $this->denyExternal($request);

        return $this->success($this->orders->markSent($id, $request->user()->tenant_id), 'Purchase order marked as sent');
    }

    public function receive(Request $request, int $id)
    {
        $this->denyExternal($request);
        $data = $request->validate(['received' => 'required|array']);

        return $this->success(
            $this->orders->receive($id, $data['received'], $request->user()->tenant_id),
            'Receipt recorded against the purchase order'
        );
    }

    public function cancel(Request $request, int $id)
    {
        $this->denyExternal($request);

        return $this->success($this->orders->cancel($id, $request->user()->tenant_id), 'Purchase order cancelled');
    }

    /** Draft one PO per preferred vendor for everything below its reorder point. */
    public function generate(Request $request, StockService $stock)
    {
        $this->denyExternal($request);
        $result = $this->orders->generateFromLowStock($request->user()->tenant_id, $request->user()->id, $stock);

        $this->notifier->purchaseOrdersGenerated(
            $request->user()->tenant_id,
            $result['created'],
            count($result['skipped']),
            $request->user()->id,
        );

        $msg = count($result['created']) === 0
            ? 'Nothing to reorder — no items are below their reorder point (or none have a vendor).'
            : count($result['created']).' draft purchase order(s) created from low-stock items.';

        return $this->success($result, $msg);
    }

    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'vendor_id'         => ($creating ? 'required' : 'sometimes').'|integer',
            'warehouse_id'      => 'nullable|integer',
            'currency'          => 'nullable|string|max:8',
            'order_date'        => 'nullable|date',
            'expected_date'     => 'nullable|date',
            'notes'             => 'nullable|string|max:2000',
            'lines'             => 'sometimes|array',
            'lines.*.product_id'  => 'nullable|integer',
            'lines.*.description' => 'nullable|string|max:255',
            'lines.*.qty'         => 'required_with:lines|numeric|min:0',
            'lines.*.unit_price'  => 'nullable|numeric|min:0',
            'lines.*.tax_rate'    => 'nullable|numeric|min:0|max:100',
        ]);
    }
}
