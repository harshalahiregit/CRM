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
    public function __construct(
        private StockService $stock,
        private InventoryNotifier $notifier,
        private ApprovalService $approvals,
        private ReceivingService $receiving,
    ) {
    }

    /* ── Reads ──────────────────────────────────────────────────── */

    /**
     * @param  array  $viewer  ['user_id' => int, 'is_admin' => bool, 'warehouse_ids' => int[]]
     *                         Used only to decorate rows with what this person
     *                         may do, so the UI never offers a refused action.
     */
    public function list(string $type, int $tenantId, array $filters = [], array $viewer = []): Collection
    {
        $this->assertType($type);

        $q = Voucher::forTenant($tenantId)->where('type', $type)
            ->with(['warehouse:id,name', 'creator:id,name'])
            ->withCount('items');

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        // Who raised it — lets an admin answer "show me everything Rohit filed".
        if (! empty($filters['created_by'])) {
            $q->where('created_by', $filters['created_by']);
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

        return $this->decorateApproval($q->latest('id')->get(), $viewer);
    }

    /**
     * Tell the client two things it cannot work out for itself: whether this
     * document has to go through the approval gate, and whether THIS viewer is
     * allowed to decide on it. Without them the UI would either hide the button
     * from the person who needs it or offer one that 403s.
     */
    private function decorateApproval(Collection $rows, array $viewer): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $userId = (int) ($viewer['user_id'] ?? 0);
        $isAdmin = (bool) ($viewer['is_admin'] ?? false);
        $managed = array_map('intval', $viewer['warehouse_ids'] ?? []);

        return $rows->each(function (Voucher $v) use ($userId, $isAdmin, $managed) {
            $v->setAttribute('needs_approval', $this->approvals->isRequired($v));

            // You can never approve your own document — that is the whole point
            // of a second pair of eyes, so the button is not offered either.
            $v->setAttribute('can_approve',
                $v->status === 'pending_approval'
                && (int) $v->created_by !== $userId
                && ($isAdmin || ($v->warehouse_id && in_array((int) $v->warehouse_id, $managed, true)))
            );
        });
    }

    public function show(int $id, int $tenantId): Voucher
    {
        $v = $this->find($id, $tenantId);
        $v->load([
            'items.product:id,sku,name,base_unit',
            'items.warehouse:id,name', 'items.fromWarehouse:id,name', 'items.toWarehouse:id,name',
            'warehouse:id,name', 'creator:id,name', 'staff:id,name',
            'approver:id,name', 'rejecter:id,name',
        ]);

        // A receipt carries its own headline: ordered vs received vs accepted,
        // whether it was short, and whether a vendor return is still owed. The
        // client would otherwise have to derive all of that from the lines and
        // could easily derive it differently from the server.
        if ($v->type === 'receipt') {
            $v->setAttribute('receiving', $this->receiving->summary($v));
        }

        $v->setAttribute('needs_approval', $this->approvals->isRequired($v));

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

        // The approval gate, if this workspace asked for one. Checked here rather
        // than in the controller because posting is the only moment stock moves —
        // any other path to it would otherwise walk straight past the rule.
        $this->approvals->assertPostable($voucher);

        // A document travelling as a consignment has already had its stock moved
        // out of the source by the dispatch. Posting it here would write a second
        // set of movements for the same goods and double the transfer.
        $this->assertNotTravelling($voucher, $tenantId, 'posted');

        $items = VoucherItem::forTenant($tenantId)->where('voucher_id', $voucher->id)->get();
        if ($items->isEmpty()) {
            throw new BusinessException('Add at least one line before posting.', 422);
        }

        // A receipt where inspection rejected everything would post silently and
        // move nothing, leaving a "posted" document and an unchanged shelf.
        // Better to say so than to look like it worked.
        if ($voucher->type === 'receipt' && $items->sum(fn ($i) => $i->postableQuantity()) <= 0) {
            throw new BusinessException(
                'Every line on this receipt was rejected, so there is nothing to put on the shelf. Raise a vendor return instead.',
                422
            );
        }

        // Snapshot on-hand BEFORE the movement so the alert below can tell
        // "just fell below the reorder point" apart from "has been low for a
        // week" — only the first of those is news.
        $productIds = $items->pluck('product_id')->filter()->map(fn ($i) => (int) $i)->unique()->values()->all();
        $before = $this->onHandTotals($productIds, $tenantId);

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

        // Outside the transaction: an alert must never be able to roll back the
        // movement it is announcing.
        $this->announce($voucher, $productIds, $before, $tenantId, $userId, fn () => $this->notifier->voucherPosted($voucher, $userId));

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

        // Same reason as posting: the consignment owns those movements, and its
        // own cancel knows how to turn the lorry round. A blind reversal here
        // would send stock back from a warehouse it never reached.
        $this->assertNotTravelling($voucher, $tenantId, 'cancelled');

        $wasPosted = $voucher->status === 'posted';
        $productIds = $voucher->items()->pluck('product_id')->filter()->map(fn ($i) => (int) $i)->unique()->values()->all();
        $before = $wasPosted ? $this->onHandTotals($productIds, $tenantId) : [];

        DB::transaction(function () use ($voucher, $tenantId, $userId, $wasPosted) {
            if ($wasPosted) {
                foreach ($voucher->items()->get() as $line) {
                    $this->reverseLine($voucher, $line, $tenantId, $userId);
                }
            }
            $voucher->forceFill(['status' => 'cancelled'])->save();
        });

        // Reversing a receipt takes stock back out, so a cancellation can put an
        // item below its reorder point just as surely as an issue can.
        $this->announce(
            $voucher, $wasPosted ? $productIds : [], $before, $tenantId, $userId,
            fn () => $this->notifier->voucherCancelled($voucher, $userId, $wasPosted),
        );

        return $this->show($voucher->id, $tenantId);
    }

    /* ── Alerts ─────────────────────────────────────────────────── */

    /**
     * Fire the "something happened to this document" notice, then work out which
     * items crossed their reorder point as a result.
     *
     * Everything in here is best-effort: the stock has already moved and the
     * ledger already says so, so a failure to tell anyone about it is a
     * degraded alert, not a failed posting.
     */
    private function announce(Voucher $voucher, array $productIds, array $before, int $tenantId, int $userId, callable $documentNotice): void
    {
        try {
            $documentNotice();

            if (! $productIds) {
                return;
            }

            $after = $this->onHandTotals($productIds, $tenantId);

            $products = Product::forTenant($tenantId)
                ->whereIn('id', $productIds)
                ->where('status', 'active')
                // Items excluded from stock math can't be low — they have no stock.
                ->where('without_checking_warehouse', false)
                ->get(['id', 'name', 'sku', 'min_stock', 'reorder_point']);

            $crossed = [];
            foreach ($products as $p) {
                $threshold = (float) ($p->reorder_point ?: $p->min_stock);
                if ($threshold <= 0) {
                    continue;   // nobody set a level, so there's no line to cross
                }

                $was = (float) ($before[$p->id] ?? 0);
                $now = (float) ($after[$p->id] ?? 0);

                if ($was > $threshold && $now <= $threshold) {
                    $crossed[] = ['product' => $p, 'on_hand' => $now, 'threshold' => $threshold];
                }
            }

            $this->notifier->stockCrossedThreshold($tenantId, $crossed, $voucher->warehouse_id, $userId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                "Inventory alerts failed for voucher {$voucher->code}: {$e->getMessage()}"
            );
        }
    }

    /** Total on-hand per product across every warehouse, keyed by product id. */
    private function onHandTotals(array $productIds, int $tenantId): array
    {
        if (! $productIds) {
            return [];
        }

        $totals = array_fill_keys($productIds, 0.0);

        $rows = \App\Models\Inventory\Stock::forTenant($tenantId)
            ->whereIn('product_id', $productIds)
            ->selectRaw('product_id, COALESCE(SUM(quantity), 0) as qty')
            ->groupBy('product_id')
            ->get();

        foreach ($rows as $row) {
            $totals[(int) $row->product_id] = (float) $row->qty;
        }

        return $totals;
    }

    /**
     * An internal note that was handed to a consignment is no longer this
     * service's to move: the dispatch already took the stock out of the source
     * and the transfer owns the rest of the journey. Acting on it here would
     * write a second set of movements for goods that have already left.
     *
     * Resolved lazily through the container rather than injected, because the
     * dependency only runs the other way in practice and a constructor pair
     * would be a cycle.
     */
    private function assertNotTravelling(Voucher $voucher, int $tenantId, string $what): void
    {
        if ($voucher->type !== 'internal') {
            return;
        }

        $live = app(TransferService::class)->liveTransferFor($voucher->id, $tenantId);
        if (! $live) {
            return;
        }

        throw new BusinessException(
            "This note is travelling as consignment {$live->code}, so it cannot be {$what} here. "
                .'Use the consignment to dispatch, receive or turn it round.',
            422
        );
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

        // A RECEIPT posts what was ACCEPTED, not what turned up. Damaged goods
        // are in the building but must never become sellable stock, or the next
        // customer order picks a broken unit. postableQuantity() falls back to
        // the line quantity when nobody inspected, so receipts written before
        // inspection existed keep meaning exactly what they meant.
        $qty = $v->type === 'receipt' ? $line->postableQuantity() : (float) $line->quantity;

        // A line rejected in full moves nothing at all — and record() refuses a
        // zero quantity, so it has to be skipped rather than posted as zero.
        if ($qty <= 0) {
            return;
        }

        $this->stock->record([
            ...$ref,
            'product_id'        => $line->product_id,
            'type'              => $this->movementTypeFor($v),
            'quantity'          => $qty,
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

        // Take back exactly what went in. A receipt that only accepted 8 of 10
        // put 8 on the shelf, so reversing 10 would invent two units out of an
        // inspection failure.
        $qty = $wasIn ? $line->postableQuantity() : (float) $line->quantity;
        if ($qty <= 0) {
            return;
        }

        $this->stock->record([
            ...$ref,
            'product_id'   => $line->product_id,
            'type'         => 'adjustment',
            'direction'    => $wasIn ? 'out' : 'in',
            'quantity'     => $qty,
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
            // A discount can never exceed the line it discounts — otherwise the
            // header total could go negative on a typo.
            $discount = min(round((float) ($row['discount'] ?? 0), 2), $amount);

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
                'discount'          => $discount,
                'amount'            => $amount,
                'available_qty'     => $this->onHand((int) $row['product_id'], $row['warehouse_id'] ?? $voucher->warehouse_id, $tenantId),
                'lot_number'        => $row['lot_number'] ?? null,
                'expiry_date'       => $row['expiry_date'] ?? null,
                'note'              => $row['note'] ?? null,
            ]);
        }

        $this->recalcTotals($voucher);
    }

    /**
     * Header totals are always derived from the lines — never typed in.
     *
     * Discount comes off the goods before tax (tax is charged on what's actually
     * paid), and "value of inventory" is the stock value the document moves,
     * costed at each item's cost price rather than its selling price — which is
     * why it differs from total_amount on a delivery.
     */
    private function recalcTotals(Voucher $voucher): void
    {
        $rows = $voucher->items()->with('product:id,cost_price')->get();

        $goods = round($rows->sum(fn ($r) => (float) $r->amount), 2);
        $discount = round($rows->sum(fn ($r) => (float) $r->discount), 2);
        $net = max(0, $goods - $discount);
        $tax = round($rows->sum(function ($r) use ($goods, $discount) {
            // Spread the discount across lines in proportion to their value, so
            // tax is charged on the discounted amount of each line.
            $share = $goods > 0 ? ((float) $r->amount / $goods) * $discount : 0;

            return max(0, (float) $r->amount - $share) * (float) $r->tax_rate / 100;
        }), 2);

        $inventoryValue = round($rows->sum(
            fn ($r) => (float) $r->quantity * (float) ($r->product->cost_price ?? 0)
        ), 2);

        $voucher->forceFill([
            'total_goods'     => $goods,
            'total_discount'  => $discount,
            'total_tax'       => $tax,
            'total_amount'    => round($net + $tax, 2),
            'inventory_value' => $inventoryValue,
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
