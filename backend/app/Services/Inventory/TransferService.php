<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\Stock;
use App\Models\Inventory\Transfer;
use App\Models\Inventory\TransferLine;
use App\Models\Inventory\Voucher;
use App\Models\Inventory\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Transfers with a real in-transit state (§15).
 *
 * The rule this exists to fix: an internal transfer used to move stock out of
 * one warehouse and into another in the same instant. True for two racks in one
 * building; a lie when a lorry takes three days. For those three days the
 * destination's figures claimed goods were on a shelf nobody could pick from,
 * and a carton that fell off the back went unnoticed until somebody counted.
 *
 * So goods on the road live in the tenant's TRANSIT warehouse — a real place
 * with a real balance. Three consequences worth having:
 *
 *   1. "Where is my stock?" answers with a location, not a null.
 *   2. The balance can never go negative, so the ledger's own arithmetic
 *      refuses a destination that claims to receive more than was sent.
 *   3. A shortfall does NOT evaporate. It stays in transit until somebody
 *      either receives it late or deliberately writes it off — which is the
 *      entire point, because a loss nobody had to sign for is a loss nobody
 *      investigates.
 */
class TransferService
{
    private const TRANSIT_CODE = 'TRANSIT';

    public function __construct(
        private StockService $stock,
        private InventoryNotifier $notifier,
    ) {
    }

    /* ── Reading ────────────────────────────────────────────────── */

    public function list(int $tenantId, array $f = []): array
    {
        $q = Transfer::forTenant($tenantId)
            // `type` is required, not cosmetic: Voucher appends `type_label`,
            // whose accessor reads it, so omitting it throws on serialisation.
            ->with(['fromWarehouse:id,name', 'toWarehouse:id,name', 'dispatcher:id,name', 'receiver:id,name', 'voucher:id,code,type'])
            ->withCount('lines');

        foreach (['status', 'from_warehouse_id', 'to_warehouse_id'] as $key) {
            if (! empty($f[$key])) {
                $q->where($key, $f[$key]);
            }
        }

        // "Inbound to me" is the queue a receiving site works from.
        if (! empty($f['inbound_to'])) {
            $q->where('to_warehouse_id', $f['inbound_to'])->where('status', 'in_transit');
        }
        // Late and still on the road — the only definition of overdue that means
        // anything, since a delivered-late consignment is no longer a problem.
        if (! empty($f['overdue'])) {
            $q->where('status', 'in_transit')->whereNotNull('expected_at')->whereDate('expected_at', '<', now());
        }
        if (! empty($f['search'])) {
            $s = '%'.$f['search'].'%';
            $q->where(fn ($w) => $w->where('code', 'like', $s)
                ->orWhere('tracking_number', 'like', $s)
                ->orWhere('vehicle_no', 'like', $s));
        }

        return $q->latest('id')->get()->all();
    }

    public function show(int $id, int $tenantId): Transfer
    {
        return Transfer::forTenant($tenantId)
            ->with([
                'lines.product:id,sku,name,base_unit,barcode,cost_price',
                'fromWarehouse:id,name,code', 'toWarehouse:id,name,code',
                'dispatcher:id,name', 'receiver:id,name', 'creator:id,name', 'voucher:id,code,type',
            ])
            ->find($id) ?? throw new BusinessException('That transfer does not exist.', 404);
    }

    /** What is still on the road, and whether anything is late. */
    public function summaryOf(Transfer $t): array
    {
        $lines = $t->relationLoaded('lines') ? $t->lines : $t->lines()->with('product:id,name,cost_price')->get();

        $out = $short = $value = 0.0;
        $problems = [];

        foreach ($lines as $l) {
            $out += $l->outstanding();

            if ($l->received_qty !== null && $l->outstanding() > 0) {
                $short += $l->outstanding();
                $value += $l->outstanding() * (float) ($l->product->cost_price ?? 0);
                $problems[] = [
                    'line_id'    => $l->id,
                    'product'    => $l->product->name ?? 'Item',
                    'dispatched' => (float) $l->dispatched_qty,
                    'received'   => (float) $l->received_qty,
                    'missing'    => $l->outstanding(),
                    'value'      => round($l->outstanding() * (float) ($l->product->cost_price ?? 0), 2),
                ];
            }
        }

        return [
            'lines'            => $lines->count(),
            'outstanding_qty'  => round($out, 3),
            'short_qty'        => round($short, 3),
            'short_value'      => round($value, 2),
            'problems'         => $problems,
            'overdue'          => $t->isOverdue(),
            // A consignment is only finished when every unit is accounted for:
            // arrived, or signed off as lost. Anything else is still open.
            'fully_accounted'  => round($out, 3) <= 0,
        ];
    }

    /* ── Raising ────────────────────────────────────────────────── */

    /**
     * Turn an internal delivery note into a consignment.
     *
     * The voucher says what should move between two sites; this says how it
     * physically travelled and what actually turned up.
     */
    public function createFromVoucher(int $voucherId, array $data, int $tenantId, int $userId): Transfer
    {
        $voucher = Voucher::forTenant($tenantId)->with('items')->find($voucherId)
            ?? throw new BusinessException('That document does not exist.', 404);

        if ($voucher->type !== 'internal') {
            throw new BusinessException('Only an internal delivery note can be sent as a consignment.', 422);
        }
        if ($voucher->status === 'posted') {
            throw new BusinessException('This transfer has already been posted, so the stock has already moved.', 422);
        }
        if ($voucher->status === 'cancelled') {
            throw new BusinessException('This document was cancelled.', 422);
        }
        if ($this->liveTransferFor($voucherId, $tenantId)) {
            throw new BusinessException('This document is already travelling as a consignment.', 422);
        }
        if ($voucher->items->isEmpty()) {
            throw new BusinessException('Add at least one line before sending it.', 422);
        }

        // Every line on an internal note carries its own pair of warehouses, so
        // one document could in principle span several routes. A consignment is
        // one lorry going one way — mixing routes into it would make "what is on
        // this vehicle" unanswerable.
        $routes = $voucher->items
            ->map(fn ($i) => ((int) $i->from_warehouse_id).'>'.((int) $i->to_warehouse_id))
            ->unique();

        if ($routes->count() !== 1) {
            throw new BusinessException(
                'This document moves stock along more than one route, so it cannot travel as a single consignment. Split it into one note per route.',
                422
            );
        }

        $first = $voucher->items->first();

        return $this->create([
            ...$data,
            'voucher_id'        => $voucher->id,
            'from_warehouse_id' => (int) $first->from_warehouse_id,
            'to_warehouse_id'   => (int) $first->to_warehouse_id,
            'lines' => $voucher->items->map(fn ($i) => [
                'product_id'       => (int) $i->product_id,
                'quantity'         => (float) $i->quantity,
                'from_location_id' => $i->location_id,
            ])->all(),
        ], $tenantId, $userId);
    }

    /** Raise a consignment directly, with no paperwork in front of it. */
    public function create(array $data, int $tenantId, int $userId): Transfer
    {
        $from = (int) ($data['from_warehouse_id'] ?? 0);
        $to = (int) ($data['to_warehouse_id'] ?? 0);

        if (! $from || ! $to || $from === $to) {
            throw new BusinessException('A transfer needs a different source and destination warehouse.', 422);
        }
        foreach ([$from, $to] as $id) {
            if (! Warehouse::forTenant($tenantId)->whereKey($id)->exists()) {
                throw new BusinessException('That warehouse does not exist.', 404);
            }
        }

        $lines = array_values(array_filter((array) ($data['lines'] ?? []), fn ($l) => (float) ($l['quantity'] ?? 0) > 0));
        if (! $lines) {
            throw new BusinessException('Add at least one line with a quantity to send.', 422);
        }

        return DB::transaction(function () use ($data, $lines, $from, $to, $tenantId, $userId) {
            $transfer = Transfer::create([
                'tenant_id'         => $tenantId,
                'code'              => $this->nextCode($tenantId),
                'voucher_id'        => $data['voucher_id'] ?? null,
                'from_warehouse_id' => $from,
                'to_warehouse_id'   => $to,
                'status'            => 'draft',
                'expected_at'       => $data['expected_at'] ?? null,
                'carrier'           => $data['carrier'] ?? null,
                'tracking_number'   => $data['tracking_number'] ?? null,
                'vehicle_no'        => $data['vehicle_no'] ?? null,
                'driver_name'       => $data['driver_name'] ?? null,
                'driver_phone'      => $data['driver_phone'] ?? null,
                'note'              => $data['note'] ?? null,
                'created_by'        => $userId,
            ]);

            foreach ($lines as $l) {
                $transfer->lines()->create([
                    'tenant_id'        => $tenantId,
                    'product_id'       => (int) $l['product_id'],
                    'dispatched_qty'   => round((float) $l['quantity'], 3),
                    'from_location_id' => $l['from_location_id'] ?? null,
                    'status'           => 'in_transit',
                ]);
            }

            return $transfer;
        });
    }

    /* ── Dispatch ───────────────────────────────────────────────── */

    /**
     * The lorry leaves. Stock moves out of the source and into transit.
     *
     * This is a real ledger movement, not a status change: the source's shelf
     * genuinely no longer has it, and somebody looking for it should be told
     * where it actually is.
     */
    public function dispatch(int $id, array $data, int $tenantId, int $userId): Transfer
    {
        $transfer = $this->find($id, $tenantId);

        if ($transfer->status !== 'draft') {
            throw new BusinessException(
                $transfer->status === 'in_transit'
                    ? 'This consignment has already left.'
                    : 'Only a consignment that has not left yet can be dispatched.',
                422
            );
        }

        $transit = $this->transitWarehouse($tenantId);
        $lines = $transfer->lines()->get();

        DB::transaction(function () use ($transfer, $lines, $transit, $data, $tenantId, $userId) {
            foreach ($lines as $line) {
                $this->stock->record([
                    'reference_type'    => 'transfer',
                    'reference_id'      => $transfer->id,
                    'product_id'        => $line->product_id,
                    'type'              => 'transfer',
                    'quantity'          => (float) $line->dispatched_qty,
                    'from_warehouse_id' => $transfer->from_warehouse_id,
                    'from_location_id'  => $line->from_location_id,
                    'to_warehouse_id'   => $transit->id,
                    'reason'            => 'Dispatched on '.$transfer->code,
                ], $tenantId, $userId);
            }

            $transfer->forceFill([
                'status'          => 'in_transit',
                'dispatched_at'   => now(),
                'dispatched_by'   => $userId,
                'expected_at'     => $data['expected_at'] ?? $transfer->expected_at,
                'carrier'         => $data['carrier'] ?? $transfer->carrier,
                'tracking_number' => $data['tracking_number'] ?? $transfer->tracking_number,
                'vehicle_no'      => $data['vehicle_no'] ?? $transfer->vehicle_no,
                'driver_name'     => $data['driver_name'] ?? $transfer->driver_name,
                'driver_phone'    => $data['driver_phone'] ?? $transfer->driver_phone,
            ])->save();

            // The paperwork is done with: the stock has left its source and the
            // consignment now owns the rest of the journey. Marked directly
            // rather than through post(), because posting would write a second
            // set of movements for stock that has already moved.
            if ($transfer->voucher_id) {
                Voucher::forTenant($tenantId)->whereKey($transfer->voucher_id)
                    ->update(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $userId]);
            }
        });

        $fresh = $this->show($id, $tenantId);
        $this->notifier->transferDispatched($fresh, $userId);

        return $fresh;
    }

    /* ── Receiving ──────────────────────────────────────────────── */

    /**
     * Confirm what actually turned up.
     *
     * Quantities ADD rather than replace, so a consignment that arrives in two
     * loads — or whose missing carton is found on the next run — is recorded as
     * what really happened instead of overwriting the first receipt.
     *
     * @param  array  $lines  [['id' => n, 'received_qty' => n, 'note' => ?], ...]
     */
    public function receive(int $id, array $lines, array $data, int $tenantId, int $userId): Transfer
    {
        $transfer = $this->find($id, $tenantId);

        if ($transfer->status !== 'in_transit') {
            throw new BusinessException(
                $transfer->status === 'draft'
                    ? 'This consignment has not been dispatched yet.'
                    : 'This consignment is '.$transfer->status.' — it can no longer be received.',
                422
            );
        }

        $transit = $this->transitWarehouse($tenantId);
        $known = $transfer->lines()->with('product:id,name')->get()->keyBy('id');

        DB::transaction(function () use ($lines, $known, $transfer, $transit, $data, $tenantId, $userId) {
            foreach ($lines as $row) {
                $line = $known->get((int) ($row['id'] ?? 0))
                    ?? throw new BusinessException('One of those lines is not on this consignment.', 422);

                $qty = round((float) ($row['received_qty'] ?? 0), 3);
                if ($qty < 0) {
                    throw new BusinessException('A received quantity cannot be negative.', 422);
                }
                // Receiving more than is still on the road would take units out
                // of the transit balance that were never put in. The ledger
                // would refuse it anyway; saying so plainly is kinder than a
                // "not enough stock at that warehouse" error about a warehouse
                // nobody knew existed.
                if ($qty > $line->outstanding() + 0.0005) {
                    throw new BusinessException(
                        'You cannot receive more than was sent. '.($line->product?->name ?? 'That item').
                        ' has '.$this->fmt($line->outstanding()).' still on the road. If more arrived than was dispatched, the source count was wrong — receive what was sent and raise a count at the source.',
                        422
                    );
                }

                if ($qty > 0) {
                    $this->stock->record([
                        'reference_type'    => 'transfer_receive',
                        'reference_id'      => $transfer->id,
                        'product_id'        => $line->product_id,
                        'type'              => 'transfer',
                        'quantity'          => $qty,
                        'from_warehouse_id' => $transit->id,
                        'to_warehouse_id'   => $transfer->to_warehouse_id,
                        'to_location_id'    => $row['to_location_id'] ?? $line->to_location_id,
                        'reason'            => 'Received on '.$transfer->code,
                    ], $tenantId, $userId);
                }

                $line->forceFill([
                    'received_qty'   => round((float) ($line->received_qty ?? 0) + $qty, 3),
                    'to_location_id' => $row['to_location_id'] ?? $line->to_location_id,
                    'note'           => $row['note'] ?? $line->note,
                ])->save();

                $line->forceFill(['status' => $line->outstanding() > 0 ? 'short' : 'received'])->save();
            }

            $transfer->forceFill([
                'received_at' => now(),
                'received_by' => $userId,
            ])->save();

            // Everything accounted for closes the consignment. Anything still
            // out there keeps it open, because an unexplained shortfall is the
            // one thing this whole feature exists to stop losing.
            if ($this->outstandingOf($transfer) <= 0) {
                $transfer->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
            }
        });

        $fresh = $this->show($id, $tenantId);
        $summary = $this->summaryOf($fresh);

        $this->notifier->transferReceived($fresh, $userId, $summary);

        return $fresh;
    }

    /* ── Transit loss ───────────────────────────────────────────── */

    /**
     * Sign off what never arrived.
     *
     * Deliberately a separate act with a required reason. Inferring the loss the
     * moment a receipt comes up short would make the shortfall disappear at the
     * exact instant somebody should have been asked about it — and a write-off
     * nobody had to sign is a write-off nobody investigates.
     *
     * @param  array  $lines  [['id' => n, 'lost_qty' => n], ...]
     */
    public function writeOffLoss(int $id, array $lines, string $reason, int $tenantId, int $userId): Transfer
    {
        $transfer = $this->find($id, $tenantId);

        if ($transfer->status !== 'in_transit') {
            throw new BusinessException('Only a consignment still on the road can have a loss written off.', 422);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new BusinessException('Say what happened to it — a write-off with no reason is stock that vanished twice.', 422);
        }

        $transit = $this->transitWarehouse($tenantId);
        $known = $transfer->lines()->with('product:id,name')->get()->keyBy('id');

        DB::transaction(function () use ($lines, $known, $transfer, $transit, $reason, $tenantId, $userId) {
            foreach ($lines as $row) {
                $line = $known->get((int) ($row['id'] ?? 0))
                    ?? throw new BusinessException('One of those lines is not on this consignment.', 422);

                $qty = round((float) ($row['lost_qty'] ?? 0), 3);
                if ($qty <= 0) {
                    continue;
                }
                if ($qty > $line->outstanding() + 0.0005) {
                    throw new BusinessException(
                        'You cannot write off more than is still on the road for '.($line->product?->name ?? 'that item').'.',
                        422
                    );
                }

                // Out of the transit warehouse as an ordinary loss, so it lands
                // in the ledger looking exactly like every other write-off and
                // shows up in the same reports.
                $this->stock->record([
                    'reference_type' => 'transfer_loss',
                    'reference_id'   => $transfer->id,
                    'product_id'     => $line->product_id,
                    'type'           => 'lost',
                    'quantity'       => $qty,
                    'warehouse_id'   => $transit->id,
                    'reason'         => 'Transit loss on '.$transfer->code.' — '.$reason,
                ], $tenantId, $userId);

                $line->forceFill([
                    'lost_qty' => round((float) $line->lost_qty + $qty, 3),
                    'note'     => $reason,
                ])->save();

                $line->forceFill(['status' => $line->outstanding() > 0 ? 'short' : 'written_off'])->save();
            }

            if ($this->outstandingOf($transfer) <= 0) {
                $transfer->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
            }
        });

        $fresh = $this->show($id, $tenantId);
        $this->notifier->transferLoss($fresh, $userId, $reason, $this->summaryOf($fresh));

        return $fresh;
    }

    /* ── Turning back ───────────────────────────────────────────── */

    /**
     * Cancel. A draft simply stops; a consignment already on the road is turned
     * round, and whatever is still out there goes back on the source's shelf.
     * Leaving it stranded in transit would be a balance nobody owns.
     */
    public function cancel(int $id, int $tenantId, int $userId): Transfer
    {
        $transfer = $this->find($id, $tenantId);

        if ($transfer->isClosed()) {
            throw new BusinessException('This consignment is '.$transfer->status.' — it cannot be cancelled.', 422);
        }

        $transit = $this->transitWarehouse($tenantId);

        DB::transaction(function () use ($transfer, $transit, $tenantId, $userId) {
            if ($transfer->status === 'in_transit') {
                foreach ($transfer->lines()->get() as $line) {
                    $back = $line->outstanding();
                    if ($back <= 0) {
                        continue;
                    }

                    $this->stock->record([
                        'reference_type'    => 'transfer_return',
                        'reference_id'      => $transfer->id,
                        'product_id'        => $line->product_id,
                        'type'              => 'transfer',
                        'quantity'          => $back,
                        'from_warehouse_id' => $transit->id,
                        'to_warehouse_id'   => $transfer->from_warehouse_id,
                        'reason'            => 'Returned to source — '.$transfer->code.' cancelled',
                    ], $tenantId, $userId);
                }
                // The line is left saying exactly what was dispatched and what
                // arrived. Rewriting it to hide the turn-round would erase the
                // fact that a lorry went out at all; the return movement is what
                // explains where the stock ended up. Reconciliation only counts
                // consignments that are still in transit, so a cancelled one
                // cannot leave a phantom balance behind.
            }

            $transfer->forceFill(['status' => 'cancelled', 'closed_at' => now()])->save();

            // The document goes back to being a draft it can be re-sent from —
            // the stock is where it started, so calling it posted would be false.
            if ($transfer->voucher_id) {
                Voucher::forTenant($tenantId)->whereKey($transfer->voucher_id)
                    ->where('status', 'posted')
                    ->update(['status' => 'draft', 'posted_at' => null, 'posted_by' => null]);
            }
        });

        return $this->show($id, $tenantId);
    }

    /* ── Reconciliation ─────────────────────────────────────────── */

    /**
     * The module's one cross-check on this feature: the transit warehouse's
     * balance must equal what every open consignment still says is on the road.
     *
     * If those two disagree, something moved stock in or out of transit without
     * going through a consignment — which is exactly the kind of quiet drift
     * that makes people stop trusting the numbers. Better to surface it than to
     * present a tidy figure that happens to be wrong.
     */
    public function reconcile(int $tenantId): array
    {
        $transit = $this->transitWarehouse($tenantId);

        $onHand = Stock::forTenant($tenantId)->where('warehouse_id', $transit->id)
            ->selectRaw('product_id, SUM(quantity) as qty')->groupBy('product_id')
            ->pluck('qty', 'product_id')->map(fn ($q) => (float) $q)->all();

        $expected = [];
        $openIds = Transfer::forTenant($tenantId)->where('status', 'in_transit')->pluck('id');

        foreach (TransferLine::forTenant($tenantId)->whereIn('transfer_id', $openIds)->with('product:id,sku,name')->get() as $l) {
            $expected[$l->product_id] = ($expected[$l->product_id] ?? 0) + $l->outstanding();
        }

        $rows = [];
        foreach (array_unique([...array_keys($onHand), ...array_keys($expected)]) as $pid) {
            $have = round($onHand[$pid] ?? 0, 3);
            $want = round($expected[$pid] ?? 0, 3);
            if (abs($have - $want) < 0.0005) {
                continue;
            }
            $rows[] = [
                'product_id' => (int) $pid,
                'product'    => \App\Models\Inventory\Product::forTenant($tenantId)->whereKey($pid)->value('name'),
                'in_transit_balance' => $have,
                'consignments_say'   => $want,
                'difference'         => round($have - $want, 3),
            ];
        }

        return [
            'transit_warehouse' => $transit->only(['id', 'name', 'code']),
            'open_consignments' => $openIds->count(),
            'ok'                => $rows === [],
            'problems'          => $rows,
        ];
    }

    /* ── Internals ──────────────────────────────────────────────── */

    /**
     * The tenant's one transit warehouse, created on first use.
     *
     * Hidden from pickers (`display = false`) because nobody walks to it — but a
     * real row all the same, so the stock in it is countable, valued and visible
     * in every report rather than living in a special case.
     */
    public function transitWarehouse(int $tenantId): Warehouse
    {
        $found = Warehouse::forTenant($tenantId)->where('type', 'transit')->orderBy('id')->first();
        if ($found) {
            return $found;
        }

        return Warehouse::create([
            'tenant_id' => $tenantId,
            'name'      => 'In transit',
            'code'      => $this->uniqueTransitCode($tenantId),
            'type'      => 'transit',
            'display'   => false,
            'status'    => 'active',
            'note'      => 'Holds stock that has left one warehouse and not yet arrived at another. Created automatically; do not move stock into it by hand.',
        ]);
    }

    /** Is this document already travelling? */
    public function liveTransferFor(int $voucherId, int $tenantId): ?Transfer
    {
        return Transfer::forTenant($tenantId)
            ->where('voucher_id', $voucherId)
            ->whereNotIn('status', ['cancelled'])
            ->first();
    }

    private function outstandingOf(Transfer $transfer): float
    {
        return round($transfer->lines()->get()->sum(fn (TransferLine $l) => $l->outstanding()), 3);
    }

    private function find(int $id, int $tenantId): Transfer
    {
        return Transfer::forTenant($tenantId)->find($id)
            ?? throw new BusinessException('That transfer does not exist.', 404);
    }

    private function nextCode(int $tenantId): string
    {
        $n = Transfer::withTrashed()->where('tenant_id', $tenantId)->count() + 1;

        do {
            $code = 'TRF-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while (Transfer::withTrashed()->where('tenant_id', $tenantId)->where('code', $code)->exists());

        return $code;
    }

    private function uniqueTransitCode(int $tenantId): string
    {
        $code = self::TRANSIT_CODE;
        $i = 2;
        while (Warehouse::withTrashed()->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
            $code = self::TRANSIT_CODE.'-'.$i;
            $i++;
        }

        return $code;
    }

    private function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.') ?: '0';
    }
}
