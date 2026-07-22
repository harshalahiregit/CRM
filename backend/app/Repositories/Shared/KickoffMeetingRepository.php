<?php

namespace App\Repositories\Shared;

use App\Models\Shared\KickoffMeeting;
use App\Repositories\BaseRepository;
use App\Support\Shared\KickoffStatus as Status;

class KickoffMeetingRepository extends BaseRepository
{
    protected string $modelClass = KickoffMeeting::class;

    /** Tenant-scoped, filtered listing for the registry. */
    public function filtered(int $tenantId, array $filters)
    {
        $query = KickoffMeeting::forTenant($tenantId)
            ->with(['creator:id,name', 'kickoffable'])
            ->withCount([
                'attendees',
                // Present count drives the list's "Attendance" column (present/total).
                'attendees as attended_count' => fn ($q) => $q->where('attended', true),
            ]);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['subject_type']) && ! empty($filters['subject_id'])) {
            $query->where('kickoffable_type', $filters['subject_type'])
                  ->where('kickoffable_id', (int) $filters['subject_id']);
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
    public function findForTenant(int $id, int $tenantId): ?KickoffMeeting
    {
        return KickoffMeeting::forTenant($tenantId)
            ->with(['creator:id,name', 'kickoffable', 'attendees.vendorContact:id,name,designation', 'auditLogs'])
            ->find($id);
    }

    public function stats(int $tenantId): array
    {
        $base = fn () => KickoffMeeting::forTenant($tenantId);

        return [
            'total'        => $base()->count(),
            'scheduled'    => $base()->where('status', Status::SCHEDULED)->count(),
            'delayed'      => $base()->where('status', Status::DELAYED)->count(),
            'completed'    => $base()->where('status', Status::COMPLETED)->count(),
            'awaiting_ack' => $base()->where('status', Status::COMPLETED)->whereNull('acknowledged_at')->count(),
            // Open meetings whose date is already in the past — the chase list.
            'overdue'      => $base()->open()->whereNotNull('scheduled_at')
                                   ->where('scheduled_at', '<', now())->count(),
        ];
    }
}
