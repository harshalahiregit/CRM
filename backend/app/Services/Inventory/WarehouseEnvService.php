<?php

namespace App\Services\Inventory;

use App\Models\Inventory\Warehouse;
use App\Models\Inventory\WarehouseEnvReading;

/**
 * Environment monitoring for temperature/humidity-sensitive sites. A reading is
 * checked against the warehouse's configured band the moment it's logged; an
 * out-of-band reading is a real event (a cold chain broken is spoilage waiting to
 * happen), so it fires the same best-effort alert the rest of the module uses.
 */
class WarehouseEnvService
{
    public function __construct(private InventoryNotifier $notifier)
    {
    }

    /** Recent readings for one site, newest first. */
    public function readings(int $warehouseId, int $tenantId, int $limit = 50)
    {
        return WarehouseEnvReading::forTenant($tenantId)
            ->where('warehouse_id', $warehouseId)
            ->with('recorder:id,name')
            ->orderByDesc('recorded_at')->orderByDesc('id')
            ->limit($limit)->get();
    }

    /** The latest reading per warehouse — for a badge on the warehouse list. */
    public function latestFor(array $warehouseIds, int $tenantId): array
    {
        $out = [];
        foreach ($warehouseIds as $id) {
            $r = WarehouseEnvReading::forTenant($tenantId)
                ->where('warehouse_id', $id)
                ->orderByDesc('recorded_at')->orderByDesc('id')->first();
            if ($r) {
                $out[$id] = $r;
            }
        }

        return $out;
    }

    public function record(int $warehouseId, array $d, int $tenantId, int $userId): WarehouseEnvReading
    {
        $warehouse = Warehouse::forTenant($tenantId)->findOrFail($warehouseId);

        $temp = isset($d['temperature']) && $d['temperature'] !== '' ? (float) $d['temperature'] : null;
        $humidity = isset($d['humidity']) && $d['humidity'] !== '' ? (float) $d['humidity'] : null;

        $inBand = $warehouse->readingInBand($temp, $humidity);

        $reading = WarehouseEnvReading::create([
            'tenant_id'   => $tenantId,
            'warehouse_id' => $warehouseId,
            'temperature' => $temp,
            'humidity'    => $humidity,
            'in_band'     => $inBand,
            'note'        => $d['note'] ?? null,
            'recorded_by' => $userId,
            'recorded_at' => $d['recorded_at'] ?? now(),
        ]);

        if (! $inBand) {
            $this->notifier->environmentBreach($warehouse, $reading, $userId);
        }

        return $reading->fresh('recorder');
    }
}
