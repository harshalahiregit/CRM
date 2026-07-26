<?php

namespace App\Services\Inventory;

use App\Models\Inventory\Batch;

/**
 * Inventory valuation costing. The tenant picks FIFO, LIFO or weighted-average
 * (Settings → delivery_costing_method); this turns that choice into the cost
 * value of what's on hand, using the cost recorded on each batch/lot layer.
 *
 * Layer order: a batch's id increases with receipt, so lowest id = received
 * earliest. FIFO issues the earliest first, so what REMAINS on the shelf is the
 * newest layers → value newest-first. LIFO is the mirror. Average ignores order
 * and weights every remaining layer by its quantity. Stock not covered by any
 * layer (items that aren't batch-tracked) falls back to the product cost.
 */
class CostingService
{
    public const METHODS = ['fifo', 'lifo', 'average'];

    public function method(int $tenantId): string
    {
        $m = strtolower((string) app(ConfigService::class)->get($tenantId, 'delivery_costing_method'));

        return in_array($m, self::METHODS, true) ? $m : 'fifo';
    }

    /**
     * Cost value of $onHand units of a product under $method.
     */
    public function onHandValue(int $productId, float $fallbackCost, float $onHand, string $method, int $tenantId, ?int $warehouseId = null): float
    {
        $onHand = round($onHand, 3);
        if ($onHand <= 0) {
            return 0.0;
        }

        $q = Batch::forTenant($tenantId)->where('product_id', $productId)->where('remaining_qty', '>', 0);
        if ($warehouseId) {
            $q->where('warehouse_id', $warehouseId);
        }
        $layers = $q->get(['id', 'remaining_qty', 'cost_price']);

        if ($layers->isEmpty()) {
            return round($onHand * $fallbackCost, 2);
        }

        $costOf = fn ($l) => (float) ($l->cost_price ?? $fallbackCost);

        if ($method === 'average') {
            $totRem = (float) $layers->sum('remaining_qty');
            $totCost = (float) $layers->sum(fn ($l) => (float) $l->remaining_qty * $costOf($l));
            $unit = $totRem > 0 ? $totCost / $totRem : $fallbackCost;

            return round($onHand * $unit, 2);
        }

        // fifo → value newest layers first; lifo → oldest layers first.
        $ordered = $method === 'lifo' ? $layers->sortBy('id') : $layers->sortByDesc('id');

        $need = $onHand;
        $value = 0.0;
        foreach ($ordered as $l) {
            if ($need <= 0) {
                break;
            }
            $take = min($need, (float) $l->remaining_qty);
            $value += $take * $costOf($l);
            $need = round($need - $take, 3);
        }
        // Any on-hand beyond the batch layers (untracked stock) at product cost.
        if ($need > 0) {
            $value += $need * $fallbackCost;
        }

        return round($value, 2);
    }
}
