<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\Batch;
use App\Models\Inventory\Product;
use App\Models\Inventory\Reservation;
use App\Models\Inventory\Serial;
use App\Models\Inventory\Stock;
use App\Models\Inventory\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Batches, serial numbers and reservations — the traceability layer over the
 * stock ledger.
 *
 * The ledger answers "how much moved and why". This answers "which physical
 * units, from which batch, expiring when, and how much of what's on the shelf
 * is already promised to someone".
 */
class TraceabilityService
{
    /* ══ Batches ══════════════════════════════════════════════════ */

    public function batches(int $tenantId, array $f = [])
    {
        $q = Batch::forTenant($tenantId)->with(['product:id,sku,name,base_unit', 'warehouse:id,name']);

        foreach (['product_id', 'warehouse_id', 'quality_status'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }

        if (! empty($f['search'])) {
            $s = '%'.$f['search'].'%';
            $q->where(fn ($w) => $w->where('batch_no', 'like', $s)
                ->orWhere('lot_number', 'like', $s)
                ->orWhere('vendor_batch_no', 'like', $s));
        }

        // Only batches with stock left, unless explicitly asked for the history.
        if (empty($f['include_empty'])) {
            $q->where('remaining_qty', '>', 0);
        }

        if (! empty($f['expiring_days'])) {
            $q->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', now()->addDays((int) $f['expiring_days'])->toDateString());
        }
        if (! empty($f['expired'])) {
            $q->whereNotNull('expiry_date')->whereDate('expiry_date', '<', now()->toDateString());
        }

        // Soonest expiry first — the order anyone managing shelf life wants.
        return $q->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')->orderByDesc('id')->get();
    }

    public function createBatch(array $d, int $tenantId, int $userId): Batch
    {
        $this->assertProduct((int) $d['product_id'], $tenantId);

        if (! empty($d['warehouse_id'])) {
            $this->assertWarehouse((int) $d['warehouse_id'], $tenantId);
        }

        if (Batch::forTenant($tenantId)->where('product_id', $d['product_id'])->where('batch_no', $d['batch_no'])->exists()) {
            throw new BusinessException("Batch {$d['batch_no']} already exists for this item.", 422);
        }

        $qty = round((float) ($d['received_qty'] ?? 0), 3);

        return Batch::create([
            'tenant_id'       => $tenantId,
            'product_id'      => $d['product_id'],
            'warehouse_id'    => $d['warehouse_id'] ?? null,
            'batch_no'        => $d['batch_no'],
            'lot_number'      => $d['lot_number'] ?? null,
            'vendor_batch_no' => $d['vendor_batch_no'] ?? null,
            'manufactured_at' => $d['manufactured_at'] ?? null,
            'expiry_date'     => $d['expiry_date'] ?? null,
            'received_qty'    => $qty,
            // A new batch starts with everything it received still on the shelf.
            'remaining_qty'   => $d['remaining_qty'] ?? $qty,
            'cost_price'      => $d['cost_price'] ?? null,
            'quality_status'  => $d['quality_status'] ?? 'passed',
            'note'            => $d['note'] ?? null,
            'created_by'      => $userId,
        ]);
    }

    public function updateBatch(int $id, array $d, int $tenantId): Batch
    {
        $batch = Batch::forTenant($tenantId)->findOrFail($id);

        // batch_no identifies the physical lot — renaming it would detach the
        // history already recorded against it.
        unset($d['batch_no'], $d['product_id'], $d['tenant_id']);
        $batch->update($d);

        return $batch->fresh(['product', 'warehouse']);
    }

    public function deleteBatch(int $id, int $tenantId): void
    {
        $batch = Batch::forTenant($tenantId)->findOrFail($id);

        if ((float) $batch->remaining_qty > 0) {
            throw new BusinessException('That batch still holds stock. Consume or write it off first.', 422);
        }

        $batch->delete();
    }

    /**
     * FEFO pick list — First Expired, First Out. Given a quantity, returns which
     * batches to draw from and how much from each, soonest-expiring first.
     * Batches that aren't quality-passed are skipped: held stock is not sellable
     * stock, and silently shipping it would defeat the hold.
     */
    public function fefoPlan(int $productId, float $quantity, int $tenantId, ?int $warehouseId = null): array
    {
        $q = Batch::forTenant($tenantId)
            ->where('product_id', $productId)
            ->where('remaining_qty', '>', 0)
            ->where('quality_status', 'passed');

        if ($warehouseId) {
            $q->where('warehouse_id', $warehouseId);
        }

        $batches = $q->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')->orderBy('id')->get();

        $need = round($quantity, 3);
        $plan = [];

        foreach ($batches as $b) {
            if ($need <= 0) {
                break;
            }
            $take = min($need, (float) $b->remaining_qty);
            $plan[] = [
                'batch_id'    => $b->id,
                'batch_no'    => $b->batch_no,
                'expiry_date' => $b->expiry_date?->toDateString(),
                'take'        => round($take, 3),
                'remaining'   => round((float) $b->remaining_qty - $take, 3),
                'expired'     => $b->is_expired,
            ];
            $need = round($need - $take, 3);
        }

        return [
            'plan'      => $plan,
            'satisfied' => $need <= 0,
            'short_by'  => max(0, $need),
        ];
    }

    /** Draw quantity off batches per a FEFO plan (called when stock actually leaves). */
    public function consumeFefo(int $productId, float $quantity, int $tenantId, ?int $warehouseId = null): array
    {
        $result = $this->fefoPlan($productId, $quantity, $tenantId, $warehouseId);

        DB::transaction(function () use ($result) {
            foreach ($result['plan'] as $line) {
                Batch::whereKey($line['batch_id'])->update(['remaining_qty' => $line['remaining']]);
            }
        });

        return $result;
    }

    /* ══ Serial numbers ═══════════════════════════════════════════ */

    public function serials(int $tenantId, array $f = [])
    {
        $q = Serial::forTenant($tenantId)->with(['product:id,sku,name', 'warehouse:id,name', 'batch:id,batch_no']);

        foreach (['product_id', 'warehouse_id', 'status', 'batch_id'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }

        if (! empty($f['search'])) {
            $q->where(fn ($w) => $w->where('serial_no', 'like', '%'.$f['search'].'%')
                ->orWhere('customer_ref', 'like', '%'.$f['search'].'%'));
        }

        return $q->orderByDesc('id')->limit(500)->get();
    }

    public function createSerials(array $d, int $tenantId, int $userId): array
    {
        $this->assertProduct((int) $d['product_id'], $tenantId);

        // Accept one serial or a whole intake at once — receiving 50 laptops
        // shouldn't be 50 requests.
        $numbers = collect($d['serial_no'] ?? $d['serial_numbers'] ?? [])
            ->flatMap(fn ($s) => preg_split('/[\s,]+/', (string) $s))
            ->map(fn ($s) => trim($s))->filter()->unique()->values();

        if ($numbers->isEmpty()) {
            throw new BusinessException('Give at least one serial number.', 422);
        }

        $existing = Serial::forTenant($tenantId)->whereIn('serial_no', $numbers)->pluck('serial_no')->all();
        $created = [];
        $skipped = [];

        foreach ($numbers as $sn) {
            if (in_array($sn, $existing, true)) {
                $skipped[] = $sn;
                continue;
            }

            $created[] = Serial::create([
                'tenant_id'      => $tenantId,
                'product_id'     => $d['product_id'],
                'batch_id'       => $d['batch_id'] ?? null,
                'warehouse_id'   => $d['warehouse_id'] ?? null,
                'serial_no'      => $sn,
                'status'         => $d['status'] ?? 'in_stock',
                'warranty_until' => $d['warranty_until'] ?? null,
                'customer_ref'   => $d['customer_ref'] ?? null,
                'note'           => $d['note'] ?? null,
                'created_by'     => $userId,
            ]);
        }

        return ['created' => count($created), 'skipped' => $skipped, 'serials' => $created];
    }

    public function updateSerial(int $id, array $d, int $tenantId): Serial
    {
        $serial = Serial::forTenant($tenantId)->findOrFail($id);

        if (! empty($d['status']) && ! in_array($d['status'], Serial::STATUSES, true)) {
            throw new BusinessException('Unknown serial status.', 422);
        }

        // The number itself is the identity — everything else is mutable.
        unset($d['serial_no'], $d['product_id'], $d['tenant_id']);
        $serial->update($d);

        return $serial->fresh(['product', 'warehouse', 'batch']);
    }

    public function deleteSerial(int $id, int $tenantId): void
    {
        Serial::forTenant($tenantId)->findOrFail($id)->delete();
    }

    /* ══ Reservations ═════════════════════════════════════════════ */

    public function reservations(int $tenantId, array $f = [])
    {
        $q = Reservation::forTenant($tenantId)
            ->with(['product:id,sku,name,base_unit', 'warehouse:id,name', 'creator:id,name']);

        foreach (['product_id', 'warehouse_id', 'status', 'reserved_for'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }
        if (! empty($f['created_by'])) {
            $q->where('created_by', $f['created_by']);
        }

        return $q->orderBy('priority')->orderByDesc('id')->get();
    }

    /**
     * Reserve stock. Refuses to promise more than is actually free — the whole
     * point of a reservation is that it can be honoured, so over-committing is
     * a validation error rather than a silent negative number.
     */
    public function reserve(array $d, int $tenantId, int $userId): Reservation
    {
        $productId = (int) $d['product_id'];
        $warehouseId = (int) $d['warehouse_id'];
        $qty = round((float) $d['quantity'], 3);

        $this->assertProduct($productId, $tenantId);
        $this->assertWarehouse($warehouseId, $tenantId);

        if ($qty <= 0) {
            throw new BusinessException('Reserve a quantity greater than zero.', 422);
        }

        return DB::transaction(function () use ($d, $productId, $warehouseId, $qty, $tenantId, $userId) {
            $row = Stock::forTenant($tenantId)
                ->where('product_id', $productId)->where('warehouse_id', $warehouseId)
                ->lockForUpdate()->first();

            $onHand = (float) ($row->quantity ?? 0);
            $already = (float) ($row->reserved_quantity ?? 0);
            $free = $onHand - $already;

            if ($qty > $free) {
                throw new BusinessException(
                    'Only '.rtrim(rtrim(number_format($free, 3, '.', ''), '0'), '.').' available to reserve at that warehouse.',
                    422
                );
            }

            $reservation = Reservation::create([
                'tenant_id'       => $tenantId,
                'product_id'      => $productId,
                'warehouse_id'    => $warehouseId,
                'quantity'        => $qty,
                'reserved_for'    => $d['reserved_for'] ?? 'customer',
                'reference_id'    => $d['reference_id'] ?? null,
                'reference_label' => $d['reference_label'] ?? null,
                'priority'        => $d['priority'] ?? 5,
                'status'          => 'active',
                'expires_at'      => $d['expires_at'] ?? null,
                'note'            => $d['note'] ?? null,
                'created_by'      => $userId,
            ]);

            $this->syncReserved($productId, $warehouseId, $tenantId);

            return $reservation->load(['product', 'warehouse', 'creator']);
        });
    }

    /** Release (or fulfil) a reservation and give the stock back to "available". */
    public function closeReservation(int $id, int $tenantId, int $userId, string $as = 'released'): Reservation
    {
        if (! in_array($as, ['released', 'fulfilled'], true)) {
            throw new BusinessException('A reservation can only be released or fulfilled.', 422);
        }

        $r = Reservation::forTenant($tenantId)->findOrFail($id);

        if ($r->status !== 'active') {
            throw new BusinessException('That reservation is already closed.', 422);
        }

        $r->update(['status' => $as, 'released_by' => $userId, 'released_at' => now()]);
        $this->syncReserved($r->product_id, $r->warehouse_id, $tenantId);

        return $r->fresh(['product', 'warehouse', 'creator']);
    }

    /**
     * Recompute a balance's reserved_quantity from the active reservations that
     * back it. Derived rather than incremented, so the aggregate can't drift out
     * of step with the rows that explain it.
     */
    private function syncReserved(int $productId, int $warehouseId, int $tenantId): void
    {
        $total = (float) Reservation::forTenant($tenantId)
            ->where('product_id', $productId)->where('warehouse_id', $warehouseId)
            ->where('status', 'active')->sum('quantity');

        Stock::forTenant($tenantId)
            ->where('product_id', $productId)->where('warehouse_id', $warehouseId)
            ->update(['reserved_quantity' => round($total, 3)]);
    }

    /* ══ Expiry dashboard ═════════════════════════════════════════ */

    /**
     * Shelf-life overview: what's already expired, what's about to, and what
     * it's worth. Windows are cumulative buckets an operator can act on.
     */
    public function expiryOverview(int $tenantId, ?int $days = null): array
    {
        $days = $days ?: (int) (app(ConfigService::class)->get($tenantId, 'expiry_alert_days') ?? 30);

        $live = Batch::forTenant($tenantId)
            ->where('remaining_qty', '>', 0)
            ->whereNotNull('expiry_date')
            ->with(['product:id,sku,name,base_unit,cost_price', 'warehouse:id,name'])
            ->orderBy('expiry_date')
            ->get();

        $today = now()->startOfDay();
        $value = fn ($b) => (float) $b->remaining_qty * (float) ($b->cost_price ?? $b->product->cost_price ?? 0);

        $expired = $live->filter(fn ($b) => $b->expiry_date->startOfDay()->lt($today));
        $soon = $live->filter(fn ($b) => $b->expiry_date->startOfDay()->gte($today)
            && $b->expiry_date->startOfDay()->lte($today->copy()->addDays($days)));

        return [
            'window_days' => $days,
            'expired'     => ['count' => $expired->count(), 'value' => round($expired->sum($value), 2), 'batches' => $expired->values()],
            'expiring'    => ['count' => $soon->count(), 'value' => round($soon->sum($value), 2), 'batches' => $soon->values()],
            'total_dated' => $live->count(),
        ];
    }

    /* ══ Guards ═══════════════════════════════════════════════════ */

    private function assertProduct(int $id, int $tenantId): void
    {
        if (! Product::forTenant($tenantId)->whereKey($id)->exists()) {
            throw new BusinessException('That item does not exist.', 404);
        }
    }

    private function assertWarehouse(int $id, int $tenantId): void
    {
        if (! Warehouse::forTenant($tenantId)->whereKey($id)->exists()) {
            throw new BusinessException('That warehouse does not exist.', 404);
        }
    }
}
