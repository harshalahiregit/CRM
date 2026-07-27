<?php

namespace App\Repositories\Purchase;

use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Repositories\BaseRepository;
use App\Support\Purchase\PurchaseKickoffStatus as Status;

class PurchaseKickoffRepository extends BaseRepository
{
    protected string $modelClass = PurchaseKickoffMeeting::class;

    /** Tenant-scoped, filtered listing for the registry. */
    public function filtered(int $tenantId, array $filters)
    {
        $query = PurchaseKickoffMeeting::forTenant($tenantId)
            ->with(['creator:id,name', 'vendor:id,company_name,purchase_vendor_code'])
            ->withCount([
                'participants',
                'participants as attended_count' => fn ($q) => $q->where('attended', true),
            ]);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['purchase_vendor_id'])) {
            $query->where('purchase_vendor_id', (int) $filters['purchase_vendor_id']);
        }
        if (! empty($filters['awaiting_ack'])) {
            $query->where('status', Status::COMPLETED)->whereNull('acknowledged_at');
        }
        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(fn ($q) => $q->where('title', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%"));
        }

        return $query->orderByDesc('scheduled_at')->get();
    }

    /** Tenant-guarded fetch with everything the detail screen needs. */
    public function findForTenant(int $id, int $tenantId): ?PurchaseKickoffMeeting
    {
        return PurchaseKickoffMeeting::forTenant($tenantId)
            ->with(['creator:id,name', 'vendor:id,company_name,purchase_vendor_code', 'participants.contact:id,first_name,last_name,designation', 'currentMom', 'auditLogs'])
            ->find($id);
    }

    public function stats(int $tenantId): array
    {
        $base = fn () => PurchaseKickoffMeeting::forTenant($tenantId);

        return [
            'total'        => $base()->count(),
            'scheduled'    => $base()->where('status', Status::SCHEDULED)->count(),
            'delayed'      => $base()->where('status', Status::DELAYED)->count(),
            'completed'    => $base()->where('status', Status::COMPLETED)->count(),
            'awaiting_ack' => $base()->where('status', Status::COMPLETED)->whereNull('acknowledged_at')->count(),
            'overdue'      => $base()->open()->whereNotNull('scheduled_at')
                                   ->where('scheduled_at', '<', now())->count(),
        ];
    }
}
