<?php

namespace App\Repositories\Helpdesk;

use App\Models\Helpdesk\Ticket;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class TicketRepository extends BaseRepository
{
    protected string $modelClass = Ticket::class;

    /** Filtered, tenant-scoped ticket list for the grid. */
    public function filtered(int $tenantId, array $filters): Collection
    {
        $query = Ticket::forTenant($tenantId)->with('assignee:id,name,email');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        if (! empty($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if (! empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }
        if (! empty($filters['search'])) {
            $s = '%'.$filters['search'].'%';
            $query->where(function ($sub) use ($s) {
                $sub->where('subject', 'like', $s)->orWhere('description', 'like', $s);
            });
        }

        return $query->latest()->get();
    }

    /** Open/in-progress tickets assigned to a user — their "task list". */
    public function assignedTo(int $userId, int $tenantId): Collection
    {
        return Ticket::forTenant($tenantId)
            ->where('assigned_to', $userId)
            ->whereIn('status', ['open', 'in-progress'])
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderBy('due_date')
            ->get();
    }

    /** Find a ticket within a tenant, or null. */
    public function findForTenant(int $id, int $tenantId): ?Ticket
    {
        return Ticket::forTenant($tenantId)->find($id);
    }
}
