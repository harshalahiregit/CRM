<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\PickList;
use App\Models\Inventory\PickListLine;
use App\Models\Inventory\Product;
use App\Models\Inventory\Stock;
use App\Models\Inventory\Voucher;
use Illuminate\Support\Facades\DB;

/**
 * Pick, pack, ship (§11 / §14).
 *
 * The one rule everything here bends around: STOCK DOES NOT MOVE IN THIS CLASS.
 * Picking is someone walking to a shelf; packing is someone counting into a
 * carton. Neither changes what the company owns. Shipping posts the underlying
 * delivery voucher, and THAT is what writes the movement — so the ledger keeps
 * its single, checkable rule that posting is the only moment stock changes.
 *
 * What picking does do is RESERVE. From the moment a picker is sent to a shelf,
 * those units must stop being promised to anybody else, or two orders get sold
 * the same box and one customer gets an apology.
 */
class FulfilmentService
{
    public function __construct(
        private TraceabilityService $trace,
        private VoucherService $vouchers,
        private CarrierService $carriers,
        private InventoryNotifier $notifier,
    ) {
    }

    /* ── Reading ────────────────────────────────────────────────── */

    public function list(int $tenantId, array $f = []): array
    {
        $q = PickList::forTenant($tenantId)
            // `type` is not optional here: Voucher appends `type_label`, whose
            // accessor reads it, so a partial select without it throws on
            // serialisation and takes the whole list down.
            ->with(['warehouse:id,name', 'picker:id,name', 'creator:id,name', 'voucher:id,code,type,customer_name'])
            ->withCount('lines');

        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }
        if (! empty($f['warehouse_id'])) {
            $q->where('warehouse_id', $f['warehouse_id']);
        }
        if (! empty($f['assigned_to'])) {
            $q->where('assigned_to', $f['assigned_to']);
        }
        // "Mine" means the queue a picker actually works from: jobs on their
        // name that have not left the building yet.
        if (! empty($f['mine'])) {
            $q->where('assigned_to', $f['mine'])->whereNotIn('status', PickList::CLOSED);
        }
        if (! empty($f['search'])) {
            $s = '%'.$f['search'].'%';
            $q->where(fn ($w) => $w->where('code', 'like', $s)->orWhere('tracking_number', 'like', $s));
        }

        return $q->latest('id')->get()->all();
    }

    public function show(int $id, int $tenantId): PickList
    {
        return PickList::forTenant($tenantId)
            ->with([
                'lines.product:id,sku,name,base_unit,barcode',
                'lines.location:id,name,code', 'lines.batch:id,batch_no,expiry_date',
                'warehouse:id,name', 'picker:id,name', 'creator:id,name',
                'voucher:id,code,customer_name,address,type',
            ])
            ->find($id) ?? throw new BusinessException('That pick list does not exist.', 404);
    }

    /* ── Creating ───────────────────────────────────────────────── */

    /**
     * Turn a delivery voucher into a job someone can walk.
     *
     * Each line gets a suggested bin and, where the item is batch-tracked, the
     * batch FEFO says to use — so the picker is told where to go instead of
     * deciding for themselves which stock to break into.
     */
    public function createFromVoucher(int $voucherId, array $data, int $tenantId, int $userId): PickList
    {
        $voucher = Voucher::forTenant($tenantId)->with('items')->find($voucherId)
            ?? throw new BusinessException('That document does not exist.', 404);

        if ($voucher->type !== 'delivery') {
            throw new BusinessException('Only a delivery voucher can be picked.', 422);
        }
        if ($voucher->status === 'posted') {
            throw new BusinessException('This delivery has already been posted — the stock has left.', 422);
        }
        if ($voucher->status === 'cancelled') {
            throw new BusinessException('This delivery was cancelled.', 422);
        }
        if (PickList::forTenant($tenantId)->where('voucher_id', $voucherId)
            ->whereNotIn('status', ['cancelled'])->exists()) {
            throw new BusinessException('This delivery already has a pick list.', 422);
        }
        if ($voucher->items->isEmpty()) {
            throw new BusinessException('Add at least one line to the delivery before picking it.', 422);
        }

        $warehouseId = (int) ($data['warehouse_id'] ?? $voucher->warehouse_id);
        if (! $warehouseId) {
            throw new BusinessException('Say which warehouse this is picked from.', 422);
        }

        $list = DB::transaction(function () use ($voucher, $data, $warehouseId, $tenantId, $userId) {
            $list = PickList::create([
                'tenant_id'    => $tenantId,
                'code'         => $this->nextCode($tenantId),
                'voucher_id'   => $voucher->id,
                'warehouse_id' => $warehouseId,
                'status'       => 'open',
                'assigned_to'  => $data['assigned_to'] ?? null,
                'note'         => $data['note'] ?? null,
                'created_by'   => $userId,
            ]);

            foreach ($voucher->items as $item) {
                $qty = (float) $item->quantity;

                $list->lines()->create([
                    'tenant_id'      => $tenantId,
                    'product_id'     => $item->product_id,
                    'required_qty'   => $qty,
                    'location_id'    => $item->location_id ?: $this->suggestBin($item->product_id, $warehouseId, $tenantId),
                    'batch_id'       => $this->suggestBatch($item->product_id, $qty, $warehouseId, $tenantId),
                    'reservation_id' => $this->reserveFor($item->product_id, $qty, $warehouseId, $voucher, $tenantId, $userId),
                ]);
            }

            return $list;
        });

        // Assigning at creation is the common case — the person raising the job
        // usually knows who is walking it. Telling them only when assign() is
        // called separately would mean the most ordinary path stays silent.
        if ($list->assigned_to) {
            $this->notifier->pickListAssigned($list->fresh(), (int) $list->assigned_to, $userId);
        }

        return $list;
    }

    /** Hand the job to a picker. */
    public function assign(int $id, ?int $userId, int $tenantId, int $actorId): PickList
    {
        $list = $this->find($id, $tenantId);
        $this->assertOpenForWork($list);

        $list->forceFill(['assigned_to' => $userId])->save();

        if ($userId) {
            $this->notifier->pickListAssigned($list->fresh(), $userId, $actorId);
        }

        return $this->show($id, $tenantId);
    }

    /* ── Picking ────────────────────────────────────────────────── */

    /**
     * Record what was found on the shelf.
     *
     * @param  array  $lines  [['id' => lineId, 'picked_qty' => n, 'note' => ?], ...]
     */
    public function pick(int $id, array $lines, int $tenantId, int $actorId): PickList
    {
        $list = $this->find($id, $tenantId);
        $this->assertOpenForWork($list);

        $known = $list->lines()->get()->keyBy('id');

        DB::transaction(function () use ($lines, $known, $list) {
            foreach ($lines as $row) {
                $line = $known->get((int) ($row['id'] ?? 0))
                    ?? throw new BusinessException('One of those lines is not on this pick list.', 422);

                $picked = round((float) ($row['picked_qty'] ?? 0), 3);
                if ($picked < 0) {
                    throw new BusinessException('A picked quantity cannot be negative.', 422);
                }
                // Over-picking is refused rather than warned about: the extra
                // units would leave the building on a document that does not
                // account for them, and the ledger would never balance.
                if ($picked > (float) $line->required_qty) {
                    throw new BusinessException(
                        'You cannot pick more than the order needs. Change the delivery document if the order grew.',
                        422
                    );
                }

                $line->forceFill([
                    'picked_qty' => $picked,
                    'note'       => $row['note'] ?? $line->note,
                ])->save();
            }

            $list->forceFill(['status' => 'picking'])->save();
        });

        return $this->show($id, $tenantId);
    }

    /** Picking is finished — hand it to whoever packs. */
    public function completePick(int $id, int $tenantId, int $actorId): PickList
    {
        $list = $this->find($id, $tenantId);
        $this->assertOpenForWork($list);

        if ((float) $list->lines()->sum('picked_qty') <= 0) {
            throw new BusinessException('Nothing has been picked yet.', 422);
        }

        $list->forceFill(['status' => 'picked', 'picked_at' => now()])->save();

        return $this->show($id, $tenantId);
    }

    /* ── Packing ────────────────────────────────────────────────── */

    /**
     * Confirm what actually went into the carton.
     *
     * This is a SECOND count of the same units, and its whole value is that it
     * can disagree with the first. A packed figure that silently copied the
     * picked one would catch nothing.
     */
    public function pack(int $id, array $lines, int $tenantId, int $actorId): PickList
    {
        $list = $this->find($id, $tenantId);

        if (! in_array($list->status, ['picked', 'packed'], true)) {
            throw new BusinessException('Finish picking before packing.', 422);
        }

        $known = $list->lines()->get()->keyBy('id');

        DB::transaction(function () use ($lines, $known, $list) {
            foreach ($lines as $row) {
                $line = $known->get((int) ($row['id'] ?? 0))
                    ?? throw new BusinessException('One of those lines is not on this pick list.', 422);

                $packed = round((float) ($row['packed_qty'] ?? 0), 3);
                if ($packed < 0 || $packed > (float) $line->picked_qty) {
                    throw new BusinessException(
                        'You cannot pack more than was picked — go back and pick the difference first.',
                        422
                    );
                }

                $line->forceFill(['packed_qty' => $packed])->save();
            }

            $list->forceFill(['status' => 'packed', 'packed_at' => now()])->save();
        });

        return $this->show($id, $tenantId);
    }

    /**
     * Does the carton match the pick? Returned rather than enforced, because a
     * short pack is sometimes the right answer (the last one was damaged) — the
     * dispatcher should see the discrepancy and decide, not be blocked by it.
     */
    public function packCheck(PickList $list): array
    {
        $problems = [];

        foreach ($list->lines()->with('product:id,name,sku')->get() as $l) {
            $required = (float) $l->required_qty;
            $picked = (float) $l->picked_qty;
            $packed = (float) $l->packed_qty;

            if ($packed < $picked) {
                $problems[] = [
                    'product' => $l->product->name ?? 'Item',
                    'issue'   => 'packed_short',
                    'detail'  => "Picked {$picked}, packed {$packed}",
                ];
            } elseif ($picked < $required) {
                $problems[] = [
                    'product' => $l->product->name ?? 'Item',
                    'issue'   => 'picked_short',
                    'detail'  => "Order needs {$required}, picked {$picked}",
                ];
            }
        }

        return ['ok' => $problems === [], 'problems' => $problems];
    }

    /* ── Shipping ───────────────────────────────────────────────── */

    /**
     * The parcel leaves. This is the moment stock moves — by posting the
     * underlying delivery voucher, not by writing movements here.
     */
    public function ship(int $id, array $data, int $tenantId, int $actorId): PickList
    {
        $list = $this->find($id, $tenantId);

        if ($list->status !== 'packed') {
            throw new BusinessException('Pack the parcel before shipping it.', 422);
        }

        $booking = $this->carriers->book([
            'carrier'         => $data['carrier'] ?? null,
            'tracking_number' => $data['tracking_number'] ?? null,
            'weight'          => $data['parcel_weight'] ?? null,
            'parcels'         => $data['parcel_count'] ?? 1,
            'reference'       => $list->code,
        ], $tenantId);

        DB::transaction(function () use ($list, $data, $booking, $tenantId, $actorId) {
            $list->forceFill([
                'status'          => 'shipped',
                'shipped_at'      => now(),
                'carrier'         => $booking['carrier'],
                'tracking_number' => $booking['tracking_number'],
                'tracking_url'    => $booking['tracking_url'],
                'parcel_weight'   => $data['parcel_weight'] ?? null,
                'parcel_count'    => $data['parcel_count'] ?? 1,
            ])->save();

            // Stock leaves here, through the ledger's own front door.
            if ($list->voucher_id) {
                $this->syncVoucherToPack($list, $tenantId);
                $this->vouchers->post($list->voucher_id, $tenantId, $actorId);
            }

            // The units are gone, so the promise on them is finished rather
            // than merely released — released would put them back on the shelf.
            foreach ($list->lines()->whereNotNull('reservation_id')->get() as $line) {
                try {
                    $this->trace->closeReservation((int) $line->reservation_id, $tenantId, $actorId, 'fulfilled');
                } catch (\Throwable $e) {
                    // Already closed by hand — not a reason to fail the dispatch.
                }
            }
        });

        $fresh = $this->show($id, $tenantId);
        $this->notifier->parcelShipped($fresh, $actorId, $booking['mode'] === 'manual');

        return $fresh;
    }

    /** It arrived. POD is attached separately, against the delivery document. */
    public function markDelivered(int $id, array $data, int $tenantId, int $actorId): PickList
    {
        $list = $this->find($id, $tenantId);

        if ($list->status !== 'shipped') {
            throw new BusinessException('Only a shipped parcel can be marked as delivered.', 422);
        }

        $list->forceFill([
            'status'       => 'delivered',
            'delivered_at' => $data['delivered_at'] ?? now(),
            'received_by'  => $data['received_by'] ?? null,
        ])->save();

        $fresh = $this->show($id, $tenantId);
        $this->notifier->parcelDelivered($fresh, $actorId);

        return $fresh;
    }

    /** Abandon the job and put the promised stock back. */
    public function cancel(int $id, int $tenantId, int $actorId): PickList
    {
        $list = $this->find($id, $tenantId);

        if ($list->isClosed()) {
            throw new BusinessException('This parcel has already '.$list->status.' — it cannot be cancelled.', 422);
        }

        DB::transaction(function () use ($list, $tenantId, $actorId) {
            foreach ($list->lines()->whereNotNull('reservation_id')->get() as $line) {
                try {
                    // Released, not fulfilled: nothing left, so the stock goes
                    // back to being available for somebody else.
                    $this->trace->closeReservation((int) $line->reservation_id, $tenantId, $actorId, 'released');
                } catch (\Throwable $e) {
                }
            }
            $list->forceFill(['status' => 'cancelled'])->save();
        });

        return $this->show($id, $tenantId);
    }

    /* ── Internals ──────────────────────────────────────────────── */

    /**
     * A short pick has to reach the customer's paperwork, or they are invoiced
     * for units that were never sent.
     */
    private function syncVoucherToPack(PickList $list, int $tenantId): void
    {
        $voucher = Voucher::forTenant($tenantId)->with('items')->find($list->voucher_id);
        if (! $voucher) {
            return;
        }

        $packed = $list->lines()->get()->keyBy('product_id');

        foreach ($voucher->items as $item) {
            $line = $packed->get($item->product_id);
            if (! $line) {
                continue;
            }

            $qty = (float) $line->packed_qty;
            if ($qty > 0 && abs($qty - (float) $item->quantity) > 0.0001) {
                $item->forceFill([
                    'quantity' => $qty,
                    'amount'   => round($qty * (float) $item->unit_price, 2),
                ])->save();
            }
        }
    }

    /** The bin holding the most of this item — fewest walks for the picker. */
    private function suggestBin(int $productId, int $warehouseId, int $tenantId): ?int
    {
        return Stock::forTenant($tenantId)
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->whereNotNull('location_id')
            ->where('quantity', '>', 0)
            ->orderByDesc('quantity')
            ->value('location_id');
    }

    /** FEFO's choice, so the batch closest to expiry leaves first. */
    private function suggestBatch(int $productId, float $qty, int $warehouseId, int $tenantId): ?int
    {
        if (! Product::forTenant($tenantId)->whereKey($productId)->value('track_batch')) {
            return null;
        }

        $plan = $this->trace->fefoPlan($productId, $qty, $tenantId, $warehouseId);

        return $plan['plan'][0]['batch_id'] ?? null;
    }

    /**
     * Promise the stock the moment a picker is sent for it. Best-effort: a
     * warehouse that is already short should still be able to raise the pick
     * list and discover the shortfall physically, which is what happens anyway.
     */
    private function reserveFor(int $productId, float $qty, int $warehouseId, Voucher $voucher, int $tenantId, int $userId): ?int
    {
        try {
            return $this->trace->reserve([
                'product_id'      => $productId,
                'warehouse_id'    => $warehouseId,
                'quantity'        => $qty,
                'reserved_for'    => 'customer',
                'reference_id'    => $voucher->id,
                'reference_label' => $voucher->customer_name ?: $voucher->code,
                'priority'        => 3,
            ], $tenantId, $userId)->id;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function assertOpenForWork(PickList $list): void
    {
        if ($list->isClosed()) {
            throw new BusinessException('This parcel is '.$list->status.' — it can no longer be changed.', 422);
        }
    }

    private function find(int $id, int $tenantId): PickList
    {
        return PickList::forTenant($tenantId)->find($id)
            ?? throw new BusinessException('That pick list does not exist.', 404);
    }

    private function nextCode(int $tenantId): string
    {
        $n = PickList::withTrashed()->where('tenant_id', $tenantId)->count() + 1;

        do {
            $code = 'PICK-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while (PickList::withTrashed()->where('tenant_id', $tenantId)->where('code', $code)->exists());

        return $code;
    }
}
