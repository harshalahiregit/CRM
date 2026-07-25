<?php

namespace App\Mail\Helpdesk;

use App\Models\Helpdesk\Ticket;
use App\Models\Helpdesk\TicketReply;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A staff reply delivered to the customer as email. Replying to it continues the
 * ticket thread (Reply-To is plus-addressed to the inbound address). This is the
 * outbound half of the "works like email, back and forth" flow.
 */
class TicketReplyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public TicketReply $reply,
        public string $recipientName,
        public string $agentName,
    ) {
    }

    public function envelope(): Envelope
    {
        $cc = collect($this->reply->cc ?? [])->map(fn ($e) => new Address($e))->all();

        return new Envelope(
            subject: "[Ticket #{$this->ticket->id}] {$this->ticket->subject}",
            replyTo: [new Address($this->ticket->threadedReplyTo(), 'Support')],
            cc: $cc,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.helpdesk.ticket-reply',
            with: [
                'ticket'        => $this->ticket,
                'reply'         => $this->reply,
                'recipientName' => $this->recipientName,
                'agentName'     => $this->agentName,
            ],
        );
    }
}
