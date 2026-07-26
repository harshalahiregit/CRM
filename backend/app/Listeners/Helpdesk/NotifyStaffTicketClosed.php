<?php

namespace App\Listeners\Helpdesk;

use App\Events\Helpdesk\TicketClosed;
use App\Services\Helpdesk\HelpdeskService;
use App\Services\NotificationService;

/**
 * In-app counterpart to the CSAT closure email.
 *
 * The customer is emailed a feedback request on closure, but nobody inside the
 * CRM was told the ticket was resolved. This notifies the assigned agent (their
 * work is done) and the ticket's managers (so the admin's activity feed reflects
 * resolutions, not just new tickets) — giving in-app notifications parity with
 * the closure email. Auto-discovered by Laravel via the typed handle().
 */
class NotifyStaffTicketClosed
{
    public function __construct(
        private NotificationService $notifications,
        private HelpdeskService $helpdesk,
    ) {
    }

    public function handle(TicketClosed $event): void
    {
        $ticket = $event->ticket;

        // Assignee + managers, de-duplicated. The actor (whoever closed it) is
        // suppressed inside notify(), so closing your own ticket doesn't ping you.
        $recipients = collect();
        if ($ticket->assigned_to) {
            $recipients->push((int) $ticket->assigned_to);
        }
        $recipients = $recipients->merge($this->helpdesk->ticketManagerIds($ticket))->unique();

        foreach ($recipients as $userId) {
            $this->notifications->notify(
                userId: $userId,
                tenantId: $ticket->tenant_id,
                type: 'ticket.resolved',
                title: "Ticket #{$ticket->id} resolved",
                message: $ticket->subject,
                link: "/app/helpdesk/tickets/{$ticket->id}",
                actorId: auth()->id(),
            );
        }
    }
}
