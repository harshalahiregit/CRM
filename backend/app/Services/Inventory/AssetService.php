<?php

namespace App\Services\Inventory;

use App\Events\Inventory\AssetAssignedToEmployee;
use App\Exceptions\BusinessException;
use App\Models\Inventory\Asset;
use App\Models\Inventory\AssetEvent;
use Illuminate\Support\Facades\DB;

/** Company asset register + maintenance history. */
class AssetService
{
    /**
     * How a raw asset status reads to a person looking at who holds what.
     * Inventory owns the status; this is only its label.
     */
    public const STATUS_LABELS = [
        'in_service'  => 'Assigned',
        'reserved'    => 'Reserved',
        'maintenance' => 'Under Maintenance',
        'damaged'     => 'Damaged',
        'lost'        => 'Lost',
        'idle'        => 'Available',
        'retired'     => 'Retired',
    ];

    public function list(int $tenantId, array $f = [])
    {
        $q = Asset::forTenant($tenantId)
            ->with('assignee:id,name', 'warehouse:id,name', 'employee:id,name,employee_code')
            ->withCount('events');

        if (! empty($f['assigned_employee_id'])) {
            $q->where('assigned_employee_id', (int) $f['assigned_employee_id']);
        }

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
            ->with('assignee:id,name', 'warehouse:id,name', 'product:id,sku,name,brand,image_path',
                'employee:id,name,employee_code', 'events.performer:id,name', 'events.employee:id,name')
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
        // The employee holder is set only through assign()/lifecycle(), which write
        // the history entry an employee's asset view is built from. Letting a plain
        // edit change it would produce a holder with no assignment record.
        unset($d['tenant_id'], $d['created_by'], $d['assigned_employee_id']);
        $asset->update($d);

        return $asset->fresh(['assignee', 'warehouse', 'employee']);
    }

    public function delete(int $id, int $tenantId): void
    {
        Asset::forTenant($tenantId)->findOrFail($id)->delete();
    }

    /**
     * Assign (or hand back) an asset, logging the change to its history.
     *
     * `$to` accepts 'user_id' and/or 'employee_id'. Passing neither hands the
     * asset back. The history entry records the employee involved — on a return
     * that is the OUTGOING holder, which is the only reason a returned asset can
     * still be traced to the person who had it.
     */
    public function assign(int $id, ?int $toUserId, int $tenantId, int $actorId, array $to = []): Asset
    {
        return DB::transaction(function () use ($id, $toUserId, $tenantId, $actorId, $to) {
            $asset = Asset::forTenant($tenantId)->findOrFail($id);

            $toEmployeeId = array_key_exists('employee_id', $to) ? $to['employee_id'] : $asset->assigned_employee_id;
            $toEmployeeId = $toEmployeeId ? (int) $toEmployeeId : null;
            $outgoing     = $asset->assigned_employee_id ? (int) $asset->assigned_employee_id : null;
            $isAssign     = $toUserId || $toEmployeeId;

            $asset->update([
                'assigned_to'          => $toUserId,
                'assigned_employee_id' => $toEmployeeId,
                // A handed-back unit is back on the shelf; an assigned one is in service.
                'status'               => $isAssign
                    ? (in_array($asset->status, ['idle', 'reserved'], true) ? 'in_service' : $asset->status)
                    : 'idle',
            ]);

            // Handing straight from one employee to another still ends the first
            // one's holding. Without this the outgoing employee has no 'returned'
            // entry and the asset vanishes from their history.
            if ($outgoing && $outgoing !== $toEmployeeId) {
                $this->addEvent($id, [
                    'type'        => 'returned',
                    'description' => $isAssign ? 'Transferred to another holder.' : 'Returned / unassigned.',
                    'employee_id' => $outgoing,
                ], $tenantId, $actorId);
            }

            if ($isAssign) {
                $this->addEvent($id, [
                    'type'        => 'assigned',
                    'description' => 'Assigned.',
                    'employee_id' => $toEmployeeId,
                ], $tenantId, $actorId);
            } elseif (! $outgoing) {
                // Unassigned from a user-only holder — still worth recording.
                $this->addEvent($id, [
                    'type'        => 'returned',
                    'description' => 'Returned / unassigned.',
                    'employee_id' => null,
                ], $tenantId, $actorId);
            }

            // Announce it; whoever cares (HR onboarding) subscribes. Inventory does
            // not reach into other modules.
            if ($toEmployeeId || $outgoing) {
                AssetAssignedToEmployee::dispatch($id, $toEmployeeId, $tenantId, $actorId);
            }

            return $asset->fresh(['assignee', 'employee']);
        });
    }

    /**
     * One asset lifecycle action — status, holder and history move together so
     * an employee's view can never drift from the register.
     *
     * transfer/replace move the unit to a new holder; maintenance/lost/damaged
     * keep the holder so the asset still shows on their profile with that state.
     */
    public function lifecycle(int $id, string $action, array $d, int $tenantId, int $actorId): Asset
    {
        $statusFor = ['maintenance' => 'maintenance', 'lost' => 'lost', 'damaged' => 'damaged'];

        if ($action === 'assign' || $action === 'transfer') {
            return $this->assign($id, $d['user_id'] ?? null, $tenantId, $actorId, [
                'employee_id' => $d['employee_id'] ?? null,
            ]);
        }

        if ($action === 'return') {
            return $this->assign($id, null, $tenantId, $actorId, ['employee_id' => null]);
        }

        if ($action === 'replace') {
            // The old unit goes back and is retired; the replacement is assigned
            // separately. Read the holder BEFORE the return clears it.
            $outgoing = Asset::forTenant($tenantId)->findOrFail($id)->assigned_employee_id;
            $this->assign($id, null, $tenantId, $actorId, ['employee_id' => null]);
            $this->update($id, ['status' => 'retired'], $tenantId);
            $this->addEvent($id, [
                'type'        => 'replaced',
                'description' => $d['description'] ?? 'Replaced.',
                'employee_id' => $outgoing,
            ], $tenantId, $actorId);

            return Asset::forTenant($tenantId)->with(['assignee', 'employee'])->findOrFail($id);
        }

        if (! isset($statusFor[$action])) {
            throw new BusinessException('Unknown asset action.', 422);
        }

        return DB::transaction(function () use ($id, $action, $d, $tenantId, $actorId, $statusFor) {
            $asset = Asset::forTenant($tenantId)->findOrFail($id);
            $asset->update(array_filter([
                'status'    => $statusFor[$action],
                'condition' => $d['condition'] ?? null,
            ], fn ($v) => $v !== null));

            $this->addEvent($id, [
                'type'        => $action === 'maintenance' ? 'repair' : $action,
                'description' => $d['description'] ?? null,
                'cost'        => $d['cost'] ?? null,
                'vendor'      => $d['vendor'] ?? null,
                'next_due'    => $d['next_due'] ?? null,
                'employee_id' => $asset->assigned_employee_id,
            ], $tenantId, $actorId);

            return $asset->fresh(['assignee', 'employee']);
        });
    }

    /**
     * Every asset an employee holds or has ever held, read straight from the
     * register. HRMS stores none of this.
     */
    public function forEmployee(int $employeeId, int $tenantId)
    {
        $current = Asset::forTenant($tenantId)
            ->with('product:id,sku,name,brand,image_path', 'warehouse:id,name')
            ->where('assigned_employee_id', $employeeId)
            ->orderBy('name')
            ->get();

        // Units this employee handed back: the register no longer links them, so
        // the only trace is the 'returned' entry in the asset's own history.
        $returnedIds = AssetEvent::forTenant($tenantId)
            ->where('employee_id', $employeeId)
            ->where('type', 'returned')
            ->pluck('asset_id')
            ->unique()
            ->diff($current->pluck('id'));

        $returned = $returnedIds->isEmpty() ? collect() : Asset::forTenant($tenantId)
            ->with('product:id,sku,name,brand,image_path', 'warehouse:id,name')
            ->whereIn('id', $returnedIds)
            ->get();

        return $current->map(fn (Asset $a) => $this->employeeAssetRow($a, $employeeId, $tenantId, false))
            ->concat($returned->map(fn (Asset $a) => $this->employeeAssetRow($a, $employeeId, $tenantId, true)))
            ->values();
    }

    /** Overview counters for one employee — derived, never stored. */
    public function summaryForEmployee(int $employeeId, int $tenantId): array
    {
        $rows = $this->forEmployee($employeeId, $tenantId);

        return [
            'assigned'          => $rows->where('state', 'assigned')->count(),
            'returned'          => $rows->where('state', 'returned')->count(),
            'under_maintenance' => $rows->where('state', 'maintenance')->count(),
            'lost'              => $rows->where('state', 'lost')->count(),
            'damaged'           => $rows->where('state', 'damaged')->count(),
            'total'             => $rows->count(),
        ];
    }

    /** Shape one register row for an employee-facing list. Nothing is invented. */
    private function employeeAssetRow(Asset $a, int $employeeId, int $tenantId, bool $handedBack): array
    {
        $assignedEvent = AssetEvent::forTenant($tenantId)
            ->where('asset_id', $a->id)->where('employee_id', $employeeId)
            ->where('type', 'assigned')->with('performer:id,name')
            ->latest('performed_at')->first();

        $returnEvent = $handedBack
            ? AssetEvent::forTenant($tenantId)
                ->where('asset_id', $a->id)->where('employee_id', $employeeId)
                ->where('type', 'returned')->latest('performed_at')->first()
            : null;

        $state = $handedBack ? 'returned' : match ($a->status) {
            'maintenance' => 'maintenance',
            'lost'        => 'lost',
            'damaged'     => 'damaged',
            'reserved'    => 'reserved',
            'idle', 'retired' => 'returned',
            default       => 'assigned',
        };

        return [
            'id'              => $a->id,
            'name'            => $a->name,
            'code'            => $a->code,
            'category'        => $a->category,
            'serial_no'       => $a->serial_no,
            'brand'           => $a->product?->brand,
            'image_path'      => $a->product?->image_path,
            'product_id'      => $a->product_id,
            'sku'             => $a->product?->sku,
            'location'        => $a->location ?: $a->warehouse?->name,
            'condition'       => $a->condition,
            'warranty_until'  => optional($a->warranty_until)->toDateString(),
            'next_service_due' => optional($a->next_service_due)->toDateString(),
            'assigned_at'     => optional($assignedEvent?->performed_at)->toDateTimeString(),
            'assigned_by'     => $assignedEvent?->performer?->name,
            'returned_at'     => optional($returnEvent?->performed_at)->toDateTimeString(),
            'state'           => $state,
            'status'          => $a->status,
            'status_label'    => $handedBack ? 'Returned' : (self::STATUS_LABELS[$a->status] ?? $a->status),
            'inventory_url'   => "/app/inventory/assets?asset={$a->id}",
        ];
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
            'employee_id'  => $d['employee_id'] ?? $asset->assigned_employee_id,
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
