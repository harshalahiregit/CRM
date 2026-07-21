<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Inventory\StockMovementRequest;
use App\Services\Inventory\StockService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(private StockService $stock)
    {
    }

    /** KPI tiles for the Inventory dashboard. */
    public function summary(Request $request)
    {
        $this->denyExternal($request);

        return $this->success(
            $this->stock->summary(
                $request->user()->tenant_id,
                $this->isAdmin($request),
                $request->user()->id,
            ),
            'Summary computed'
        );
    }

    /** Where one product sits, across every warehouse/bin. */
    public function levels(Request $request, int $product)
    {
        $this->denyExternal($request);
        $tenantId = $request->user()->tenant_id;

        return $this->success([
            'levels' => $this->stock->levelsFor($product, $tenantId),
            'totals' => $this->stock->totalsFor($product, $tenantId),
        ], 'Stock levels retrieved');
    }

    /** The audited ledger for one product. */
    public function history(Request $request, int $product)
    {
        $this->denyExternal($request);
        $limit = (int) $request->integer('limit', 100);

        return $this->success(
            $this->stock->history($product, $request->user()->tenant_id, min(max($limit, 1), 500)),
            'Movement history retrieved'
        );
    }

    /** The whole-module audit ledger (blueprint §7) — every movement, filterable. */
    public function ledger(Request $request)
    {
        $this->denyExternal($request);

        $filters = $request->validate([
            'product_id'   => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'actor_id'     => 'nullable|integer|min:1',
            'type'         => 'nullable|string|max:30',
            'from'         => 'nullable|date',
            'to'           => 'nullable|date|after_or_equal:from',
            'search'       => 'nullable|string|max:120',
        ]);

        $limit = min(max((int) $request->integer('limit', 200), 1), 500);
        $offset = max((int) $request->integer('offset', 0), 0);

        return $this->success(
            $this->stock->ledger($request->user()->tenant_id, $filters, $limit, $offset),
            'Inventory history retrieved'
        );
    }

    /** Record any movement: receive / issue / damage / transfer / … */
    public function move(StockMovementRequest $request)
    {
        $this->denyExternal($request);
        $movement = $this->stock->record($request->validated(), $request->user()->tenant_id, $request->user()->id);

        return $this->success($movement, 'Stock updated', 201);
    }

    /** Set an exact counted figure; the delta is recorded as an adjustment. */
    public function adjust(Request $request)
    {
        $this->denyExternal($request);
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'product_id'   => ['required', 'integer', Rule::exists('inventory_products', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'warehouse_id' => ['required', 'integer', Rule::exists('inventory_warehouses', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'location_id'  => ['nullable', 'integer', Rule::exists('inventory_locations', 'id')->where('tenant_id', $tenantId)],
            'quantity'     => 'required|numeric|min:0',
            'reason'       => 'nullable|string|max:255',
        ]);

        $movement = $this->stock->adjustTo(
            $data['product_id'], $data['warehouse_id'], (float) $data['quantity'],
            $tenantId, $request->user()->id, $data['reason'] ?? null, $data['location_id'] ?? null,
        );

        return $this->success($movement, $movement ? 'Stock adjusted' : 'Count already matched — nothing to change');
    }

    /** Products at or below their reorder point. */
    public function lowStock(Request $request)
    {
        $this->denyExternal($request);

        return $this->success($this->stock->lowStock($request->user()->tenant_id), 'Low stock retrieved');
    }
}
