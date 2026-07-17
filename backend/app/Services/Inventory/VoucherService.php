<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\Product;
use App\Models\Inventory\Voucher;
use App\Models\Inventory\VoucherItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock documents (blueprint §3–§6).
 *
 * The whole point of this class is one rule: a voucher is paperwork, the ledger
 * is truth. Drafts can be edited all day and move nothing. POSTING is the single
 * moment stock changes — it walks the lines and hands each one to StockService,
 * so every voucher line ends up as an auditable movement rather than a hand-edited
 * balance. Cancelling a posted voucher writes REVERSING movements instead of
 * deleting history.
 */
class VoucherService
{
    public function __construct(private StockService $stock)
    {
    }

    /* ── Reads ──────────────────────────────────────────────────── */

    public function list(string $type, int $tenantId, array $filters = []): Collection
    {
        $this->assertType($type);

        $q = Voucher::forTenant($tenantId)->where('type', $type)
            ->with(['warehouse:id,name', 'creator:id,name'])
            ->withCount('items');

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $s = '%'.$filters['search'].'%';
            $q->where(fn ($w) => $w->where('code', 'like', $s)
                ->orWhere('supplier_name', 'like', $s)
                ->orWhere('customer_name', 'like', $s)
                ->orWhere('description', 'like', $s));
        }
        // Blueprint's Voucher-Day filter.
        if (! empty($filters['from'])) {
            $q->whereDate('date_add', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('date_add', '<=', $filters['to']);
        }

        return $q->latest('id')->get();
    }

    public function show(int $id, int $tenantId): Voucher
    {
        $v = $this->find($id, $tenantId);
        $v->load([
            'items.product:id,sku,name,base_unit',
            'items.warehouse:id,name', 'items.fromWarehouse:id,name', 'items.toWarehouse:id,name',
            'warehouse:id,name', 'creator:id,name', 'staff:id,name',
        ]);

        return $v;
    }

    /* ── Writes ─────────────────────────────────────────────────── */

    public function create(string $type, array $data, int $tenantId, int $userId): Voucher
    {
        $this->assertType($type);
        $items = $data['items'] ?? [];
        unset($data['items']);

        return DB::transaction(function () use ($type, $data, $items, $tenantId, $userId) {
            $voucher = Voucher::create([
                ...$data,
                'tenant_id'  => $tenantId,
                'type'       => $type,
                'code'       => $this->nextCode($type, $tenantId),
                'status'     => 'draft',
                'date_add'   => $data['date_add'] ?? now()->toDateString(),
                'date_c'     => $data['date_c'] ?? ($data['date_add'] ?? now()->toDateString()),
                'created_by' => $userId,
            ]);

            $this->replaceItems($voucher, $items, $tenantId);

            return $this->show($voucher->id, $tenantId);
        });
    }

    public function update(int $id, array $data, int $tenantId): Voucher
    {
        $voucher = $this->find($id, $tenantId);
        $this->assertEditable($voucher);

        $items = $data['items'] ?? null;
        unset($data['items'], $data['type'], $data['code'], $data['status']);

        return DB::transaction(function () use ($voucher, $data, $items, $tenantId) {
            $voucher->fill($data)->save();
            if ($items !== null) {
                $this->replaceItems($voucher, $items, $tenantId);
            } else {
                $this->recalcTotals($voucher);
            }

            return $this->show($voucher->id, $tenantId);
        });
    }

    public function delete(int $id, int $tenantId): void
    {
        $voucher = $this->find($id, $tenantId);

        // A posted voucher is part of the audit trail — cancel it instead.
        if ($voucher->status === 'posted') {
            throw new BusinessException('This voucher is posted. Cancel it first — posted documents are never deleted.', 422);
        }

        DB::transaction(function () use ($voucher, $tenantId) {
            VoucherItem::forTenant($tenantId)->where('voucher_id', $voucher->id)->delete();
            $voucher->delete();
        });
    }

    /**
     * Post the voucher: every line becomes a movement on the ledger.
     * All-or-nothing — if any line fails (e.g. not enough stock), the whole
     * document stays a draft rather than half-moving the warehouse.
     */
    public function post(int $id, int $tenantId, int $userId): Voucher
    {
        $voucher = $this->find($id, $tenantId);

        if ($voucher->status === 'posted') {
            throw new BusinessException('This voucher is already posted.', 422);
        }
        if ($voucher->status === 'cancelled') {
            throw new BusinessException('A cancelled voucher cannot be posted.', 422);
        }

        $items = VoucherItem::forTenant($tenantId)->where('voucher_id', $voucher->id)->get();
        if ($items->isEmpty()) {
            throw new BusinessException('Add at least one line before posting.', 422);
        }

        DB::transaction(function () use ($voucher, $items, $tenantId, $userId) {
            foreach ($items as $line) {
                $this->postLine($voucher, $line, $tenantId, $userId);
            }

            $voucher->forceFill([
                'status'    => 'posted',
                'posted_at' => now(),
                'posted_by' => $userId,
            ])->save();
        });

        return $this->show($voucher->id, $tenantId);
    }

    /**
     * Cancel a voucher. A draft just flips status; a POSTED one gets reversing
     * movements so the ledger explains the undo instead of losing it.
     */
    public function cancel(int $id, int $tenantId, int $userId): Voucher
    {
        $voucher = $this->find($id, $tenantId);

        if ($voucher->status === 'cancelled') {
            throw new BusinessException('This voucher is already cancelled.', 422);
        }

        DB::transaction(function () use ($voucher, $tenantId, $userId) {
            if ($voucher->status === 'posted') {
                foreach ($voucher->items()->get() as $line) {
                    $this->reverseLine($voucher, $line, $tenantId, $userId);
                }
            }
            $voucher->forceFill(['status' => 'cancelled'])->save();
        });

        return $this->show($voucher->id, $tenantId);
    }

    /* ── Posting internals ──────────────────────────────────────── */

    private function postLine(Voucher $v, VoucherItem $line, int $tenantId, int $userId): void
    {
        $ref = ['reference_type' => 'voucher', 'reference_id' => $v->id];
        $reason = $v->reason ?: $v->type_label.' '.$v->code;

        // An 'adjustment' line carries the COUNTED total, so the delta is worked
        // out at post time against whatever is actually on hand now.
        if ($v->type === 'loss_adjustment' && $v->adjustment_type === 'adjustment') {
            $this->stock->adjustTo(
                $line->product_id,
                (int) ($line->warehouse_id ?? $v->warehouse_id),
                (float) $line->quantity,
                $tenantId, $userId, $reason, $line->location_id, $ref,
            );

            return;
        }

        $this->stock->record([
            ...$ref,
            'product_id'        => $line->product_id,
            'type'              => $this->movementTypeFor($v),
            'quantity'          => (float) $line->quantity,
            'warehouse_id'      => $line->warehouse_id ?? $v->warehouse_id,
            'location_id'       => $line->location_id,
            'from_warehouse_id' => $line->from_warehouse_id,
            'to_warehouse_id'   => $line->to_warehouse_id,
            'reason'            => $reason,
        ], $tenantId, $userId);
    }

    /** The mirror image of postLine — puts back exactly what was taken. */
    private function reverseLine(Voucher $v, VoucherItem $line, int $tenantId, int $userId): void
    {
        $reason = 'Reversal of '.$v->code;
        $ref = ['reference_type' => 'voucher_reversal', 'reference_id' => $v->id];

        if ($v->type === 'loss_adjustment' && $v->adjustment_type === 'adjustment') {
            // A recount can't be "un-counted" — the honest reversal is another
            // count back to what it was before this voucher moved it.
            throw new BusinessException(
                'A posted adjustment cannot be auto-reversed, because the counted figure replaced the balance. Raise a new adjustment with the correct count instead.',
                422
            );
        }

        if ($v->type === 'internal') {
            $this->stock->record([
                ...$ref,
                'product_id'        => $line->product_id,
                'type'              => 'transfer',
                'quantity'          => (float) $line->quantity,
                // Swapped: send it back where it came from.
                'from_warehouse_id' => $line->to_warehouse_id,
                'to_warehouse_id'   => $line->from_warehouse_id,
                'reason'            => $reason,
            ], $tenantId, $userId);

            return;
        }

        $wasIn = $v->type === 'receipt';
        $this->stock->record([
            ...$ref,
            'product_id'   => $line->product_id,
            'type'         => 'adjustment',
            'direction'    => $wasIn ? 'out' : 'in',
            'quantity'     => (float) $line->quantity,
            'warehouse_id' => $line->warehouse_id ?? $v->warehouse_id,
            'location_id'  => $line->location_id,
            'reason'       => $reason,
        ], $tenantId, $userId);
    }

    private function movementTypeFor(Voucher $v): string
    {
        if ($v->type === 'loss_adjustment') {
            return 'lost';        // adjustment handled separately above
        }

        return Voucher::TYPES[$v->type][2];
    }

    /* ── Line handling ──────────────────────────────────────────── */

    /** Replace the whole grid — the form always submits the full line set. */
    private function replaceItems(Voucher $voucher, array $items, int $tenantId): void
    {
        VoucherItem::forTenant($tenantId)->where('voucher_id', $voucher->id)->delete();

        foreach ($items as $row) {
            if (empty($row['product_id'])) {
                continue;
            }
            $this->assertProduct((int) $row['product_id'], $tenantId);

            $qty = round((float) ($row['quantity'] ?? 0), 3);
            $price = round((float) ($row['unit_price'] ?? 0), 2);
            $taxRate = round((float) ($row['tax_rate'] ?? 0), 2);
            $amount = round($qty * $price, 2);

            VoucherItem::create([
                'tenant_id'         => $tenantId,
                'voucher_id'        => $voucher->id,
                'product_id'        => $row['product_id'],
                'warehouse_id'      => $row['warehouse_id'] ?? $voucher->warehouse_id,
                'from_warehouse_id' => $row['from_warehouse_id'] ?? null,
                'to_warehouse_id'   => $row['to_warehouse_id'] ?? null,
                'location_id'       => $row['location_id'] ?? null,
                'quantity'          => $qty,
                'unit_price'        => $price,
                'tax_rate'          => $taxRate,
                'amount'            => $amount,
                'available_qty'     => $this->onHand((int) $row['product_id'], $row['warehouse_id'] ?? $voucher->warehouse_id, $tenantId),
                'lot_number'        => $row['lot_number'] ?? null,
                'expiry_date'       => $row['expiry_date'] ?? null,
                'note'              => $row['note'] ?? null,
            ]);
        }

        $this->recalcTotals($voucher);
    }

    /** Header totals are always derived from the lines — never typed in. */
    private function recalcTotals(Voucher $voucher): void
    {
        $rows = $voucher->items()->get();

        $goods = round($rows->sum(fn ($r) => (float) $r->amount), 2);
        $tax = round($rows->sum(fn ($r) => (float) $r->amount * (float) $r->tax_rate / 100), 2);

        $voucher->forceFill([
            'total_goods'  => $goods,
            'total_tax'    => $tax,
            'total_amount' => round($goods + $tax, 2),
        ])->save();
    }

    private function onHand(int $productId, $warehouseId, int $tenantId): ?float
    {
        if (! $warehouseId) {
            return null;
        }

        return (float) \App\Models\Inventory\Stock::forTenant($tenantId)
            ->where('product_id', $productId)->where('warehouse_id', $warehouseId)->sum('quantity');
    }

    /* ── Helpers ────────────────────────────────────────────────── */

    public function find(int $id, int $tenantId): Voucher
    {
        return Voucher::forTenant($tenantId)->find($id)
            ?? throw new BusinessException('That voucher does not exist.', 404);
    }

    public function assertType(string $type): void
    {
        if (! isset(Voucher::TYPES[$type])) {
            throw new BusinessException('Unknown voucher type.', 404);
        }
    }

    private function assertEditable(Voucher $v): void
    {
        if (! $v->isEditable()) {
            throw new BusinessException('Only a draft voucher can be edited. This one is '.$v->status.'.', 422);
        }
    }

    private function assertProduct(int $id, int $tenantId): void
    {
        $p = Product::forTenant($tenantId)->find($id);
        if (! $p) {
            throw new BusinessException('A line refers to a product that does not exist.', 422);
        }
        if ($p->without_checking_warehouse) {
            throw new BusinessException("“{$p->name}” is set to not update inventory numbers, so it can't go on a stock voucher.", 422);
        }
    }

    /** Sequential per tenant AND per type: RCV-0001, DLV-0001 … */
    private function nextCode(string $type, int $tenantId): string
    {
        $prefix = Voucher::TYPES[$type][1];

        do {
            $n = Voucher::forTenant($tenantId)->where('type', $type)->withTrashed()->count() + 1;
            $code = sprintf('%s-%04d', $prefix, $n);
            $taken = Voucher::forTenant($tenantId)->where('type', $type)->withTrashed()->where('code', $code)->exists();
            if ($taken) {
                $code = sprintf('%s-%04d', $prefix, $n + random_int(1, 9999));
            }
        } while (Voucher::forTenant($tenantId)->where('type', $type)->withTrashed()->where('code', $code)->exists());

        return $code;
    }
}
