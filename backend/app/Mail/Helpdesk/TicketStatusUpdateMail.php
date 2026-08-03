<?php

namespace App\Mail\Helpdesk;

use App\Models\Helpdesk\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the requester their ticket's status changed (e.g. In Progress → Closed).
 * Queued + best-effort like the other helpdesk mails; replying threads back to
 * the ticket via the plus-addressed Reply-To.
 */
class TicketStatusUpdateMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public string $recipientName,
        public string $oldStatus,
        public string $newStatus,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[Ticket #{$this->ticket->id}] Status updated: {$this->newStatus}",
            replyTo: [new Address($this->ticket->threadedReplyTo(), 'Support')],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.helpdesk.ticket-status-update',
            with: [
                'ticket'        => $this->ticket,
                'recipientName' => $this->recipientName,
                'oldStatus'     => $this->oldStatus,
                'newStatus'     => $this->newStatus,
            ],
        );
    }
}
