<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\Product;
use App\Models\Inventory\PurchaseOrder;
use App\Models\Inventory\PurchaseOrderLine;
use App\Models\Inventory\Stock;
use App\Models\Inventory\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * Purchase orders + auto-reorder.
 *
 * The document lifecycle is deliberately conservative: draft → submitted →
 * approved → sent → (partial) → received, with cancel available until it is
 * received. Auto-reorder only ever produces DRAFTS — the system proposes what to
 * buy from whom, and a person decides whether it actually goes out. Nothing is
 * ordered by a cron job on its own authority.
 */
class PurchaseOrderService
{
    public function __construct(private VendorService $vendors)
    {
    }

    /* ── Read ───────────────────────────────────────────────────── */

    public function list(int $tenantId, array $f = [])
    {
        $q = PurchaseOrder::forTenant($tenantId)
            ->with('vendor:id,name,code', 'warehouse:id,name')
            ->withCount('lines');

        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }
        if (! empty($f['vendor_id'])) {
            $q->where('vendor_id', (int) $f['vendor_id']);
        }
        if (! empty($f['source'])) {
            $q->where('source', $f['source']);
        }
        if (! empty($f['search'])) {
            $s = '%'.$f['search'].'%';
            $q->where(fn ($w) => $w->where('code', 'like', $s)
                ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', $s)));
        }

        return $q->orderByDesc('id')->get();
    }

    public function show(int $id, int $tenantId): PurchaseOrder
    {
        return PurchaseOrder::forTenant($tenantId)
            ->with('vendor', 'warehouse:id,name', 'creator:id,name', 'approver:id,name',
                'lines.product:id,sku,name')
            ->findOrFail($id);
    }

    /* ── Create / update ────────────────────────────────────────── */

    public function create(array $d, int $tenantId, int $userId): PurchaseOrder
    {
        $vendorId = (int) ($d['vendor_id'] ?? 0);
        if ($vendorId <= 0 || ! Vendor::forTenant($tenantId)->whereKey($vendorId)->exists()) {
            throw new BusinessException('Choose a vendor for this purchase order.', 422);
        }

        return DB::transaction(function () use ($d, $tenantId, $userId, $vendorId) {
            $po = PurchaseOrder::create(array_merge([
                'tenant_id'     => $tenantId,
                'vendor_id'     => $vendorId,
                'status'        => 'draft',
                'source'        => $d['source'] ?? 'manual',
                'order_date'    => $d['order_date'] ?? now()->toDateString(),
                'created_by'    => $userId,
            ], $this->headerFields($d)));
            $po->code = 'PO-'.str_pad((string) $po->id, 6, '0', STR_PAD_LEFT);
            $po->save();

            $this->syncLines($po, $d['lines'] ?? []);
            $this->recalc($po);

            return $po;
        });
    }

    /** The header attributes a caller may set, pulled from the request payload. */
    private function headerFields(array $d): array
    {
        return array_intersect_key($d, array_flip([
            'description', 'warehouse_id', 'type', 'tags', 'currency',
            'order_date', 'expected_date', 'delivery_date',
            'discount_type', 'discount_mode', 'discount_value', 'shipping_fee',
            'notes', 'vendor_note', 'terms',
            'ship_address', 'ship_city', 'ship_state', 'ship_zip', 'ship_country',
        ]));
    }

    public function update(int $id, array $d, int $tenantId): PurchaseOrder
    {
        $po = PurchaseOrder::forTenant($tenantId)->findOrFail($id);
        if (! in_array($po->status, ['draft', 'submitted'], true)) {
            throw new BusinessException('Only a draft or submitted PO can be edited.', 422);
        }

        return DB::transaction(function () use ($po, $d) {
            $po->fill($this->headerFields($d));
            if (! empty($d['vendor_id'])) {
                $po->vendor_id = (int) $d['vendor_id'];
            }
            $po->save();

            if (array_key_exists('lines', $d)) {
                $this->syncLines($po, $d['lines']);
            }
            $this->recalc($po);

            return $po->fresh();
        });
    }

    /** Replace all lines. Kept private — every caller goes through create/update. */
    private function syncLines(PurchaseOrder $po, array $lines): void
    {
        PurchaseOrderLine::forTenant($po->tenant_id)->where('purchase_order_id', $po->id)->delete();

        foreach ($lines as $l) {
            $qty = (float) ($l['qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $price = (float) ($l['unit_price'] ?? 0);
            $tax = (float) ($l['tax_rate'] ?? 0);
            $disc = min(100, max(0, (float) ($l['discount_pct'] ?? 0)));
            $net = $qty * $price;
            $netAfterDisc = $net - ($net * $disc / 100);
            $lineTotal = $netAfterDisc + ($netAfterDisc * $tax / 100);

            PurchaseOrderLine::create([
                'tenant_id'         => $po->tenant_id,
                'purchase_order_id' => $po->id,
                'product_id'        => $l['product_id'] ?? null,
                'description'       => $l['description'] ?? null,
                'qty'               => $qty,
                'received_qty'      => 0,
                'unit_price'        => $price,
                'tax_rate'          => $tax,
                'discount_pct'      => $disc,
                'line_total'        => round($lineTotal, 2),
            ]);
        }
    }

    /**
     * Roll the line totals up onto the header, then apply the order-level
     * discount and shipping fee.
     *
     * Line discounts fold into each line's net before tax. The order discount
     * sits on top: a percentage bites the subtotal (before_tax) or the taxed
     * figure (after_tax); a flat amount is taken as-is. Shipping is added last.
     */
    private function recalc(PurchaseOrder $po): void
    {
        $lines = $po->lines()->get();
        $subtotal = 0.0;
        $tax = 0.0;
        foreach ($lines as $l) {
            $net = (float) $l->qty * (float) $l->unit_price;
            $net -= $net * (float) $l->discount_pct / 100;
            $subtotal += $net;
            $tax += $net * (float) $l->tax_rate / 100;
        }

        $mode = $po->discount_mode;
        $value = (float) $po->discount_value;
        $discountAmount = 0.0;
        if ($value > 0) {
            if ($mode === 'percent') {
                $base = $po->discount_type === 'after_tax' ? ($subtotal + $tax) : $subtotal;
                $discountAmount = $base * min(100, $value) / 100;
            } elseif ($mode === 'amount') {
                $discountAmount = $value;
            }
        }
        $shipping = max(0, (float) $po->shipping_fee);

        $total = max(0, $subtotal + $tax - $discountAmount + $shipping);

        $po->subtotal = round($subtotal, 2);
        $po->tax_total = round($tax, 2);
        $po->discount_amount = round($discountAmount, 2);
        $po->total = round($total, 2);
        $po->save();
    }

    /* ── Lifecycle ──────────────────────────────────────────────── */

    public function submit(int $id, int $tenantId): PurchaseOrder
    {
        $po = PurchaseOrder::forTenant($tenantId)->findOrFail($id);
        $this->assertStatus($po, ['draft'], 'submit');
        if ($po->lines()->count() === 0) {
            throw new BusinessException('Add at least one line before submitting.', 422);
        }
        $po->update(['status' => 'submitted']);

        return $po->fresh();
    }

    public function approve(int $id, int $tenantId, int $userId): PurchaseOrder
    {
        $po = PurchaseOrder::forTenant($tenantId)->findOrFail($id);
        $this->assertStatus($po, ['draft', 'submitted'], 'approve');
        $po->update(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now()]);

        return $po->fresh();
    }

    public function markSent(int $id, int $tenantId): PurchaseOrder
    {
        $po = PurchaseOrder::forTenant($tenantId)->findOrFail($id);
        $this->assertStatus($po, ['approved', 'submitted'], 'mark as sent');
        $po->update(['status' => 'sent', 'sent_at' => now()]);

        return $po->fresh();
    }

    public function cancel(int $id, int $tenantId): PurchaseOrder
    {
        $po = PurchaseOrder::forTenant($tenantId)->findOrFail($id);
        if (in_array($po->status, ['received', 'cancelled'], true)) {
            throw new BusinessException('This PO can no longer be cancelled.', 422);
        }
        $po->update(['status' => 'cancelled']);

        return $po->fresh();
    }

    /**
     * Record a receipt against the PO's lines and roll the status forward. Given
     * `[line_id => qty]`, adds to each line's received_qty and sets the header to
     * partial or received. This is the manual link until a full goods-receipt
     * voucher integration lands; it never moves ledger stock by itself.
     */
    public function receive(int $id, array $received, int $tenantId): PurchaseOrder
    {
        $po = PurchaseOrder::forTenant($tenantId)->with('lines')->findOrFail($id);
        if (! in_array($po->status, ['approved', 'sent', 'partial'], true)) {
            throw new BusinessException('Only an approved or sent PO can receive stock.', 422);
        }

        DB::transaction(function () use ($po, $received) {
            foreach ($po->lines as $line) {
                $add = (float) ($received[$line->id] ?? 0);
                if ($add > 0) {
                    $line->received_qty = min((float) $line->qty, (float) $line->received_qty + $add);
                    $line->save();
                }
            }
            $po->refresh();
            $done = $po->lines->every(fn ($l) => (float) $l->received_qty >= (float) $l->qty);
            $any = $po->lines->contains(fn ($l) => (float) $l->received_qty > 0);
            $po->status = $done ? 'received' : ($any ? 'partial' : $po->status);
            $po->save();
        });

        return $po->fresh('lines');
    }

    private function assertStatus(PurchaseOrder $po, array $allowed, string $action): void
    {
        if (! in_array($po->status, $allowed, true)) {
            throw new BusinessException("A {$po->status} PO cannot be {$action}d.", 422);
        }
    }

    /* ── Auto-reorder ───────────────────────────────────────────── */

    /**
     * Propose purchase orders for everything sitting below its reorder point.
     *
     * Low-stock items are grouped by their PREFERRED vendor (from the vendor
     * master); each vendor gets one DRAFT PO. Order quantity aims to refill to
     * max_stock where set, otherwise to the reorder threshold, and never asks for
     * less than the vendor's MOQ. Items with no vendor linked can't be ordered
     * automatically — they're returned as `skipped` so the buyer can assign one.
     *
     * @return array{created: PurchaseOrder[], skipped: array}
     */
    public function generateFromLowStock(int $tenantId, int $userId, StockService $stock): array
    {
        $low = $stock->lowStock($tenantId);

        $byVendor = [];      // vendor_id => [lines]
        $skipped = [];

        foreach ($low as $product) {
            $link = $this->vendors->preferredFor($product->id, $tenantId);
            if (! $link) {
                $skipped[] = ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku];
                continue;
            }

            $onHand = (float) ($product->on_hand ?? $this->onHand($product->id, $tenantId));
            $threshold = $product->reorderThreshold();
            $target = (float) $product->max_stock > 0 ? (float) $product->max_stock : $threshold;

            $need = $target - $onHand;
            $moq = (float) ($link->moq ?? 0);
            $qty = max($need, $moq);
            if ($qty <= 0) {
                continue;
            }

            $byVendor[$link->vendor_id][] = [
                'product'    => $product,
                'qty'        => round($qty, 3),
                'unit_price' => (float) ($link->price ?? $product->cost_price ?? 0),
                'tax_rate'   => (float) ($product->gst_rate ?? 0),
            ];
        }

        $created = [];
        DB::transaction(function () use (&$created, $byVendor, $tenantId, $userId) {
            foreach ($byVendor as $vendorId => $rows) {
                $po = PurchaseOrder::create([
                    'tenant_id'  => $tenantId,
                    'vendor_id'  => $vendorId,
                    'status'     => 'draft',
                    'source'     => 'auto',
                    'order_date' => now()->toDateString(),
                    'notes'      => 'Auto-generated from low-stock items.',
                    'created_by' => $userId,
                ]);
                $po->code = 'PO-'.str_pad((string) $po->id, 6, '0', STR_PAD_LEFT);
                $po->save();

                $this->syncLines($po, array_map(fn ($r) => [
                    'product_id'  => $r['product']->id,
                    'description' => $r['product']->name,
                    'qty'         => $r['qty'],
                    'unit_price'  => $r['unit_price'],
                    'tax_rate'    => $r['tax_rate'],
                ], $rows));
                $this->recalc($po);

                $created[] = $po->fresh(['vendor', 'lines']);
            }
        });

        return ['created' => $created, 'skipped' => $skipped];
    }

    private function onHand(int $productId, int $tenantId): float
    {
        return (float) Stock::forTenant($tenantId)->where('product_id', $productId)->sum('quantity');
    }
}
