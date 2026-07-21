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
    public function __construct(private InventoryNotifier $notifier)
    {
    }

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

    /**
     * The whole-module audit ledger (blueprint §7) — every receipt, issue,
     * transfer and adjustment across all products, newest first and filterable.
     * `opening` is derived rather than stored: a movement's closing balance minus
     * the delta it applied IS the balance it started from, so each line reads
     * opening → closing without a second query.
     */
    public function ledger(int $tenantId, array $f = [], int $limit = 200, int $offset = 0): array
    {
        $q = Movement::forTenant($tenantId)
            ->with(['product:id,sku,name,base_unit', 'actor:id,name', 'fromWarehouse:id,name,code', 'toWarehouse:id,name,code']);

        foreach (['product_id', 'warehouse_id', 'type', 'actor_id'] as $key) {
            if (empty($f[$key])) {
                continue;
            }
            match ($key) {
                'warehouse_id' => $q->where(fn ($w) => $w->where('from_warehouse_id', $f[$key])->orWhere('to_warehouse_id', $f[$key])),
                default        => $q->where($key, $f[$key]),
            };
        }

        if (! empty($f['from'])) {
            $q->whereDate('created_at', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->whereDate('created_at', '<=', $f['to']);
        }
        if (! empty($f['search'])) {
            $term = '%'.$f['search'].'%';
            $q->where(fn ($w) => $w->where('batch_no', 'like', $term)
                ->orWhere('serial_no', 'like', $term)
                ->orWhere('reason', 'like', $term)
                ->orWhere('notes', 'like', $term)
                ->orWhereHas('product', fn ($p) => $p->where('sku', 'like', $term)->orWhere('name', 'like', $term)));
        }

        $total = (clone $q)->count();

        $rows = $q->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limit)->offset($offset)->get()
            ->map(function (Movement $m) {
                $closing = $m->balance_after !== null ? (float) $m->balance_after : null;
                $delta = $m->direction === 'in' ? (float) $m->quantity
                    : ($m->direction === 'out' ? -(float) $m->quantity : (float) $m->quantity);

                return [
                    'id'             => $m->id,
                    'date'           => $m->created_at,
                    'type'           => $m->type,
                    'direction'      => $m->direction,
                    'quantity'       => (float) $m->quantity,
                    'opening'        => $closing !== null ? round($closing - $delta, 3) : null,
                    'closing'        => $closing,
                    'product'        => $m->product ? ['id' => $m->product->id, 'sku' => $m->product->sku, 'name' => $m->product->name, 'unit' => $m->product->base_unit] : null,
                    'from_warehouse' => $m->fromWarehouse?->only(['id', 'name', 'code']),
                    'to_warehouse'   => $m->toWarehouse?->only(['id', 'name', 'code']),
                    'batch_no'       => $m->batch_no,
                    'serial_no'      => $m->serial_no,
                    'reason'         => $m->reason,
                    'notes'          => $m->notes,
                    'reference'      => $m->reference_type ? "{$m->reference_type}#{$m->reference_id}" : null,
                    'actor'          => $m->actor?->only(['id', 'name']),
                ];
            });

        return ['rows' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
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

        // Only for hand-made moves. A voucher line reaches here too, but the
        // voucher groups its own alert across all its lines — checking here as
        // well would send one email per line for the same posting.
        $standalone = ! in_array($d['reference_type'] ?? null, ['voucher', 'voucher_reversal'], true);
        $before = $standalone ? $this->onHandTotal($product->id, $tenantId) : null;

        $movement = DB::transaction(function () use ($d, $type, $qty, $direction, $product, $tenantId, $actorId) {
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

        if ($standalone) {
            $this->alertIfCrossed($product, $before, $tenantId, $d['warehouse_id'] ?? ($d['to_warehouse_id'] ?? null), $actorId);
        }

        return $movement;
    }

    /**
     * Tell the right people if this move just pushed an item to or below its
     * reorder point. Edge-triggered against the pre-move balance, so an item
     * that was already low doesn't re-alert on every subsequent issue.
     *
     * Best-effort by design — the movement is already written and the ledger
     * already explains it; a failed alert must not undo that.
     */
    private function alertIfCrossed(Product $product, ?float $before, int $tenantId, $warehouseId, ?int $actorId): void
    {
        try {
            if ($before === null || $product->status !== 'active') {
                return;
            }

            $threshold = (float) ($product->reorder_point ?: $product->min_stock);
            if ($threshold <= 0) {
                return;   // nobody set a level, so there's no line to cross
            }

            $now = $this->onHandTotal($product->id, $tenantId);
            if ($before <= $threshold || $now > $threshold) {
                return;
            }

            $this->notifier->stockCrossedThreshold(
                $tenantId,
                [['product' => $product, 'on_hand' => $now, 'threshold' => $threshold]],
                $warehouseId,
                (int) $actorId,
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                "Inventory stock alert failed for product {$product->id}: {$e->getMessage()}"
            );
        }
    }

    /** Total on-hand for one product across every warehouse. */
    private function onHandTotal(int $productId, int $tenantId): float
    {
        return (float) Stock::forTenant($tenantId)->where('product_id', $productId)->sum('quantity');
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

    /**
     * KPI tiles for the dashboard.
     *
     * Stock levels are shared operational truth — scoping "what's on the shelf"
     * per person would tell a storekeeper there are 0 units of something they
     * can physically see. So those figures are identical for everyone.
     *
     * Two things are NOT flat:
     *  • inventory VALUE is a finance figure, so it's admin-only (null otherwise);
     *  • a `my` block gives each viewer their own work — what they moved today,
     *    drafts they still owe, stock they've committed.
     */
    public function summary(int $tenantId, bool $isAdmin = true, ?int $userId = null): array
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

        $today = now()->toDateString();

        return [
            'is_admin' => $isAdmin,

            // Finance figure — admins only. Null (not 0) so the UI can tell
            // "you may not see this" apart from "it's worth nothing".
            'inventory_value' => $isAdmin ? round($value, 2) : null,

            // Shared operational truth — the same for everyone, deliberately.
            'total_quantity'  => $qty,
            'reserved'        => $reserved,
            'available'       => $qty - $reserved,
            'products'        => Product::forTenant($tenantId)->where('status', 'active')->count(),
            'warehouses'      => Warehouse::forTenant($tenantId)->where('status', 'active')->count(),
            'low_stock'       => $this->lowStock($tenantId)->count(),
            'out_of_stock'    => $this->outOfStockCount($tenantId),
            'movements_today' => Movement::forTenant($tenantId)->whereDate('created_at', $today)->count(),

            // Admin-only: work sitting with OTHER people. An unposted draft is
            // stock that hasn't moved yet, and until now only its author could
            // see it — which is exactly the thing a manager needs surfaced.
            'team' => $isAdmin ? [
                'open_drafts' => \App\Models\Inventory\Voucher::forTenant($tenantId)
                    ->where('status', 'draft')
                    ->when($userId, fn ($q) => $q->where('created_by', '!=', $userId))
                    ->count(),
                'reservations' => \App\Models\Inventory\Reservation::forTenant($tenantId)
                    ->where('status', 'active')
                    ->when($userId, fn ($q) => $q->where('created_by', '!=', $userId))
                    ->count(),
                'active_people' => Movement::forTenant($tenantId)
                    ->whereDate('created_at', $today)->whereNotNull('actor_id')
                    ->distinct()->count('actor_id'),
            ] : null,

            // …and the viewer's own work.
            'my' => $userId ? [
                'movements_today' => Movement::forTenant($tenantId)
                    ->where('actor_id', $userId)->whereDate('created_at', $today)->count(),
                // Documents this person raised but hasn't posted — stock they still owe.
                'open_drafts' => \App\Models\Inventory\Voucher::forTenant($tenantId)
                    ->where('created_by', $userId)->where('status', 'draft')->count(),
                'reservations' => \App\Models\Inventory\Reservation::forTenant($tenantId)
                    ->where('created_by', $userId)->where('status', 'active')->count(),
            ] : null,
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
