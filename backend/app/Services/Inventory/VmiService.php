<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\Product;
use App\Models\Inventory\Stock;
use App\Models\Inventory\Vendor;
use App\Models\Inventory\VmiAgreement;
use App\Models\Inventory\VmiItem;
use Illuminate\Support\Facades\DB;

/**
 * Vendor-managed inventory. Each agreement is a vendor plus a list of items with
 * min/max levels; the shortfall against min (topped up to max) is the
 * replenishment the vendor is expected to send, and can be turned into a draft PO
 * on that vendor via the existing purchase-order engine.
 */
class VmiService
{
    public function __construct(private PurchaseOrderService $purchaseOrders)
    {
    }

    public function list(int $tenantId, array $f = [])
    {
        $q = VmiAgreement::forTenant($tenantId)
            ->with('vendor:id,name', 'warehouse:id,name')
            ->withCount('items');

        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }
        if (! empty($f['vendor_id'])) {
            $q->where('vendor_id', (int) $f['vendor_id']);
        }

        return $q->orderByDesc('id')->get();
    }

    public function show(int $id, int $tenantId): VmiAgreement
    {
        return VmiAgreement::forTenant($tenantId)
            ->with('vendor:id,name', 'warehouse:id,name', 'items.product:id,sku,name')
            ->findOrFail($id);
    }

    public function create(array $d, int $tenantId, int $userId): VmiAgreement
    {
        $vendorId = (int) ($d['vendor_id'] ?? 0);
        if ($vendorId <= 0 || ! Vendor::forTenant($tenantId)->whereKey($vendorId)->exists()) {
            throw new BusinessException('Choose a vendor for this agreement.', 422);
        }

        return DB::transaction(function () use ($d, $tenantId, $userId, $vendorId) {
            $a = VmiAgreement::create([
                'tenant_id'        => $tenantId,
                'vendor_id'        => $vendorId,
                'warehouse_id'     => $d['warehouse_id'] ?? null,
                'name'             => $d['name'] ?? null,
                'status'           => $d['status'] ?? 'active',
                'review_frequency' => $d['review_frequency'] ?? null,
                'note'             => $d['note'] ?? null,
                'created_by'       => $userId,
            ]);
            $this->setItems($a, $d['items'] ?? [], $tenantId);

            return $a->fresh(['vendor', 'warehouse', 'items']);
        });
    }

    public function update(int $id, array $d, int $tenantId): VmiAgreement
    {
        $a = VmiAgreement::forTenant($tenantId)->findOrFail($id);

        return DB::transaction(function () use ($a, $d, $tenantId) {
            $a->fill(array_intersect_key($d, array_flip(['warehouse_id', 'name', 'status', 'review_frequency', 'note'])));
            if (! empty($d['vendor_id'])) {
                $a->vendor_id = (int) $d['vendor_id'];
            }
            $a->save();

            if (array_key_exists('items', $d)) {
                $this->setItems($a, $d['items'], $tenantId);
            }

            return $a->fresh(['vendor', 'warehouse', 'items']);
        });
    }

    private function setItems(VmiAgreement $a, array $items, int $tenantId): void
    {
        VmiItem::forTenant($tenantId)->where('agreement_id', $a->id)->delete();
        foreach ($items as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0 || ! Product::forTenant($tenantId)->whereKey($productId)->exists()) {
                continue;
            }
            VmiItem::create([
                'tenant_id'    => $tenantId,
                'agreement_id' => $a->id,
                'product_id'   => $productId,
                'min_level'    => (float) ($row['min_level'] ?? 0),
                'max_level'    => (float) ($row['max_level'] ?? 0),
            ]);
        }
    }

    public function delete(int $id, int $tenantId): void
    {
        VmiAgreement::forTenant($tenantId)->findOrFail($id)->delete();
    }

    /**
     * Items below their min level and the top-up to max. Only items that are
     * actually short are returned — a healthy agreement suggests nothing.
     *
     * @return array{rows: array, total_qty: float}
     */
    public function suggestions(int $id, int $tenantId): array
    {
        $a = $this->show($id, $tenantId);
        $rows = [];
        $total = 0.0;

        foreach ($a->items as $item) {
            $onHand = $this->onHand($item->product_id, $tenantId, $a->warehouse_id);
            $min = (float) $item->min_level;
            $max = (float) $item->max_level;
            if ($onHand >= $min || $max <= 0) {
                continue;
            }
            $qty = round(max($min, $max) - $onHand, 3);
            if ($qty <= 0) {
                continue;
            }
            $rows[] = [
                'product_id' => $item->product_id,
                'name'       => $item->product->name ?? ('#'.$item->product_id),
                'sku'        => $item->product->sku ?? null,
                'on_hand'    => $onHand,
                'min_level'  => $min,
                'max_level'  => $max,
                'suggest'    => $qty,
                'cost_price' => (float) ($item->product->cost_price ?? 0),
            ];
            $total += $qty;
        }

        return ['agreement_id' => $id, 'vendor' => $a->vendor?->only(['id', 'name']), 'rows' => $rows, 'total_qty' => round($total, 3)];
    }

    /** Turn the current shortfall into a draft PO on the agreement's vendor. */
    public function generatePurchaseOrder(int $id, int $tenantId, int $userId): array
    {
        $a = $this->show($id, $tenantId);
        $sug = $this->suggestions($id, $tenantId);
        if (! $sug['rows']) {
            return ['created' => null, 'message' => 'Nothing is below its minimum — no PO needed.'];
        }

        $po = $this->purchaseOrders->create([
            'vendor_id'    => $a->vendor_id,
            'warehouse_id' => $a->warehouse_id,
            'source'       => 'auto',
            'notes'        => 'Generated from VMI agreement '.($a->name ? "“{$a->name}”" : "#{$a->id}").'.',
            'lines'        => array_map(fn ($r) => [
                'product_id'  => $r['product_id'],
                'description' => $r['name'],
                'qty'         => $r['suggest'],
                'unit_price'  => $r['cost_price'],
            ], $sug['rows']),
        ], $tenantId, $userId);

        return ['created' => $po, 'message' => 'Draft PO '.$po->code.' created from the VMI shortfall.'];
    }

    private function onHand(int $productId, int $tenantId, ?int $warehouseId): float
    {
        $q = Stock::forTenant($tenantId)->where('product_id', $productId);
        if ($warehouseId) {
            $q->where('warehouse_id', $warehouseId);
        }

        return (float) $q->sum('quantity');
    }
}
