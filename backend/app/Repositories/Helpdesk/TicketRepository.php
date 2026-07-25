<?php

namespace App\Repositories\Helpdesk;

use App\Models\Helpdesk\Ticket;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class TicketRepository extends BaseRepository
{
    protected string $modelClass = Ticket::class;

    /** Filtered, tenant-scoped ticket list for the grid. */
    public function filtered(int $tenantId, array $filters, ?array $visibility = null): Collection
    {
        $query = Ticket::forTenant($tenantId)->with('assignee:id,name,email');

        // Access barrier: a non-manager agent only sees tickets assigned to them,
        // unassigned tickets (claimable), or tickets in a department they manage.
        // HelpdeskService passes null for admins / global managers → no scoping.
        if ($visibility) {
            $uid = (int) $visibility['user_id'];
            $depts = $visibility['dept_ids'] ?? [];
            $email = $visibility['email'] ?? null;
            $query->where(function ($q) use ($uid, $depts, $email) {
                // Assigned to me, unassigned (claimable), a dept I manage, OR one I
                // raised — created it or my email is the requester. Mirrors
                // HelpdeskService::canSeeTicket so the list and the detail agree.
                $q->where('assigned_to', $uid)
                    ->orWhereNull('assigned_to')
                    ->orWhere('created_by', $uid);
                if ($email) {
                    $q->orWhereRaw('LOWER(requester_email) = ?', [mb_strtolower(trim($email))]);
                }
                if (! empty($depts)) {
                    $q->orWhereIn('department_id', $depts);
                }
            });
        }

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
