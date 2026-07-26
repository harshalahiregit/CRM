<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\DeadStockAction;
use App\Models\Inventory\Movement;
use App\Models\Inventory\Product;
use App\Models\Inventory\Stock;
use Illuminate\Support\Facades\DB;

/**
 * The action side of dead stock. Analytics reports what's dead; this owns the
 * decision about each item and drives it to a resolution.
 *
 * Only the DISCOUNT action touches anything on its own — it can push a new sale
 * price onto the item, because that's a self-contained product edit inside this
 * module. Transfer and write-off deliberately do NOT move ledger stock here:
 * they record the intent and point the user at the Transfers / Loss & adjustment
 * screens, where the movement is posted with the usual approvals. A dead-stock
 * note must never be a back door that quietly reverses the stock ledger.
 */
class DeadStockService
{
    private const DEFAULT_DAYS = 90;

    /* ── Candidates ─────────────────────────────────────────────── */

    /**
     * Items with stock on hand that haven't gone OUT in the window and don't yet
     * have an open action. Valued at cost so the list is ranked by money at rest.
     */
    public function candidates(int $tenantId, int $days = self::DEFAULT_DAYS): array
    {
        $cutoff = now()->subDays($days);

        // product_ids that had an outbound movement inside the window — alive.
        $movedOut = Movement::forTenant($tenantId)
            ->where('direction', 'out')
            ->where('created_at', '>=', $cutoff)
            ->distinct()->pluck('product_id')->map(fn ($i) => (int) $i)->all();

        // product_ids already being dealt with — don't offer them twice.
        $handled = DeadStockAction::forTenant($tenantId)
            ->whereIn('status', ['open', 'in_progress'])
            ->distinct()->pluck('product_id')->map(fn ($i) => (int) $i)->all();

        $skip = array_flip(array_merge($movedOut, $handled));

        $held = DB::table('inventory_stock as s')
            ->join('inventory_products as p', 'p.id', '=', 's.product_id')
            ->where('s.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->where('p.without_checking_warehouse', false)
            ->groupBy('s.product_id', 'p.sku', 'p.name', 'p.cost_price', 'p.sale_price')
            ->havingRaw('SUM(s.quantity) > 0')
            ->get(['s.product_id', 'p.sku', 'p.name', 'p.cost_price', 'p.sale_price', DB::raw('SUM(s.quantity) as qty')]);

        $rows = [];
        foreach ($held as $h) {
            if (isset($skip[(int) $h->product_id])) {
                continue;
            }
            $rows[] = [
                'product_id' => (int) $h->product_id,
                'sku'        => $h->sku,
                'name'       => $h->name,
                'quantity'   => round((float) $h->qty, 3),
                'cost_price' => (float) ($h->cost_price ?? 0),
                'sale_price' => (float) ($h->sale_price ?? 0),
                'value'      => round((float) $h->qty * (float) ($h->cost_price ?? 0), 2),
            ];
        }

        usort($rows, fn ($a, $b) => $b['value'] <=> $a['value']);

        return [
            'days'  => $days,
            'count' => count($rows),
            'value' => round(array_sum(array_column($rows, 'value')), 2),
            'rows'  => $rows,
        ];
    }

    /* ── Actions ────────────────────────────────────────────────── */

    public function list(int $tenantId, array $f = [])
    {
        $q = DeadStockAction::forTenant($tenantId)
            ->with('product:id,sku,name', 'warehouse:id,name', 'toWarehouse:id,name', 'assignee:id,name', 'creator:id,name');

        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }
        if (! empty($f['action'])) {
            $q->where('action', $f['action']);
        }

        return $q->orderByDesc('id')->get();
    }

    public function create(array $d, int $tenantId, int $userId): DeadStockAction
    {
        $action = $d['action'] ?? '';
        if (! in_array($action, DeadStockAction::ACTIONS, true)) {
            throw new BusinessException('Choose what to do with this stock.', 422);
        }

        $product = Product::forTenant($tenantId)->findOrFail((int) ($d['product_id'] ?? 0));
        $onHand = (float) Stock::forTenant($tenantId)->where('product_id', $product->id)->sum('quantity');

        return DB::transaction(function () use ($d, $action, $tenantId, $userId, $product, $onHand) {
            $row = DeadStockAction::create([
                'tenant_id'        => $tenantId,
                'product_id'       => $product->id,
                'action'           => $action,
                'status'           => 'open',
                'qty'              => $d['qty'] ?? $onHand,
                'warehouse_id'     => $d['warehouse_id'] ?? null,
                'to_warehouse_id'  => $action === 'transfer' ? ($d['to_warehouse_id'] ?? null) : null,
                'discount_percent' => $action === 'discount' ? ($d['discount_percent'] ?? null) : null,
                'new_price'        => $action === 'discount' ? ($d['new_price'] ?? null) : null,
                'value_snapshot'   => round($onHand * (float) ($product->cost_price ?? 0), 2),
                'note'             => $d['note'] ?? null,
                'assigned_to'      => $d['assigned_to'] ?? null,
                'created_by'       => $userId,
            ]);

            // Discount is the one action safe to apply straight away, if asked to.
            if ($action === 'discount' && ! empty($d['apply_now'])) {
                $this->applyDiscount($row, $product);
            }

            return $row->fresh(['product', 'warehouse', 'toWarehouse', 'assignee']);
        });
    }

    /** Apply a discount action's price change to the product (idempotent). */
    private function applyDiscount(DeadStockAction $row, Product $product): void
    {
        if ($row->applied) {
            return;
        }

        $price = null;
        if ($row->new_price !== null) {
            $price = (float) $row->new_price;
        } elseif ($row->discount_percent !== null) {
            $base = (float) ($product->sale_price ?: $product->cost_price ?: 0);
            $price = round($base * (1 - (float) $row->discount_percent / 100), 2);
        }

        if ($price !== null && $price >= 0) {
            $product->update(['sale_price' => $price]);
            $row->update(['applied' => true, 'new_price' => $price]);
        }
    }

    public function updateStatus(int $id, string $status, int $tenantId, int $userId): DeadStockAction
    {
        if (! in_array($status, ['open', 'in_progress', 'done', 'cancelled'], true)) {
            throw new BusinessException('Unknown status.', 422);
        }

        $row = DeadStockAction::forTenant($tenantId)->with('product')->findOrFail($id);

        // Marking a not-yet-applied discount as done applies the price now.
        if ($status === 'done' && $row->action === 'discount' && ! $row->applied && $row->product) {
            $this->applyDiscount($row, $row->product);
        }

        $row->update([
            'status'      => $status,
            'resolved_by' => in_array($status, ['done', 'cancelled'], true) ? $userId : null,
            'resolved_at' => in_array($status, ['done', 'cancelled'], true) ? now() : null,
        ]);

        return $row->fresh(['product', 'warehouse', 'toWarehouse', 'assignee']);
    }

    public function delete(int $id, int $tenantId): void
    {
        DeadStockAction::forTenant($tenantId)->findOrFail($id)->delete();
    }
}
