<?php

namespace App\Mail\Helpdesk;

use App\Models\Helpdesk\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies the internal agent that a ticket has landed on their queue, with a
 * deep link into the workspace. This is the "it shows on Shivam's dashboard"
 * signal, delivered by email as well as in-app (/my-tasks).
 */
class TicketAssignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Ticket $ticket, public string $agentName)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[Ticket #{$this->ticket->id}] assigned to you — {$this->ticket->subject}",
        );
    }

    public function content(): Content
    {
        $appUrl = rtrim((string) config('helpdesk.app_url'), '/');

        return new Content(
            view: 'emails.helpdesk.ticket-assigned',
            with: [
                'ticket'    => $this->ticket,
                'agentName' => $this->agentName,
                'ticketUrl' => "{$appUrl}/app/helpdesk/tickets/{$this->ticket->id}",
            ],
        );
    }
}
