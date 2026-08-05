<?php

namespace App\Support\Task;

use App\Models\Task\Task;
use Illuminate\Support\Collection;

/**
 * Reads the tasks linked to a vendor through tasks.rel_type / rel_id.
 *
 * A shared READER, not shared vendor logic: TPV and Purchase stay independent
 * modules with their own tables, controllers and routes. What they have in common
 * is only the shape of the link — `rel_type = 'tpv_vendor' | 'purchase_vendor'`
 * plus an id — so duplicating this query in two controllers would be copy-paste,
 * not separation. Each caller passes its own rel_type and its own tenant.
 *
 * TWO routes reach a vendor and both count as "this vendor's work":
 *   1. the task is ABOUT the vendor      — rel_type/rel_id
 *   2. the task is ASSIGNED to its login — task_assignees.user_id
 * Only the first existed here at first, so a task assigned to a TPV's portal user
 * was missing from that vendor's Tasks tab even though the vendor could see it on
 * their own portal. Purchase Vendors usually have no login, so route 2 is simply
 * inert for them — which is why $portalUserId is optional rather than required.
 */
final class VendorTaskLink
{
    public const TPV      = 'tpv_vendor';
    public const PURCHASE = 'purchase_vendor';

    /** Statuses that mean the task is done — used only for the open/closed split. */
    private const CLOSED = ['complete', 'finished', 'cancelled'];

    /**
     * Tasks linked to one vendor, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function forVendor(string $relType, int $vendorId, int $tenantId, ?int $portalUserId = null, int $limit = 200): Collection
    {
        return self::baseQuery($relType, $vendorId, $tenantId, $portalUserId)
            ->with(['assignees.user:id,name'])
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'name', 'status', 'priority', 'start_date', 'due_date', 'date_finished', 'created_at', 'rel_type', 'rel_id'])
            ->map(fn (Task $t) => [
                'id'          => $t->id,
                // How this task reached the vendor, so the tab can say why it is listed.
                'link'        => $t->rel_type === $relType && (int) $t->rel_id === $vendorId ? 'related' : 'assigned',
                'name'        => $t->name,
                'status'      => $t->status,
                'priority'    => $t->priority,
                'start_date'  => $t->start_date,
                'due_date'    => $t->due_date,
                'finished_at' => $t->date_finished,
                'is_open'     => ! in_array(strtolower((string) $t->status), self::CLOSED, true),
                'assignees'   => $t->assignees->map(fn ($a) => $a->user?->name)->filter()->values(),
                'url'         => "/app/tasks/{$t->id}",
            ]);
    }

    /**
     * Tasks reaching this vendor by EITHER route. Tenant is applied explicitly —
     * Task carries no global scope.
     */
    private static function baseQuery(string $relType, int $vendorId, int $tenantId, ?int $portalUserId)
    {
        return Task::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($relType, $vendorId, $portalUserId) {
                $q->where(fn ($r) => $r->where('rel_type', $relType)->where('rel_id', $vendorId));
                if ($portalUserId) {
                    $q->orWhereHas('assignees', fn ($a) => $a->where('user_id', $portalUserId));
                }
            });
    }

    /** Counts for a vendor's Tasks tab header. */
    public static function summary(string $relType, int $vendorId, int $tenantId, ?int $portalUserId = null): array
    {
        $base = self::baseQuery($relType, $vendorId, $tenantId, $portalUserId);

        $total = (clone $base)->count();
        $open  = (clone $base)->whereNotIn('status', self::CLOSED)->count();

        return [
            'total'    => $total,
            'open'     => $open,
            'complete' => $total - $open,
            'overdue'  => (clone $base)
                ->whereNotIn('status', self::CLOSED)
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now()->toDateString())
                ->count(),
        ];
    }
}
