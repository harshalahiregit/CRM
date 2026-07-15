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
    public function __construct(
        private TicketRepository $tickets,
        private HelpdeskMailService $mail,
        private \App\Services\NotificationService $notifications,
    ) {
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

        $previous = $ticket->assigned_to;
        $ticket->update(['assigned_to' => $userId]);

        Log::info('Helpdesk ticket assignment', [
            'ticket' => $ticket->id, 'assigned_to' => $userId, 'tenant' => $tenantId,
        ]);

        // Notify the newly-assigned agent (skip re-assign to the same user).
        if ($userId !== null && $userId !== $previous) {
            $fresh = $ticket->fresh();
            $this->mail->sendAssignment($fresh, $userId);
            $this->notifications->notify(
                userId: $userId,
                tenantId: $tenantId,
                type: 'ticket.assigned',
                title: "Ticket #{$fresh->id} assigned to you",
                message: $fresh->subject,
                link: "/app/helpdesk/tickets/{$fresh->id}",
                actorId: auth()->id(),
            );
        }

        return $ticket->fresh('assignee');
    }

    /**
     * Integration 3e — the unified assignee dashboard. Pulls BOTH the tickets
     * assigned to the user (tickets.assigned_to) AND the tasks assigned to them
     * (task_assignees), normalized and tagged by `source` (ticket | task).
     * The Task side is guarded so this still works before the Task module ships.
     */
    public function myTasks(int $userId, int $tenantId): array
    {
        $items = $this->tickets->assignedTo($userId, $tenantId)->map(fn ($t) => [
            'source'    => 'ticket',
            'id'        => $t->id,
            'title'     => $t->subject,
            'priority'  => $t->priority,
            'status'    => $t->status,
            'due_date'  => $t->due_date,
            'link'      => "/app/helpdesk/tickets/{$t->id}",
        ])->all();

        if (\Illuminate\Support\Facades\Schema::hasTable('tasks')) {
            $tasks = \App\Models\Task\Task::forTenant($tenantId)
                ->whereIn('status', ['not_started', 'in_progress', 'awaiting_feedback', 'testing'])
                ->whereHas('assignees', fn ($q) => $q->where('user_id', $userId))
                ->get();

            foreach ($tasks as $t) {
                $items[] = [
                    'source'   => 'task',
                    'id'       => $t->id,
                    'title'    => $t->name,
                    'priority' => $t->priority,
                    'status'   => $t->status,
                    'due_date' => $t->due_date,
                    'link'     => "/app/tasks/{$t->id}",
                ];
            }
        }

        // Urgent-first, then by soonest due date.
        $rank = ['urgent' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($items, fn ($a, $b) => ($rank[$a['priority']] ?? 9) <=> ($rank[$b['priority']] ?? 9)
            ?: strcmp((string) $a['due_date'], (string) $b['due_date']));

        return $items;
    }
}
