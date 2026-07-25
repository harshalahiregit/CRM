<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\Asset;
use App\Models\Inventory\AssetEvent;
use Illuminate\Support\Facades\DB;

/** Company asset register + maintenance history. */
class AssetService
{
    public function list(int $tenantId, array $f = [])
    {
        $q = Asset::forTenant($tenantId)
            ->with('assignee:id,name', 'warehouse:id,name')
            ->withCount('events');

        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }
        if (! empty($f['category'])) {
            $q->where('category', $f['category']);
        }
        if (! empty($f['assigned_to'])) {
            $q->where('assigned_to', (int) $f['assigned_to']);
        }
        if (! empty($f['search'])) {
            $s = '%'.$f['search'].'%';
            $q->where(fn ($w) => $w->where('name', 'like', $s)->orWhere('code', 'like', $s)->orWhere('serial_no', 'like', $s));
        }
        // Assets due (or overdue) for service.
        if (! empty($f['due'])) {
            $q->whereNotNull('next_service_due')->whereDate('next_service_due', '<=', now()->addDays(14));
        }

        return $q->orderBy('name')->get();
    }

    public function show(int $id, int $tenantId): Asset
    {
        return Asset::forTenant($tenantId)
            ->with('assignee:id,name', 'warehouse:id,name', 'product:id,sku,name',
                'events.performer:id,name')
            ->findOrFail($id);
    }

    public function create(array $d, int $tenantId, int $userId): Asset
    {
        $d['name'] = trim($d['name'] ?? '');
        if ($d['name'] === '') {
            throw new BusinessException('An asset needs a name.', 422);
        }

        return Asset::create(array_merge($d, ['tenant_id' => $tenantId, 'created_by' => $userId]));
    }

    public function update(int $id, array $d, int $tenantId): Asset
    {
        $asset = Asset::forTenant($tenantId)->findOrFail($id);
        unset($d['tenant_id'], $d['created_by']);
        $asset->update($d);

        return $asset->fresh(['assignee', 'warehouse']);
    }

    public function delete(int $id, int $tenantId): void
    {
        Asset::forTenant($tenantId)->findOrFail($id)->delete();
    }

    /** Assign (or hand back) an asset, logging the change to its history. */
    public function assign(int $id, ?int $toUserId, int $tenantId, int $actorId): Asset
    {
        return DB::transaction(function () use ($id, $toUserId, $tenantId, $actorId) {
            $asset = Asset::forTenant($tenantId)->findOrFail($id);
            $asset->update(['assigned_to' => $toUserId]);

            $this->addEvent($id, [
                'type'        => $toUserId ? 'assigned' : 'returned',
                'description' => $toUserId ? 'Assigned to a user.' : 'Returned / unassigned.',
            ], $tenantId, $actorId);

            return $asset->fresh('assignee');
        });
    }

    public function setStatus(int $id, string $status, int $tenantId): Asset
    {
        if (! in_array($status, Asset::STATUSES, true)) {
            throw new BusinessException('Unknown asset status.', 422);
        }
        $asset = Asset::forTenant($tenantId)->findOrFail($id);
        $asset->update(['status' => $status]);

        return $asset->fresh();
    }

    /** Log a maintenance/other event; a service event rolls the next-due date. */
    public function addEvent(int $assetId, array $d, int $tenantId, int $userId): AssetEvent
    {
        $type = $d['type'] ?? 'note';
        if (! in_array($type, AssetEvent::TYPES, true)) {
            throw new BusinessException('Unknown event type.', 422);
        }

        $asset = Asset::forTenant($tenantId)->findOrFail($assetId);

        $event = AssetEvent::create([
            'tenant_id'    => $tenantId,
            'asset_id'     => $assetId,
            'type'         => $type,
            'description'  => $d['description'] ?? null,
            'cost'         => $d['cost'] ?? null,
            'vendor'       => $d['vendor'] ?? null,
            'next_due'     => $d['next_due'] ?? null,
            'performed_at' => $d['performed_at'] ?? now(),
            'performed_by' => $userId,
        ]);

        // A service/inspection with a next-due date advances the asset's schedule,
        // and a service brings a maintenance asset back into service.
        $patch = [];
        if (! empty($d['next_due'])) {
            $patch['next_service_due'] = $d['next_due'];
        }
        if ($type === 'service' && $asset->status === 'maintenance') {
            $patch['status'] = 'in_service';
        }
        if ($patch) {
            $asset->update($patch);
        }

        return $event->fresh('performer');
    }
}
