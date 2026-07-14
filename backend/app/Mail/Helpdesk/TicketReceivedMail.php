<?php

namespace App\Mail\Helpdesk;

use App\Models\Helpdesk\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Acknowledgment sent to the requester the moment a ticket is created, carrying
 * the ticket number and the promise that replying to this email continues the
 * thread. Reply-To is the plus-addressed inbound address so a reply routes back.
 */
class TicketReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Ticket $ticket, public string $recipientName)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[Ticket #{$this->ticket->id}] {$this->ticket->subject} — we've received your request",
            replyTo: [new Address(threadedReplyTo($this->ticket), 'Support')],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.helpdesk.ticket-received',
            with: [
                'ticket'        => $this->ticket,
                'recipientName' => $this->recipientName,
            ],
        );
    }
}

/**
 * Build the plus-addressed Reply-To for a ticket: support+{id}-{token}@domain.
 * Shared by every threaded helpdesk mail; kept as a small free function so the
 * Mailables stay declarative.
 */
if (! function_exists('App\Mail\Helpdesk\threadedReplyTo')) {
    function threadedReplyTo(Ticket $ticket): string
    {
        $base = config('helpdesk.inbound_address');
        $ref  = $ticket->emailThreadRef();

        return str_contains($base, '@')
            ? preg_replace('/@/', "+{$ref}@", $base, 1)
            : $base;
    }
}
