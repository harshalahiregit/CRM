<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\Rental;
use Carbon\Carbon;

/**
 * Rental register. A rental is a tracking record, not a ledger entry — checking
 * something out here does not by itself move sellable stock (the storekeeper
 * posts the physical issue on the stock screens if the item is inventoried). This
 * keeps rentals from double-counting against the on-hand figure. What it owns is
 * the who/when/how-much and the overdue picture.
 */
class RentalService
{
    public function list(int $tenantId, array $f = [])
    {
        $q = Rental::forTenant($tenantId)
            ->with('product:id,sku,name', 'asset:id,name', 'warehouse:id,name');

        if (! empty($f['status'])) {
            if ($f['status'] === 'overdue') {
                $q->where('status', 'out')->whereNotNull('due_date')->whereDate('due_date', '<', now());
            } else {
                $q->where('status', $f['status']);
            }
        }
        if (! empty($f['search'])) {
            $s = '%'.$f['search'].'%';
            $q->where(fn ($w) => $w->where('customer_name', 'like', $s)->orWhere('code', 'like', $s)->orWhere('item_label', 'like', $s));
        }

        return $q->orderByDesc('id')->get();
    }

    public function show(int $id, int $tenantId): Rental
    {
        return Rental::forTenant($tenantId)->with('product:id,sku,name', 'asset:id,name', 'warehouse:id,name', 'creator:id,name')->findOrFail($id);
    }

    public function create(array $d, int $tenantId, int $userId): Rental
    {
        if (trim($d['customer_name'] ?? '') === '') {
            throw new BusinessException('A rental needs a customer name.', 422);
        }

        $rental = Rental::create(array_merge($d, [
            'tenant_id' => $tenantId,
            'created_by' => $userId,
            'status' => $d['status'] ?? 'reserved',
        ]));
        $rental->code = 'RENT-'.str_pad((string) $rental->id, 6, '0', STR_PAD_LEFT);
        $rental->save();

        return $rental->fresh(['product', 'asset', 'warehouse']);
    }

    public function update(int $id, array $d, int $tenantId): Rental
    {
        $rental = Rental::forTenant($tenantId)->findOrFail($id);
        unset($d['tenant_id'], $d['created_by'], $d['status']);
        $rental->update($d);

        return $rental->fresh(['product', 'asset', 'warehouse']);
    }

    /** Hand it over. Stamps out/due dates and (for a linked asset) marks it idle→out. */
    public function checkout(int $id, array $d, int $tenantId): Rental
    {
        $rental = Rental::forTenant($tenantId)->findOrFail($id);
        if (! in_array($rental->status, ['reserved'], true)) {
            throw new BusinessException('Only a reserved rental can be checked out.', 422);
        }

        $rental->update([
            'status'   => 'out',
            'out_date' => $d['out_date'] ?? now()->toDateString(),
            'due_date' => $d['due_date'] ?? $rental->due_date,
        ]);

        if ($rental->asset_id) {
            $rental->asset?->update(['status' => 'in_service']);
        }

        return $rental->fresh(['product', 'asset', 'warehouse']);
    }

    /** Take it back. Computes the charge from the period elapsed unless one is given. */
    public function returnItem(int $id, array $d, int $tenantId): Rental
    {
        $rental = Rental::forTenant($tenantId)->findOrFail($id);
        if (! in_array($rental->status, ['out', 'overdue'], true)) {
            throw new BusinessException('Only an item that is out can be returned.', 422);
        }

        $returned = Carbon::parse($d['returned_date'] ?? now()->toDateString());
        $charge = $d['charged'] ?? $this->estimateCharge($rental, $returned);

        $rental->update([
            'status'        => 'returned',
            'returned_date' => $returned->toDateString(),
            'charged'       => $charge,
        ]);

        return $rental->fresh(['product', 'asset', 'warehouse']);
    }

    public function cancel(int $id, int $tenantId): Rental
    {
        $rental = Rental::forTenant($tenantId)->findOrFail($id);
        if (in_array($rental->status, ['returned', 'cancelled'], true)) {
            throw new BusinessException('This rental is already closed.', 422);
        }
        $rental->update(['status' => 'cancelled']);

        return $rental->fresh();
    }

    public function delete(int $id, int $tenantId): void
    {
        Rental::forTenant($tenantId)->findOrFail($id)->delete();
    }

    /** rate × (periods elapsed, rounded up) × qty. Never charges for zero periods. */
    private function estimateCharge(Rental $rental, Carbon $returned): float
    {
        $from = $rental->out_date ?: $returned;
        $days = max(1, $from->diffInDays($returned) ?: 1);
        $periods = match ($rental->rate_period) {
            'week'  => (int) ceil($days / 7),
            'month' => (int) ceil($days / 30),
            default => $days,
        };

        return round((float) $rental->rate * max(1, $periods) * (float) $rental->qty, 2);
    }
}
