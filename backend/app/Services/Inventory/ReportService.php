<?php

namespace App\Services\Inventory;

use App\Models\Inventory\Product;
use App\Models\Inventory\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Inventory reporting (blueprint §8) — the three reports the module is expected
 * to produce, all derived from the movement ledger and the current balances.
 * Nothing is stored: every figure is recomputed from inventory_movements /
 * inventory_stock, so a report can never drift from the ledger that backs it.
 *
 *  • stockSummary — opening / in / out / closing per product for a date window
 *  • valuation    — what the shelf is worth right now, at cost and at sale price
 *  • analysis     — consumption, turnover and fast/slow/dead classification
 *
 * All three accept the same filter shape: from, to, warehouse_id, product_id,
 * category_id, actor_id.
 */
class ReportService
{
    /* ── Shared ledger maths ────────────────────────────────────── */

    /**
     * The signed-delta SQL for a movement, relative to the reporting scope.
     *
     * Warehouse-scoped: a movement counts as +qty when it lands in that
     * warehouse and -qty when it leaves it — which handles receipts, issues AND
     * transfers uniformly, because record() always stamps to_/from_warehouse_id.
     * Unscoped: transfers net to zero across the business, so only in/out count.
     */
    private function deltaExpr(?int $warehouseId): string
    {
        return $warehouseId
            ? "CASE WHEN to_warehouse_id = {$warehouseId} THEN quantity WHEN from_warehouse_id = {$warehouseId} THEN -quantity ELSE 0 END"
            : "CASE WHEN direction = 'in' THEN quantity WHEN direction = 'out' THEN -quantity ELSE 0 END";
    }

    private function inExpr(?int $warehouseId): string
    {
        return $warehouseId
            ? "CASE WHEN to_warehouse_id = {$warehouseId} THEN quantity ELSE 0 END"
            : "CASE WHEN direction = 'in' THEN quantity ELSE 0 END";
    }

    private function outExpr(?int $warehouseId): string
    {
        return $warehouseId
            ? "CASE WHEN from_warehouse_id = {$warehouseId} THEN quantity ELSE 0 END"
            : "CASE WHEN direction = 'out' THEN quantity ELSE 0 END";
    }

    /** Normalise the incoming filter bag into the pieces every report uses. */
    private function scope(array $f): array
    {
        return [
            'warehouse_id' => ! empty($f['warehouse_id']) ? (int) $f['warehouse_id'] : null,
            'product_id'   => ! empty($f['product_id']) ? (int) $f['product_id'] : null,
            'category_id'  => ! empty($f['category_id']) ? (int) $f['category_id'] : null,
            'actor_id'     => ! empty($f['actor_id']) ? (int) $f['actor_id'] : null,
            'from'         => $f['from'] ?? null,
            'to'           => $f['to'] ?? null,
        ];
    }

    /** Base movement query with the tenant + optional staff/product filters applied. */
    private function movements(int $tenantId, array $s)
    {
        $q = DB::table('inventory_movements')->where('tenant_id', $tenantId);

        if ($s['actor_id']) {
            $q->where('actor_id', $s['actor_id']);
        }
        if ($s['product_id']) {
            $q->where('product_id', $s['product_id']);
        }

        return $q;
    }

    /** The products a report covers, keyed by id. */
    private function products(int $tenantId, array $s)
    {
        $q = Product::forTenant($tenantId)
            ->select(['id', 'sku', 'name', 'base_unit', 'cost_price', 'sale_price', 'category_id', 'min_stock', 'max_stock', 'reorder_point']);

        if ($s['product_id']) {
            $q->whereKey($s['product_id']);
        }
        if ($s['category_id']) {
            $q->where('category_id', $s['category_id']);
        }

        return $q->orderBy('name')->get()->keyBy('id');
    }

    /* ── 1. Stock Summary ───────────────────────────────────────── */

    /**
     * Opening → in → out → closing for every product over the window. Opening is
     * the signed ledger total BEFORE the window starts, so the report reconciles:
     * closing always equals opening + in - out.
     */
    public function stockSummary(int $tenantId, array $f = []): array
    {
        $s = $this->scope($f);
        $products = $this->products($tenantId, $s);

        if ($products->isEmpty()) {
            return ['rows' => [], 'totals' => $this->emptyTotals(), 'filters' => $this->describe($tenantId, $s)];
        }

        $ids = $products->keys()->all();

        // Opening: everything that happened before the window.
        $opening = [];
        if ($s['from']) {
            $opening = $this->movements($tenantId, $s)
                ->whereIn('product_id', $ids)
                ->whereDate('created_at', '<', $s['from'])
                ->groupBy('product_id')
                ->pluck(DB::raw('SUM('.$this->deltaExpr($s['warehouse_id']).')'), 'product_id')
                ->map(fn ($v) => (float) $v)->all();
        }

        // In / out inside the window.
        $range = $this->movements($tenantId, $s)->whereIn('product_id', $ids);
        if ($s['from']) {
            $range->whereDate('created_at', '>=', $s['from']);
        }
        if ($s['to']) {
            $range->whereDate('created_at', '<=', $s['to']);
        }
        $agg = $range->groupBy('product_id')
            ->get([
                'product_id',
                DB::raw('SUM('.$this->inExpr($s['warehouse_id']).') as qty_in'),
                DB::raw('SUM('.$this->outExpr($s['warehouse_id']).') as qty_out'),
                DB::raw('COUNT(*) as movements'),
            ])
            ->keyBy('product_id');

        $rows = [];
        $totals = $this->emptyTotals();

        foreach ($products as $id => $p) {
            $open = (float) ($opening[$id] ?? 0);
            $in   = (float) ($agg[$id]->qty_in ?? 0);
            $out  = (float) ($agg[$id]->qty_out ?? 0);
            $close = round($open + $in - $out, 3);

            // A product with no history and no closing balance adds nothing but noise.
            if ($open == 0.0 && $in == 0.0 && $out == 0.0) {
                continue;
            }

            $rows[] = [
                'product_id' => $id,
                'sku'        => $p->sku,
                'name'       => $p->name,
                'unit'       => $p->base_unit,
                'opening'    => round($open, 3),
                'qty_in'     => round($in, 3),
                'qty_out'    => round($out, 3),
                'closing'    => $close,
                'movements'  => (int) ($agg[$id]->movements ?? 0),
                'value'      => round($close * (float) ($p->cost_price ?? 0), 2),
            ];

            $totals['opening'] += $open;
            $totals['qty_in']  += $in;
            $totals['qty_out'] += $out;
            $totals['closing'] += $close;
            $totals['value']   += $close * (float) ($p->cost_price ?? 0);
        }

        return [
            'rows'    => $rows,
            'totals'  => array_map(fn ($v) => round($v, 3), $totals),
            'filters' => $this->describe($tenantId, $s),
        ];
    }

    private function emptyTotals(): array
    {
        return ['opening' => 0.0, 'qty_in' => 0.0, 'qty_out' => 0.0, 'closing' => 0.0, 'value' => 0.0];
    }

    /* ── 2. Inventory Valuation ─────────────────────────────────── */

    /**
     * What the shelf is worth right now. Cost value is what the stock is carried
     * at; sale value is what it would fetch — the difference is the margin locked
     * up in inventory.
     */
    public function valuation(int $tenantId, array $f = []): array
    {
        $s = $this->scope($f);

        $q = DB::table('inventory_stock as st')
            ->join('inventory_products as p', 'p.id', '=', 'st.product_id')
            ->leftJoin('inventory_warehouses as w', 'w.id', '=', 'st.warehouse_id')
            ->where('st.tenant_id', $tenantId)
            ->whereNull('p.deleted_at');

        if ($s['warehouse_id']) {
            $q->where('st.warehouse_id', $s['warehouse_id']);
        }
        if ($s['product_id']) {
            $q->where('st.product_id', $s['product_id']);
        }
        if ($s['category_id']) {
            $q->where('p.category_id', $s['category_id']);
        }

        // Cost value follows the tenant's costing method (FIFO/LIFO/average),
        // valued off the batch cost layers — not a single flat product cost.
        $method = app(CostingService::class)->method($tenantId);
        $costing = app(CostingService::class);
        $whId = $s['warehouse_id'] ?: null;

        $rows = $q->groupBy('st.product_id', 'p.sku', 'p.name', 'p.base_unit', 'p.cost_price', 'p.sale_price')
            ->orderBy('p.name')
            ->get([
                'st.product_id',
                'p.sku', 'p.name', 'p.base_unit', 'p.cost_price', 'p.sale_price',
                DB::raw('SUM(st.quantity) as qty'),
                DB::raw('SUM(st.reserved_quantity) as reserved'),
            ])
            ->map(function ($r) use ($method, $costing, $tenantId, $whId) {
                $qty  = (float) $r->qty;
                $fallback = (float) ($r->cost_price ?? 0);
                $sale = (float) ($r->sale_price ?? 0);

                $costValue = $costing->onHandValue((int) $r->product_id, $fallback, $qty, $method, $tenantId, $whId);
                $unitCost = $qty != 0.0 ? $costValue / $qty : $fallback;

                return [
                    'product_id'   => (int) $r->product_id,
                    'sku'          => $r->sku,
                    'name'         => $r->name,
                    'unit'         => $r->base_unit,
                    'quantity'     => round($qty, 3),
                    'reserved'     => round((float) $r->reserved, 3),
                    'available'    => round($qty - (float) $r->reserved, 3),
                    'cost_price'   => round($unitCost, 2),
                    'sale_price'   => round($sale, 2),
                    'cost_value'   => round($costValue, 2),
                    'sale_value'   => round($qty * $sale, 2),
                    'margin'       => round($qty * $sale - $costValue, 2),
                ];
            })
            ->filter(fn ($r) => $r['quantity'] != 0.0)
            ->values()
            ->all();

        return [
            'rows'   => $rows,
            'totals' => [
                'quantity'   => round(array_sum(array_column($rows, 'quantity')), 3),
                'cost_value' => round(array_sum(array_column($rows, 'cost_value')), 2),
                'sale_value' => round(array_sum(array_column($rows, 'sale_value')), 2),
                'margin'     => round(array_sum(array_column($rows, 'margin')), 2),
                'lines'      => count($rows),
            ],
            'costing_method' => $method,
            'filters' => $this->describe($tenantId, $s),
        ];
    }

    /* ── 3. Inventory Analysis ──────────────────────────────────── */

    /**
     * Consumption behaviour over the window: how much moved out, how fast the
     * stock turned over, and whether each item is fast/slow/dead.
     *
     * Classification is deterministic, not guesswork — items are ranked by
     * quantity consumed in the window: nothing consumed = dead, top 20% = fast,
     * next 30% = medium, the rest = slow. Turnover is consumption over average
     * stock held (the standard inventory-turns formula).
     */
    public function analysis(int $tenantId, array $f = []): array
    {
        $summary = $this->stockSummary($tenantId, $f);
        $rows = $summary['rows'];

        $days = $this->windowDays($f);

        foreach ($rows as &$r) {
            $avg = ((float) $r['opening'] + (float) $r['closing']) / 2;
            $out = (float) $r['qty_out'];

            $r['avg_stock'] = round($avg, 3);
            // Turns = consumption / average stock held. Undefined with no stock held.
            $r['turnover'] = $avg > 0 ? round($out / $avg, 2) : null;
            // How many days the closing balance lasts at the window's burn rate.
            $r['days_of_stock'] = ($out > 0 && $days > 0)
                ? round((float) $r['closing'] / ($out / $days), 1)
                : null;
            $r['class'] = 'dead';
        }
        unset($r);

        // Rank the movers to split fast / medium / slow.
        $movers = array_values(array_filter($rows, fn ($r) => (float) $r['qty_out'] > 0));
        usort($movers, fn ($a, $b) => (float) $b['qty_out'] <=> (float) $a['qty_out']);

        $n = count($movers);
        $classOf = [];
        foreach ($movers as $i => $m) {
            $pct = $n > 1 ? $i / ($n - 1) : 0;
            $classOf[$m['product_id']] = $pct <= 0.2 ? 'fast' : ($pct <= 0.5 ? 'medium' : 'slow');
        }

        foreach ($rows as &$r) {
            $r['class'] = $classOf[$r['product_id']] ?? 'dead';
        }
        unset($r);

        $count = fn (string $c) => count(array_filter($rows, fn ($r) => $r['class'] === $c));

        return [
            'rows'    => $rows,
            'totals'  => [
                'lines'  => count($rows),
                'fast'   => $count('fast'),
                'medium' => $count('medium'),
                'slow'   => $count('slow'),
                'dead'   => $count('dead'),
                'days'   => $days,
            ],
            'filters' => $summary['filters'],
        ];
    }

    /** Length of the reporting window in days (defaults to 30 when open-ended). */
    private function windowDays(array $f): int
    {
        if (empty($f['from'])) {
            return 30;
        }

        $from = strtotime($f['from']);
        $to = ! empty($f['to']) ? strtotime($f['to']) : time();

        return max(1, (int) round(($to - $from) / 86400) + 1);
    }

    /* ── Echo the applied filters back for the report header ────── */

    private function describe(int $tenantId, array $s): array
    {
        return [
            'from'      => $s['from'],
            'to'        => $s['to'],
            'warehouse' => $s['warehouse_id']
                ? Warehouse::forTenant($tenantId)->whereKey($s['warehouse_id'])->value('name')
                : null,
            'product'   => $s['product_id']
                ? Product::forTenant($tenantId)->whereKey($s['product_id'])->value('name')
                : null,
            'staff'     => $s['actor_id']
                ? \App\Models\User::whereKey($s['actor_id'])->value('name')
                : null,
        ];
    }
}
