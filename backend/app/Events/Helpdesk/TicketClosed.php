<?php

namespace App\Events\Helpdesk;

use App\Models\Helpdesk\Ticket;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once when a ticket transitions into the "closed" status.
 * Listened to by SendTicketClosedFeedbackEmail.
 */
class TicketClosed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Ticket $ticket)
    {
    }
}
