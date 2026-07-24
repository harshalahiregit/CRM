<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\Location;
use App\Models\Inventory\Stock;
use App\Models\Inventory\Warehouse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WarehouseService
{
    /* ── Warehouses ─────────────────────────────────────────────── */

    public function list(int $tenantId): Collection
    {
        $rows = Warehouse::forTenant($tenantId)->with('manager:id,name')->orderBy('name')->get();

        // Utilisation figures the dashboard/list show per site.
        return $rows->each(function (Warehouse $w) use ($tenantId) {
            $agg = Stock::forTenant($tenantId)->where('warehouse_id', $w->id)
                ->selectRaw('COALESCE(SUM(quantity),0) as qty, COUNT(DISTINCT product_id) as skus')->first();

            $w->setAttribute('total_quantity', (float) ($agg->qty ?? 0));
            $w->setAttribute('sku_count', (int) ($agg->skus ?? 0));
            $w->setAttribute('location_count', Location::forTenant($tenantId)->where('warehouse_id', $w->id)->count());
        });
    }

    public function show(int $id, int $tenantId): Warehouse
    {
        $w = $this->find($id, $tenantId);
        $w->load('manager:id,name');
        $w->setAttribute('locations', $this->locationTree($id, $tenantId));

        return $w;
    }

    public function create(array $data, int $tenantId): Warehouse
    {
        return DB::transaction(function () use ($data, $tenantId) {
            $w = Warehouse::create([...$data, 'tenant_id' => $tenantId]);
            // First site created is the default; later ones only if asked.
            if (! empty($data['is_default']) || Warehouse::forTenant($tenantId)->count() === 1) {
                $this->makeDefault($w, $tenantId);
            }

            return $w;
        });
    }

    public function update(int $id, array $data, int $tenantId): Warehouse
    {
        $w = $this->find($id, $tenantId);

        return DB::transaction(function () use ($w, $data, $tenantId) {
            $w->fill($data)->save();
            if (! empty($data['is_default'])) {
                $this->makeDefault($w, $tenantId);
            }

            return $w->fresh('manager');
        });
    }

    public function delete(int $id, int $tenantId): void
    {
        $w = $this->find($id, $tenantId);

        $onHand = (float) Stock::forTenant($tenantId)->where('warehouse_id', $id)->sum('quantity');
        if ($onHand > 0) {
            throw new BusinessException('This warehouse still holds stock. Transfer or write it off first.', 422);
        }

        DB::transaction(function () use ($w, $id, $tenantId) {
            Location::forTenant($tenantId)->where('warehouse_id', $id)->delete();
            Stock::forTenant($tenantId)->where('warehouse_id', $id)->delete();   // empty rows only
            $w->delete();
        });
    }

    /* ── Bin locations ──────────────────────────────────────────── */

    /** Flat rows plus a `path` (Zone A › Rack 2 › Bin 5) for pickers. */
    public function locationTree(int $warehouseId, int $tenantId): Collection
    {
        $all = Location::forTenant($tenantId)->where('warehouse_id', $warehouseId)
            ->orderBy('type')->orderBy('name')->get();

        $byId = $all->keyBy('id');

        return $all->each(function (Location $l) use ($byId) {
            $parts = [$l->name];
            $cursor = $l;
            $guard = 0;
            while ($cursor->parent_id && $guard++ < 10) {
                $cursor = $byId->get($cursor->parent_id);
                if (! $cursor) {
                    break;
                }
                array_unshift($parts, $cursor->name);
            }
            $l->setAttribute('path', implode(' › ', $parts));
        });
    }

    public function createLocation(int $warehouseId, array $data, int $tenantId): Location
    {
        $this->find($warehouseId, $tenantId);

        if (! empty($data['parent_id'])) {
            $parent = Location::forTenant($tenantId)->find($data['parent_id']);
            if (! $parent || (int) $parent->warehouse_id !== $warehouseId) {
                throw new BusinessException('The parent location belongs to a different warehouse.', 422);
            }
        }

        // Every location gets a scannable code. Without one the printed shelf
        // label has nothing a scanner can resolve, and "scan the bin you're
        // standing at" — which the whole picking and counting flow rests on —
        // silently does nothing.
        $data['code'] = ! empty($data['code'])
            ? $this->uniqueLocationCode(strtoupper(trim($data['code'])), $tenantId)
            : $this->generateLocationCode($warehouseId, $data['name'] ?? 'LOC', $tenantId);

        // fresh() so the response carries every column — capacity and
        // capacity_uom included — even when the caller left them unset. Without
        // it the create response is missing exactly the fields the layout screen
        // reads back, and null looks like "key absent" rather than "not measured".
        return Location::create([...$data, 'warehouse_id' => $warehouseId, 'tenant_id' => $tenantId])->fresh();
    }

    /**
     * A code a human can read off a shelf and a scanner can resolve:
     * warehouse code, then the location name compacted — "MAIN-RACKA".
     */
    private function generateLocationCode(int $warehouseId, string $name, int $tenantId): string
    {
        $wh = Location::query()->getConnection()->table('inventory_warehouses')
            ->where('id', $warehouseId)->first(['code', 'id']);

        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($wh->code ?? '')) ?: 'WH'.$warehouseId);
        $slug = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name)) ?: 'LOC';

        return $this->uniqueLocationCode(substr($prefix, 0, 8).'-'.substr($slug, 0, 10), $tenantId);
    }

    /** Append -2, -3 … until it's free. Codes are what scanners match on. */
    private function uniqueLocationCode(string $base, int $tenantId): string
    {
        $code = $base;
        $i = 2;
        while (Location::forTenant($tenantId)->where('code', $code)->exists()) {
            $code = $base.'-'.$i;
            $i++;
        }

        return $code;
    }

    public function deleteLocation(int $id, int $tenantId): void
    {
        $l = Location::forTenant($tenantId)->findOrFail($id);

        if (Location::forTenant($tenantId)->where('parent_id', $id)->exists()) {
            throw new BusinessException('This location has sub-locations. Remove them first.', 422);
        }
        $onHand = (float) Stock::forTenant($tenantId)->where('location_id', $id)->sum('quantity');
        if ($onHand > 0) {
            throw new BusinessException('This location still holds stock. Move it first.', 422);
        }

        $l->delete();
    }

    /* ── Helpers ────────────────────────────────────────────────── */

    public function find(int $id, int $tenantId): Warehouse
    {
        return Warehouse::forTenant($tenantId)->find($id)
            ?? throw new BusinessException('That warehouse does not exist.', 404);
    }

    /** Exactly one default per tenant. */
    private function makeDefault(Warehouse $w, int $tenantId): void
    {
        Warehouse::forTenant($tenantId)->whereKeyNot($w->id)->update(['is_default' => false]);
        $w->forceFill(['is_default' => true])->save();
    }
}
