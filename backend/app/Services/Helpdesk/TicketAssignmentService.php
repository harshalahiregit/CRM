<?php

namespace App\Services\Helpdesk;

use App\Exceptions\BusinessException;
use App\Models\Helpdesk\Ticket;
use App\Models\User;
use App\Repositories\Helpdesk\TicketRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Owns ticket → user assignment and the assignee's task list.
 * An assigned ticket surfaces on that user's dashboard via myTasks().
 */
class TicketAssignmentService
{
    public function __construct(private TicketRepository $tickets)
    {
    }

    /**
     * Assign (or unassign, when $userId is null) a ticket to a user.
     * The user must belong to the same tenant.
     */
    public function assign(int $ticketId, ?int $userId, int $tenantId): Ticket
    {
        $ticket = $this->tickets->findForTenant($ticketId, $tenantId);

        if (! $ticket) {
            throw new BusinessException('Ticket not found.', 404);
        }

        if ($userId !== null) {
            $belongs = User::where('id', $userId)->where('tenant_id', $tenantId)->exists();
            if (! $belongs) {
                throw new BusinessException('Cannot assign to a user outside this workspace.', 422);
            }
        }

        $ticket->update(['assigned_to' => $userId]);

        Log::info('Helpdesk ticket assignment', [
            'ticket' => $ticket->id, 'assigned_to' => $userId, 'tenant' => $tenantId,
        ]);

        return $ticket->fresh('assignee');
    }

    /**
     * The open/in-progress tickets assigned to a user — shown as tasks on their
     * dashboard, ordered by priority then due date.
     */
    public function myTasks(int $userId, int $tenantId): Collection
    {
        return $this->tickets->assignedTo($userId, $tenantId);
    }
}
