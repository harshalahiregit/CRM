<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\Movement;
use App\Models\Inventory\Product;
use App\Models\Inventory\Stock;
use App\Models\Inventory\Warehouse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The stock ledger engine.
 *
 * Every balance change goes through apply(): it moves the number AND writes the
 * movement row that explains it, inside one transaction. Nothing else in the
 * module is allowed to touch inventory_stock directly, which is what keeps
 * "why is this 7?" answerable for any product, forever.
 */
class StockService
{
    /* ── Reads ──────────────────────────────────────────────────── */

    /** Stock rows for one product across every warehouse/bin. */
    public function levelsFor(int $productId, int $tenantId): Collection
    {
        return Stock::forTenant($tenantId)
            ->where('product_id', $productId)
            ->with(['warehouse:id,name,type', 'location:id,name,type'])
            ->get();
    }

    /** Total on-hand / reserved / available for a product across all sites. */
    public function totalsFor(int $productId, int $tenantId): array
    {
        $row = Stock::forTenant($tenantId)->where('product_id', $productId)
            ->selectRaw('COALESCE(SUM(quantity),0) as qty, COALESCE(SUM(reserved_quantity),0) as res')
            ->first();

        $qty = (float) ($row->qty ?? 0);
        $res = (float) ($row->res ?? 0);

        return ['quantity' => $qty, 'reserved' => $res, 'available' => $qty - $res];
    }

    /** Ledger history for a product, newest first. */
    public function history(int $productId, int $tenantId, int $limit = 100): Collection
    {
        return Movement::forTenant($tenantId)
            ->where('product_id', $productId)
            ->with(['actor:id,name', 'fromWarehouse:id,name', 'toWarehouse:id,name'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limit)->get();
    }

    /* ── Writes ─────────────────────────────────────────────────── */

    /**
     * Apply one movement and update the affected balance(s).
     *
     * $d: product_id, type, quantity (>0), warehouse_id | from_warehouse_id +
     *     to_warehouse_id (transfer), location_id, reason, notes,
     *     reference_type, reference_id.
     */
    public function record(array $d, int $tenantId, ?int $actorId = null): Movement
    {
        $type = $d['type'];
        $qty = round((float) $d['quantity'], 3);

        if ($qty <= 0) {
            throw new BusinessException('Quantity must be greater than zero.', 422);
        }

        $product = Product::forTenant($tenantId)->find($d['product_id'])
            ?? throw new BusinessException('That product does not exist.', 404);

        // Blueprint §2: an item flagged "do not update inventory numbers" sits
        // outside the stock math entirely, so recording a movement against it
        // would produce a balance nobody intends to keep.
        if ($product->without_checking_warehouse) {
            throw new BusinessException('This item is set to not update inventory numbers, so its stock cannot be moved.', 422);
        }

        // 'adjustment' can go either way, so the caller may override the implied
        // direction. Everything else takes its direction from the type.
        $direction = $type === 'transfer'
            ? 'transfer'
            : ($d['direction'] ?? Movement::TYPES[$type] ?? null);

        if (! in_array($direction, ['in', 'out', 'transfer'], true)) {
            throw new BusinessException('Unknown movement type.', 422);
        }

        return DB::transaction(function () use ($d, $type, $qty, $direction, $product, $tenantId, $actorId) {
            $balanceAfter = null;

            if ($direction === 'transfer') {
                $from = (int) ($d['from_warehouse_id'] ?? 0);
                $to = (int) ($d['to_warehouse_id'] ?? 0);
                if (! $from || ! $to || $from === $to) {
                    throw new BusinessException('A transfer needs a different source and destination warehouse.', 422);
                }
                $this->assertWarehouse($from, $tenantId);
                $this->assertWarehouse($to, $tenantId);

                $this->move($product->id, $from, $d['from_location_id'] ?? null, -$qty, $tenantId);
                $balanceAfter = $this->move($product->id, $to, $d['to_location_id'] ?? null, $qty, $tenantId);
            } else {
                $wh = (int) ($d['warehouse_id'] ?? 0);
                $this->assertWarehouse($wh, $tenantId);
                $delta = $direction === 'in' ? $qty : -$qty;
                $balanceAfter = $this->move($product->id, $wh, $d['location_id'] ?? null, $delta, $tenantId);
            }

            return Movement::create([
                'tenant_id'         => $tenantId,
                'product_id'        => $product->id,
                'type'              => $type,
                'direction'         => $direction,
                'quantity'          => $qty,
                'from_warehouse_id' => $direction === 'transfer' ? $d['from_warehouse_id'] : ($direction === 'out' ? $d['warehouse_id'] : null),
                'to_warehouse_id'   => $direction === 'transfer' ? $d['to_warehouse_id'] : ($direction === 'in' ? $d['warehouse_id'] : null),
                'from_location_id'  => $d['from_location_id'] ?? ($direction === 'out' ? ($d['location_id'] ?? null) : null),
                'to_location_id'    => $d['to_location_id'] ?? ($direction === 'in' ? ($d['location_id'] ?? null) : null),
                'balance_after'     => $balanceAfter,
                'reason'            => $d['reason'] ?? null,
                'notes'             => $d['notes'] ?? null,
                'reference_type'    => $d['reference_type'] ?? null,
                'reference_id'      => $d['reference_id'] ?? null,
                'actor_id'          => $actorId,
                'created_at'        => now(),
            ]);
        });
    }

    /**
     * Set a product's balance at a site to an exact figure (a count correction).
     * Recorded as an adjustment in whichever direction closes the gap, so the
     * ledger shows the delta rather than a silent overwrite.
     */
    public function adjustTo(int $productId, int $warehouseId, float $newQty, int $tenantId, ?int $actorId, ?string $reason = null, ?int $locationId = null, array $ref = []): ?Movement
    {
        if ($newQty < 0) {
            throw new BusinessException('Counted quantity cannot be negative.', 422);
        }

        $current = (float) ($this->stockRow($productId, $warehouseId, $locationId, $tenantId, false)?->quantity ?? 0);
        $delta = round($newQty - $current, 3);

        if ($delta === 0.0) {
            return null;   // nothing to record — the count matched
        }

        return $this->record([
            // $ref carries reference_type/reference_id so a recount raised from a
            // voucher still points back at its document, like every other movement.
            ...$ref,
            'product_id'   => $productId,
            'warehouse_id' => $warehouseId,
            'location_id'  => $locationId,
            'type'         => 'adjustment',
            // The sign of the gap decides whether we add or remove.
            'direction'    => $delta > 0 ? 'in' : 'out',
            'quantity'     => abs($delta),
            'reason'       => $reason ?? 'Stock count correction',
        ], $tenantId, $actorId);
    }

    /* ── Alerts / dashboard ─────────────────────────────────────── */

    /** Products at or below their reorder point (or min stock when unset). */
    public function lowStock(int $tenantId): Collection
    {
        return Product::forTenant($tenantId)->where('status', 'active')
            // Items excluded from stock math can't be "low" — they have no stock.
            ->where('without_checking_warehouse', false)
            ->select('inventory_products.*')
            ->selectSub(
                Stock::selectRaw('COALESCE(SUM(quantity),0)')
                    ->whereColumn('inventory_stock.product_id', 'inventory_products.id'),
                'on_hand'
            )
            ->get()
            ->filter(function (Product $p) {
                $threshold = (float) ($p->reorder_point ?: $p->min_stock);
                return $threshold > 0 && (float) $p->on_hand <= $threshold;
            })
            ->values();
    }

    /** KPI tiles for the dashboard. */
    public function summary(int $tenantId): array
    {
        $totals = Stock::forTenant($tenantId)
            ->selectRaw('COALESCE(SUM(quantity),0) as qty, COALESCE(SUM(reserved_quantity),0) as res')->first();

        $qty = (float) ($totals->qty ?? 0);
        $reserved = (float) ($totals->res ?? 0);

        // Valuation reads cost_price off the product, so it needs the join.
        $value = (float) (DB::table('inventory_stock')
            ->join('inventory_products', 'inventory_products.id', '=', 'inventory_stock.product_id')
            ->where('inventory_stock.tenant_id', $tenantId)
            ->whereNull('inventory_products.deleted_at')
            ->selectRaw('COALESCE(SUM(inventory_stock.quantity * COALESCE(inventory_products.cost_price,0)),0) as v')
            ->value('v') ?? 0);

        return [
            'inventory_value' => round($value, 2),
            'total_quantity'  => $qty,
            'reserved'        => $reserved,
            'available'       => $qty - $reserved,
            'products'        => Product::forTenant($tenantId)->where('status', 'active')->count(),
            'warehouses'      => Warehouse::forTenant($tenantId)->where('status', 'active')->count(),
            'low_stock'       => $this->lowStock($tenantId)->count(),
            'out_of_stock'    => $this->outOfStockCount($tenantId),
            'movements_today' => Movement::forTenant($tenantId)->whereDate('created_at', now()->toDateString())->count(),
        ];
    }

    private function outOfStockCount(int $tenantId): int
    {
        return Product::forTenant($tenantId)->where('status', 'active')
            ->where('without_checking_warehouse', false)
            ->whereNotExists(fn ($q) => $q->from('inventory_stock')
                ->whereColumn('inventory_stock.product_id', 'inventory_products.id')
                ->where('inventory_stock.quantity', '>', 0))
            ->count();
    }

    /* ── Internals ──────────────────────────────────────────────── */

    /** Nudge one balance by $delta and return the new figure. */
    private function move(int $productId, int $warehouseId, ?int $locationId, float $delta, int $tenantId): float
    {
        $row = $this->stockRow($productId, $warehouseId, $locationId, $tenantId, true);
        $next = round((float) $row->quantity + $delta, 3);

        if ($next < 0) {
            throw new BusinessException(
                'Not enough stock at that warehouse — on hand is '.rtrim(rtrim(number_format((float) $row->quantity, 3, '.', ''), '0'), '.').'.',
                422
            );
        }

        $row->quantity = $next;
        $row->save();

        return $next;
    }

    /**
     * The balance row for a place. Nullable location_id can't be enforced by a
     * unique index in SQLite (NULLs compare distinct), so the row is resolved
     * here and created on demand inside the caller's transaction.
     */
    private function stockRow(int $productId, int $warehouseId, ?int $locationId, int $tenantId, bool $create): ?Stock
    {
        $q = Stock::forTenant($tenantId)
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId);

        $q = $locationId ? $q->where('location_id', $locationId) : $q->whereNull('location_id');

        $row = $q->first();

        if (! $row && $create) {
            $row = Stock::create([
                'tenant_id'    => $tenantId,
                'product_id'   => $productId,
                'warehouse_id' => $warehouseId,
                'location_id'  => $locationId,
                'quantity'     => 0,
            ]);
        }

        return $row;
    }

    private function assertWarehouse(int $id, int $tenantId): void
    {
        if (! $id || ! Warehouse::forTenant($tenantId)->whereKey($id)->exists()) {
            throw new BusinessException('That warehouse does not exist.', 404);
        }
    }
}
